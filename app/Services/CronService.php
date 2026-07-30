<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use Lib\Crypto;
use Lib\Fcm;
use Lib\Logger;
use Lib\Settings;
use Lib\SmsGateway;

/**
 * The scheduled jobs invoked by cron/run.php.
 *
 * Every method returns ['processed' => int, 'message' => string] and never
 * throws for a recoverable problem - the runner records the outcome so an
 * administrator can see from the dashboard whether cron is healthy.
 */
final class CronService
{
    // ------------------------------------------------------------------
    // 1. Follow-up reminders
    // ------------------------------------------------------------------

    /** @return array{processed:int,message:string} */
    public function sendReminders(): array
    {
        $today = date('Y-m-d');

        $due = Database::all(
            'SELECT f.id, f.loan_id, f.due_date, f.promise_amount, f.assigned_to, f.bc_id,
                    l.account_number, c.full_name, c.mobile_enc,
                    u.id AS agent_id, u.full_name AS agent_name, u.mobile_enc AS agent_mobile_enc
             FROM follow_ups f
             JOIN loans l ON l.id = f.loan_id
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN bc_agents bc ON bc.id = f.bc_id
             LEFT JOIN users u ON u.id = COALESCE(f.assigned_to, bc.user_id)
             WHERE f.status = "pending"
               AND f.due_date <= ?
               AND (f.reminder_sent_at IS NULL OR DATE(f.reminder_sent_at) < ?)
             ORDER BY f.due_date ASC
             LIMIT 200',
            [$today, $today]
        );

        if ($due === []) {
            return ['processed' => 0, 'message' => 'no follow-ups due'];
        }

        $smsReady = SmsGateway::isConfigured();
        $pushReady = Fcm::isConfigured();

        if (!$smsReady && !$pushReady) {
            // Nothing to send with; say so loudly rather than pretending success.
            return [
                'processed' => 0,
                'message' => count($due) . ' follow-up(s) due but neither SMS nor push is configured '
                    . '- configure them in Settings',
            ];
        }

        $sms = new SmsGateway();
        $fcm = new Fcm();
        $sent = 0;
        $failed = 0;

        foreach ($due as $followUp) {
            $delivered = false;

            $title = 'Follow-up due today';
            $body = sprintf(
                'A/c %s - %s. Promised Rs.%s by %s.',
                $followUp['account_number'],
                $followUp['full_name'],
                number_format((float) $followUp['promise_amount'], 2),
                date('d-m-Y', strtotime((string) $followUp['due_date']))
            );

            // 1. push to the agent's device (free, and does not cost an SMS)
            if ($pushReady && $followUp['agent_id'] !== null) {
                $result = $fcm->sendToUser((int) $followUp['agent_id'], $title, $body, [
                    'type'    => 'followup',
                    'loan_id' => (string) $followUp['loan_id'],
                ]);
                $delivered = $result['sent'] > 0;
            }

            // 2. fall back to SMS to the agent
            if (!$delivered && $smsReady) {
                $agentMobile = Crypto::decrypt($followUp['agent_mobile_enc'] ?? null);
                if ($agentMobile !== null && $agentMobile !== '') {
                    $result = $sms->send($agentMobile, $title . ': ' . $body, 'followup');
                    $delivered = $result['sent'];
                }
            }

            if ($delivered) {
                Database::update('follow_ups', ['reminder_sent_at' => date('Y-m-d H:i:s')], ['id' => (int) $followUp['id']]);
                $sent++;
            } else {
                $failed++;
            }
        }

        Audit::log('cron.reminders', 'follow_ups', null,
            $sent . ' reminder(s) sent, ' . $failed . ' failed', null, null,
            $failed > 0 ? 'warning' : 'info', 'cron');

        return [
            'processed' => $sent,
            'message' => $sent . ' sent, ' . $failed . ' failed, out of ' . count($due) . ' due',
        ];
    }

    // ------------------------------------------------------------------
    // 2. Recovery probability scoring (phase-2 "AI", rule based)
    // ------------------------------------------------------------------

    /**
     * A transparent, explainable score instead of a black-box model: each rule
     * contributes points, the total is clamped to 0-100, and the contributions
     * are stored so the panel can show WHY an account scored what it did.
     *
     * @return array{processed:int,message:string}
     */
    public function recomputeRiskScores(): array
    {
        $loans = Database::all(
            'SELECT l.id, l.dpd, l.asset_class, l.outstanding_amount, l.overdue_amount,
                    l.total_recovered, l.sanction_amount, l.visit_count, l.last_visit_at,
                    l.last_paid_date, l.recovery_status,
                    (SELECT COUNT(*) FROM visits v WHERE v.loan_id = l.id AND v.visit_status = "promise") AS promises,
                    (SELECT COUNT(*) FROM visits v WHERE v.loan_id = l.id AND v.visit_status = "not_available") AS not_available,
                    (SELECT COUNT(*) FROM recoveries r WHERE r.loan_id = l.id AND r.status <> "rejected") AS payments,
                    (SELECT MAX(v.recovery_possibility) FROM visits v WHERE v.loan_id = l.id) AS possibility
             FROM loans l
             WHERE l.status = "active" AND l.recovery_status NOT IN ("closed","write_off")
             LIMIT 20000'
        );

        if ($loans === []) {
            return ['processed' => 0, 'message' => 'no open accounts to score'];
        }

        $processed = 0;

        foreach ($loans as $loan) {
            $factors = [];
            $score = 50; // neutral starting point

            // --- days past due -------------------------------------------
            $dpd = (int) $loan['dpd'];
            if ($dpd <= 30) {
                $factors['dpd_low'] = +18;
            } elseif ($dpd <= 90) {
                $factors['dpd_moderate'] = +6;
            } elseif ($dpd <= 365) {
                $factors['dpd_high'] = -12;
            } else {
                $factors['dpd_very_high'] = -22;
            }

            // --- asset classification -------------------------------------
            $factors['asset_' . strtolower((string) $loan['asset_class'])] = match ((string) $loan['asset_class']) {
                'STD' => +14,
                'SMA0' => +8,
                'SMA1' => +4,
                'SMA2' => 0,
                'SS' => -8,
                'DF1', 'DF2' => -14,
                'DF3' => -18,
                'LOSS' => -25,
                default => 0,
            };

            // --- payment behaviour ----------------------------------------
            $payments = (int) $loan['payments'];
            if ($payments > 0) {
                $factors['has_paid_before'] = +10 + min(8, $payments * 2);
            } else {
                $factors['never_paid'] = -8;
            }

            if ($loan['last_paid_date'] !== null) {
                $daysSincePayment = (int) floor((time() - (int) strtotime((string) $loan['last_paid_date'])) / 86400);
                if ($daysSincePayment <= 60) {
                    $factors['recent_payment'] = +12;
                } elseif ($daysSincePayment > 365) {
                    $factors['stale_payment'] = -10;
                }
            }

            // --- engagement -----------------------------------------------
            $notAvailable = (int) $loan['not_available'];
            $visitCount = (int) $loan['visit_count'];
            if ($visitCount === 0) {
                $factors['never_visited'] = -4;
            } elseif ($notAvailable >= 3 && $notAvailable >= $visitCount / 2) {
                $factors['often_unavailable'] = -14;
            } else {
                $factors['engaged'] = +6;
            }

            if ((int) $loan['promises'] > 0) {
                $factors['made_promise'] = +8;
            }

            // --- field officer's own read ----------------------------------
            $factors['field_view_' . (string) ($loan['possibility'] ?? 'none')] = match ((string) ($loan['possibility'] ?? '')) {
                'high' => +16,
                'medium' => +6,
                'low' => -8,
                'nil' => -18,
                default => 0,
            };

            // --- exposure --------------------------------------------------
            $outstanding = (float) $loan['outstanding_amount'];
            $sanction = (float) $loan['sanction_amount'];
            if ($sanction > 0) {
                $repaidRatio = max(0.0, min(1.0, (float) $loan['total_recovered'] / $sanction));
                $factors['repaid_ratio'] = (int) round($repaidRatio * 12);
            }
            if ($outstanding > 500000) {
                $factors['large_exposure'] = -6;
            }

            foreach ($factors as $delta) {
                $score += (int) $delta;
            }
            $score = max(0, min(100, $score));

            // Score = probability of recovery; the RISK band is its inverse.
            if ($score >= 70) {
                $band = 'low';
                $suggestion = 'Good recovery prospect - schedule a visit and push for full payment.';
            } elseif ($score >= 50) {
                $band = 'medium';
                $suggestion = 'Follow up within 7 days and secure a written promise with a date.';
            } elseif ($score >= 30) {
                $band = 'high';
                $suggestion = 'Consider a one-time settlement (OTS) offer and involve the Branch Manager.';
            } else {
                $band = 'critical';
                $suggestion = 'Escalate: verify the borrower still resides there, then consider legal action.';
            }

            try {
                Database::upsert('risk_scores', [
                    'loan_id'      => (int) $loan['id'],
                    'score'        => $score,
                    'band'         => $band,
                    'factors_json' => json_encode($factors),
                    'suggestion'   => $suggestion,
                    'computed_at'  => date('Y-m-d H:i:s'),
                ], ['score', 'band', 'factors_json', 'suggestion', 'computed_at']);

                Database::update('loans', [
                    'risk_score' => $score,
                    'risk_band'  => $band,
                ], ['id' => (int) $loan['id']]);

                $processed++;
            } catch (\Throwable $e) {
                Logger::warning('Risk score write failed for loan ' . $loan['id'] . ': ' . $e->getMessage());
            }
        }

        Audit::log('cron.risk_scoring', 'loans', null,
            $processed . ' account(s) scored', null, null, 'info', 'cron');

        return ['processed' => $processed, 'message' => $processed . ' account(s) scored'];
    }

    // ------------------------------------------------------------------
    // 3. Housekeeping
    // ------------------------------------------------------------------

    /** @return array{processed:int,message:string} */
    public function cleanup(): array
    {
        $parts = [];
        $total = 0;

        $otp = (new OtpService())->purgeExpired(2);
        $total += $otp;
        $parts[] = $otp . ' OTP row(s)';

        $gpsDays = max(7, Settings::getInt('security.gps_retention_days', 90));
        $pings = Database::run(
            'DELETE FROM gps_pings WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$gpsDays]
        )->rowCount();
        $total += $pings;
        $parts[] = $pings . ' GPS ping(s) older than ' . $gpsDays . 'd';

        $auditDays = max(30, Settings::getInt('security.audit_retention_days', 365));
        $audit = Database::run(
            'DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND severity = "info"',
            [$auditDays]
        )->rowCount();
        $total += $audit;
        $parts[] = $audit . ' audit row(s) older than ' . $auditDays . 'd';

        $tokens = Database::run(
            'DELETE FROM api_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        )->rowCount();
        $total += $tokens;
        $parts[] = $tokens . ' expired token(s)';

        $attempts = Database::run(
            'DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        )->rowCount();
        $total += $attempts;
        $parts[] = $attempts . ' login attempt(s)';

        $messages = Database::run(
            'DELETE FROM message_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
        )->rowCount();
        $total += $messages;
        $parts[] = $messages . ' message log(s)';

        // Expire invitation codes whose date has passed.
        $invites = Database::run(
            'UPDATE invitation_codes SET status = "expired"
             WHERE status = "active" AND expires_at IS NOT NULL AND expires_at < NOW()'
        )->rowCount();
        $parts[] = $invites . ' invitation(s) expired';

        // Mark missed follow-ups so the pipeline report stays honest.
        $missed = Database::run(
            'UPDATE follow_ups SET status = "missed"
             WHERE status = "pending" AND due_date < DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)'
        )->rowCount();
        $parts[] = $missed . ' follow-up(s) marked missed';

        // Old temp files from aborted uploads.
        $files = $this->pruneDirectory(UPLOAD_PATH . '/temp', 2);
        $parts[] = $files . ' temp file(s)';

        return ['processed' => $total, 'message' => implode(', ', $parts)];
    }

    /** @return array{processed:int,message:string} */
    public function closeOpenAttendance(): array
    {
        // An agent who forgot to check out should not show as on duty forever.
        $rows = Database::all(
            'SELECT id, check_in_at FROM attendance
             WHERE check_out_at IS NULL AND attendance_date < CURRENT_DATE
             LIMIT 500'
        );

        $closed = 0;
        foreach ($rows as $row) {
            $checkIn = (int) strtotime((string) $row['check_in_at']);
            // Assume an 8-hour day rather than inventing a check-out time.
            $minutes = 480;

            Database::update('attendance', [
                'check_out_at'   => date('Y-m-d H:i:s', $checkIn + $minutes * 60),
                'worked_minutes' => $minutes,
                'status'         => 'half_day',
                'remarks'        => 'Auto-closed by the system: no check-out was recorded.',
            ], ['id' => (int) $row['id']]);

            $closed++;
        }

        return [
            'processed' => $closed,
            'message' => $closed === 0 ? 'nothing to close' : $closed . ' attendance row(s) auto-closed',
        ];
    }

    // ------------------------------------------------------------------
    // 4. Backup (cPanel friendly: plain SQL file on disk)
    // ------------------------------------------------------------------

    /**
     * Writes a self-contained .sql file into storage/backups. This is a pure
     * PHP dump because `mysqldump` and `exec()` are frequently unavailable on
     * shared hosting. Download it from cPanel File Manager, or add it to your
     * cPanel backup schedule.
     *
     * @return array{processed:int,message:string}
     */
    public function backup(): array
    {
        $directory = STORAGE_PATH . '/backups';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return ['processed' => 0, 'message' => 'backup folder is not writable: ' . $directory];
        }

        // Keep only the newest 7 dumps so the hosting quota is not eaten.
        $existing = glob($directory . '/lrms-*.sql') ?: [];
        if (count($existing) >= 7) {
            usort($existing, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
            foreach (array_slice($existing, 0, count($existing) - 6) as $old) {
                @unlink($old);
            }
        }

        $file = $directory . '/lrms-' . date('Y-m-d-His') . '.sql';
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            return ['processed' => 0, 'message' => 'could not open the backup file for writing'];
        }

        $tables = array_column(
            Database::all('SELECT table_name AS t FROM information_schema.tables
                           WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"'),
            't'
        );

        fwrite($handle, "-- LRMS backup " . date('c') . "\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $rowCount = 0;
        $pdo = Database::pdo();

        foreach ($tables as $table) {
            $create = Database::first('SHOW CREATE TABLE `' . str_replace('`', '', (string) $table) . '`');
            $createSql = $create === null ? null : ($create['Create Table'] ?? array_values($create)[1] ?? null);

            fwrite($handle, "\n-- ---------- " . $table . " ----------\n");
            fwrite($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
            if (is_string($createSql)) {
                fwrite($handle, $createSql . ";\n");
            }

            // Stream the rows so memory stays flat on a large visits table.
            $statement = $pdo->query('SELECT * FROM `' . $table . '`');
            if ($statement === false) {
                continue;
            }

            $buffer = [];
            while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $values = array_map(
                    static function ($value) use ($pdo): string {
                        if ($value === null) {
                            return 'NULL';
                        }
                        if (is_int($value) || is_float($value)) {
                            return (string) $value;
                        }
                        return $pdo->quote((string) $value);
                    },
                    $row
                );

                $buffer[] = '(' . implode(',', $values) . ')';
                $rowCount++;

                if (count($buffer) >= 200) {
                    fwrite($handle, 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $buffer) . ";\n");
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                fwrite($handle, 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $buffer) . ";\n");
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);

        $bytes = (int) filesize($file);

        Audit::log('cron.backup', 'database', null,
            'Backup written: ' . basename($file) . ' (' . round($bytes / 1048576, 2) . ' MB, '
            . $rowCount . ' rows)', null, null, 'notice', 'cron');

        return [
            'processed' => $rowCount,
            'message' => basename($file) . ' - ' . count($tables) . ' tables, '
                . number_format($rowCount) . ' rows, ' . round($bytes / 1048576, 2) . ' MB',
        ];
    }

    private function pruneDirectory(string $directory, int $olderThanDays): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $cutoff = time() - $olderThanDays * 86400;
        $removed = 0;

        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $cutoff && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
