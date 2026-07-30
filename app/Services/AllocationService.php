<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use Lib\Crypto;
use Lib\Logger;
use Lib\SpreadsheetReader;
use Lib\SpreadsheetWriter;

/**
 * Excel/CSV import of recovery accounts, plus the auto-allocation engine.
 *
 * Import is UPSERT based on the natural keys (customers.cif_number and
 * loans.account_number) so the same monthly file can be re-uploaded to refresh
 * balances without creating duplicates.
 *
 * Allocation strategies:
 *   bc_code       - use the BC CODE column in the sheet (authoritative)
 *   equal_branch  - spread each branch's unallocated accounts evenly over the
 *                   active BC agents of that branch (round-robin by workload)
 *   none          - import only, allocate later from the UI
 *
 * Rows are processed one at a time and committed in chunks, so a 50 000-row
 * file does not blow the memory or transaction limits of a shared host.
 */
final class AllocationService
{
    private const CHUNK = 200;

    /**
     * Column aliases. The header row is normalised (lowercase, underscores)
     * before matching, so "BC CODE", "Bc-Code" and "bc code" all work.
     *
     * @var array<string,list<string>>
     */
    private const COLUMNS = [
        'account_number'     => ['account_number', 'account_no', 'acno', 'loan_account_number', 'loan_ac_no', 'account'],
        'cif_number'         => ['cif_number', 'cif', 'cif_no', 'customer_id', 'cust_id'],
        'customer_name'      => ['customer_name', 'name', 'borrower_name', 'borrower', 'full_name'],
        'guardian_name'      => ['guardian_name', 'father_name', 'father_husband_name', 'father_s_name', 'husband_name'],
        'mobile'             => ['mobile', 'mobile_no', 'phone', 'phone_no', 'contact', 'contact_no', 'mobile_number'],
        'alt_mobile'         => ['alt_mobile', 'alternate_mobile', 'mobile_2', 'other_mobile'],
        'aadhaar'            => ['aadhaar', 'aadhar', 'aadhaar_no', 'uid'],
        'address'            => ['address', 'address_line', 'full_address', 'residence'],
        'village'            => ['village', 'village_name', 'place'],
        'panchayat'          => ['panchayat', 'gram_panchayat'],
        'block'              => ['block', 'block_name', 'taluka', 'tehsil'],
        'district'           => ['district', 'district_name'],
        'state'              => ['state'],
        'pincode'            => ['pincode', 'pin', 'pin_code', 'postal_code'],
        'occupation'         => ['occupation', 'business', 'activity'],
        'latitude'           => ['latitude', 'lat'],
        'longitude'          => ['longitude', 'lng', 'long'],
        'branch_code'        => ['branch_code', 'branch', 'solid', 'sol_id', 'branch_id', 'sol'],
        'branch_name'        => ['branch_name'],
        'bc_code'            => ['bc_code', 'bc', 'agent_code', 'bc_id', 'bc_agent_code'],
        'product_name'       => ['product_name', 'product', 'scheme', 'scheme_name', 'loan_type'],
        'scheme_code'        => ['scheme_code'],
        'sanction_amount'    => ['sanction_amount', 'sanctioned_amount', 'sanction_amt', 'limit'],
        'disbursed_amount'   => ['disbursed_amount', 'disbursement_amount', 'disb_amt'],
        'outstanding_amount' => ['outstanding_amount', 'outstanding', 'balance', 'os_amount', 'os_balance', 'principal_outstanding'],
        'overdue_amount'     => ['overdue_amount', 'overdue', 'od_amount', 'total_overdue', 'arrear', 'arrears'],
        'principal_overdue'  => ['principal_overdue', 'principal_od'],
        'interest_overdue'   => ['interest_overdue', 'interest_od', 'unrealised_interest'],
        'emi_amount'         => ['emi_amount', 'emi', 'instalment', 'installment'],
        'disbursement_date'  => ['disbursement_date', 'disb_date', 'sanction_date'],
        'maturity_date'      => ['maturity_date', 'due_date_final', 'maturity'],
        'npa_date'           => ['npa_date', 'npa_dt', 'date_of_npa'],
        'asset_class'        => ['asset_class', 'asset_classification', 'classification', 'npa_class', 'iri'],
        'dpd'                => ['dpd', 'days_past_due', 'overdue_days'],
        'last_paid_date'     => ['last_paid_date', 'last_payment_date', 'last_credit_date'],
        'last_paid_amount'   => ['last_paid_amount', 'last_payment_amount'],
    ];

    /**
     * Run an import.
     *
     * @return array{
     *   ok:bool, message:string, batch_id:int,
     *   total:int, inserted:int, updated:int, skipped:int, failed:int, allocated:int,
     *   errors:list<string>, error_report:?string
     * }
     */
    public function import(string $filePath, string $originalName, string $strategy, int $userId): array
    {
        $batchUid = Crypto::uuid4();
        $storedRelative = 'excel/' . date('Y/m') . '/' . $batchUid . '_' . $this->safeName($originalName);
        $this->moveToUploads($filePath, $storedRelative);

        $batchId = Database::insert('allocation_batches', [
            'batch_uid'   => $batchUid,
            'file_name'   => substr($originalName, 0, 255),
            'stored_path' => $storedRelative,
            'strategy'    => in_array($strategy, ['bc_code', 'equal_branch', 'manual', 'none'], true) ? $strategy : 'bc_code',
            'status'      => 'processing',
            'uploaded_by' => $userId,
        ]);

        $counters = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'allocated' => 0];
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<array{row:int,account:string,reason:string}> $rejected */
        $rejected = [];

        try {
            $reader = new SpreadsheetReader(UPLOAD_PATH . '/' . $storedRelative);

            $branchCache = [];
            $bcCache = [];
            $pending = 0;
            Database::begin();

            foreach ($reader->assocRows(0) as $row) {
                $counters['total']++;
                $rowNumber = (int) ($row['__row'] ?? $counters['total']);

                try {
                    $result = $this->importRow($row, $batchId, $strategy, $branchCache, $bcCache);

                    if ($result['status'] === 'inserted') {
                        $counters['inserted']++;
                    } elseif ($result['status'] === 'updated') {
                        $counters['updated']++;
                    } else {
                        $counters['skipped']++;
                        $rejected[] = ['row' => $rowNumber, 'account' => $result['account'], 'reason' => $result['reason']];
                        if (count($errors) < 25) {
                            $errors[] = 'Row ' . $rowNumber . ': ' . $result['reason'];
                        }
                    }

                    if ($result['allocated']) {
                        $counters['allocated']++;
                    }
                } catch (\Throwable $e) {
                    $counters['failed']++;
                    $reason = $e->getMessage();
                    $rejected[] = ['row' => $rowNumber, 'account' => (string) ($row['account_number'] ?? ''), 'reason' => $reason];
                    if (count($errors) < 25) {
                        $errors[] = 'Row ' . $rowNumber . ': ' . $reason;
                    }
                    Logger::warning('Import row failed', ['row' => $rowNumber, 'error' => $reason]);
                }

                // Commit in chunks so a huge file cannot exceed limits.
                if (++$pending >= self::CHUNK) {
                    Database::commit();
                    Database::begin();
                    $pending = 0;
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            Logger::error('Import aborted: ' . $e->getMessage());

            Database::update('allocation_batches', [
                'status'      => 'failed',
                'message'     => substr($e->getMessage(), 0, 60000),
                'total_rows'  => $counters['total'],
                'finished_at' => date('Y-m-d H:i:s'),
            ], ['id' => $batchId]);

            return array_merge($counters, [
                'ok' => false,
                'message' => 'The import stopped: ' . $e->getMessage()
                    . ' Rows committed before the failure have been kept.',
                'batch_id' => $batchId,
                'errors' => $errors,
                'error_report' => null,
            ]);
        }

        // Equal distribution runs after the import, over everything unallocated.
        if ($strategy === 'equal_branch') {
            $counters['allocated'] += $this->distributeEqually();
        }

        $errorReport = $rejected === [] ? null : $this->writeErrorReport($batchUid, $rejected);

        $status = $counters['failed'] > 0 || $counters['skipped'] > 0 ? 'partial' : 'completed';

        Database::update('allocation_batches', [
            'total_rows'     => $counters['total'],
            'inserted_rows'  => $counters['inserted'],
            'updated_rows'   => $counters['updated'],
            'skipped_rows'   => $counters['skipped'],
            'failed_rows'    => $counters['failed'],
            'allocated_rows' => $counters['allocated'],
            'error_report'   => $errorReport,
            'status'         => $status,
            'message'        => $errors === [] ? null : substr(implode("\n", $errors), 0, 60000),
            'finished_at'    => date('Y-m-d H:i:s'),
        ], ['id' => $batchId]);

        Audit::log('upload.import', 'allocation_batch', $batchId, sprintf(
            '%s: %d rows, %d new, %d updated, %d skipped, %d failed, %d allocated',
            $originalName,
            $counters['total'],
            $counters['inserted'],
            $counters['updated'],
            $counters['skipped'],
            $counters['failed'],
            $counters['allocated']
        ), null, null, $counters['failed'] > 0 ? 'warning' : 'notice');

        return array_merge($counters, [
            'ok' => true,
            'message' => sprintf(
                '%d rows read: %d new, %d updated, %d skipped, %d failed. %d account(s) allocated.',
                $counters['total'],
                $counters['inserted'],
                $counters['updated'],
                $counters['skipped'],
                $counters['failed'],
                $counters['allocated']
            ),
            'batch_id' => $batchId,
            'errors' => $errors,
            'error_report' => $errorReport,
        ]);
    }

    /**
     * @param array<string,string> $row
     * @param array<string,int|null> $branchCache
     * @param array<string,int|null> $bcCache
     * @return array{status:'inserted'|'updated'|'skipped',reason:string,account:string,allocated:bool}
     */
    private function importRow(array $row, int $batchId, string $strategy, array &$branchCache, array &$bcCache): array
    {
        $get = static function (array $row, string $field): string {
            foreach (self::COLUMNS[$field] ?? [] as $alias) {
                if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                    return trim((string) $row[$alias]);
                }
            }
            return '';
        };

        $accountNumber = $get($row, 'account_number');
        if ($accountNumber === '') {
            return ['status' => 'skipped', 'reason' => 'Account number is empty.', 'account' => '', 'allocated' => false];
        }

        $customerName = $get($row, 'customer_name');
        if ($customerName === '') {
            return ['status' => 'skipped', 'reason' => 'Customer name is empty.', 'account' => $accountNumber, 'allocated' => false];
        }

        // ---- branch -----------------------------------------------------
        $branchCode = $get($row, 'branch_code');
        $branchId = null;
        if ($branchCode !== '') {
            if (!array_key_exists($branchCode, $branchCache)) {
                $branch = Database::first('SELECT id FROM branches WHERE code = ? LIMIT 1', [$branchCode]);
                if ($branch === null) {
                    // Auto-create the branch rather than rejecting the row: the
                    // operator can fill in the address later.
                    $branchCache[$branchCode] = Database::insert('branches', [
                        'code'   => substr($branchCode, 0, 30),
                        'name'   => substr($get($row, 'branch_name') !== '' ? $get($row, 'branch_name') : $branchCode, 0, 150),
                        'district' => $get($row, 'district') !== '' ? substr($get($row, 'district'), 0, 80) : null,
                        'status' => 'active',
                    ]);
                } else {
                    $branchCache[$branchCode] = (int) $branch['id'];
                }
            }
            $branchId = $branchCache[$branchCode];
        }

        // ---- customer (upsert on CIF) -----------------------------------
        $cif = $get($row, 'cif_number');
        if ($cif === '') {
            // Derive a stable synthetic CIF so re-uploads still match.
            $cif = 'AC-' . substr(hash('sha256', $accountNumber), 0, 18);
        }

        $mobile = $get($row, 'mobile');
        $altMobile = $get($row, 'alt_mobile');
        $aadhaar = $get($row, 'aadhaar');

        $customerData = [
            'cif_number'      => substr($cif, 0, 50),
            'branch_id'       => $branchId,
            'full_name'       => substr($customerName, 0, 150),
            'guardian_name'   => $this->nullTrim($get($row, 'guardian_name'), 150),
            'mobile_enc'      => Crypto::encrypt(Crypto::normalise($mobile, 'mobile')),
            'mobile_hash'     => Crypto::blindIndex($mobile, 'mobile'),
            'mobile_last4'    => Crypto::last4($mobile),
            'alt_mobile_enc'  => Crypto::encrypt(Crypto::normalise($altMobile, 'mobile')),
            'alt_mobile_hash' => Crypto::blindIndex($altMobile, 'mobile'),
            'aadhaar_enc'     => Crypto::encrypt(Crypto::normalise($aadhaar, 'aadhaar')),
            'aadhaar_last4'   => Crypto::last4($aadhaar),
            'address_line'    => $this->nullTrim($get($row, 'address'), 255),
            'village'         => $this->nullTrim($get($row, 'village'), 120),
            'panchayat'       => $this->nullTrim($get($row, 'panchayat'), 120),
            'block'           => $this->nullTrim($get($row, 'block'), 120),
            'district'        => $this->nullTrim($get($row, 'district'), 80),
            'state'           => $this->nullTrim($get($row, 'state'), 80),
            'pincode'         => $this->nullTrim($get($row, 'pincode'), 10),
            'occupation'      => $this->nullTrim($get($row, 'occupation'), 120),
            'latitude'        => $this->coordinate($get($row, 'latitude'), 90),
            'longitude'       => $this->coordinate($get($row, 'longitude'), 180),
        ];

        Database::upsert('customers', $customerData, [
            'branch_id', 'full_name', 'guardian_name', 'mobile_enc', 'mobile_hash', 'mobile_last4',
            'alt_mobile_enc', 'alt_mobile_hash', 'aadhaar_enc', 'aadhaar_last4', 'address_line',
            'village', 'panchayat', 'block', 'district', 'state', 'pincode', 'occupation',
            'latitude', 'longitude',
        ]);

        $customerId = (int) Database::value(
            'SELECT id FROM customers WHERE cif_number = ? LIMIT 1',
            [$customerData['cif_number']],
            0
        );
        if ($customerId === 0) {
            return ['status' => 'skipped', 'reason' => 'Customer record could not be created.', 'account' => $accountNumber, 'allocated' => false];
        }

        // ---- BC allocation ----------------------------------------------
        $bcId = null;
        $allocated = false;
        if ($strategy === 'bc_code') {
            $bcCode = $get($row, 'bc_code');
            if ($bcCode !== '') {
                if (!array_key_exists($bcCode, $bcCache)) {
                    $bc = Database::first(
                        'SELECT id FROM bc_agents WHERE bc_code = ? AND status = "active" LIMIT 1',
                        [$bcCode]
                    );
                    $bcCache[$bcCode] = $bc === null ? null : (int) $bc['id'];
                }
                $bcId = $bcCache[$bcCode];
                if ($bcId === null) {
                    // Import the account but leave it unallocated and say why.
                    Logger::info('Unknown BC code in import: ' . $bcCode);
                } else {
                    $allocated = true;
                }
            }
        }

        // ---- loan (upsert on account number) -----------------------------
        $existingLoan = Database::first(
            'SELECT id, bc_id, total_recovered FROM loans WHERE account_number = ? LIMIT 1',
            [$accountNumber]
        );

        $assetClass = $this->normaliseAssetClass($get($row, 'asset_class'));
        $outstanding = $this->amount($get($row, 'outstanding_amount'));
        $overdue = $this->amount($get($row, 'overdue_amount'));

        $loanData = [
            'account_number'      => substr($accountNumber, 0, 50),
            'customer_id'         => $customerId,
            'branch_id'           => $branchId,
            'product_name'        => $this->nullTrim($get($row, 'product_name'), 120),
            'scheme_code'         => $this->nullTrim($get($row, 'scheme_code'), 40),
            'sanction_amount'     => $this->amount($get($row, 'sanction_amount')),
            'disbursed_amount'    => $this->amount($get($row, 'disbursed_amount')),
            'outstanding_amount'  => $outstanding,
            'overdue_amount'      => $overdue,
            'principal_overdue'   => $this->amount($get($row, 'principal_overdue')),
            'interest_overdue'    => $this->amount($get($row, 'interest_overdue')),
            'emi_amount'          => $this->amount($get($row, 'emi_amount')),
            'disbursement_date'   => $this->date($get($row, 'disbursement_date')),
            'maturity_date'       => $this->date($get($row, 'maturity_date')),
            'npa_date'            => $this->date($get($row, 'npa_date')),
            'asset_class'         => $assetClass,
            'dpd'                 => min(65535, max(0, (int) $this->amount($get($row, 'dpd')))),
            'last_paid_date'      => $this->date($get($row, 'last_paid_date')),
            'last_paid_amount'    => $this->amount($get($row, 'last_paid_amount')),
            'allocation_batch_id' => $batchId,
            'status'              => 'active',
        ];

        if ($existingLoan === null) {
            $loanData['bc_id'] = $bcId;
            $loanData['allocated_at'] = $bcId === null ? null : date('Y-m-d H:i:s');
            Database::insert('loans', $loanData);

            return ['status' => 'inserted', 'reason' => '', 'account' => $accountNumber, 'allocated' => $allocated];
        }

        // On update, keep the existing allocation unless the sheet names a BC.
        if ($bcId !== null) {
            $loanData['bc_id'] = $bcId;
            $loanData['allocated_at'] = date('Y-m-d H:i:s');
            $allocated = (int) ($existingLoan['bc_id'] ?? 0) !== $bcId;
        }

        Database::update('loans', $loanData, ['id' => (int) $existingLoan['id']]);

        return ['status' => 'updated', 'reason' => '', 'account' => $accountNumber, 'allocated' => $allocated];
    }

    /**
     * Spread unallocated accounts evenly across each branch's active BC agents,
     * giving the lightest-loaded agent the next account.
     */
    public function distributeEqually(?int $branchId = null): int
    {
        $branches = $branchId !== null
            ? [['id' => $branchId]]
            : Database::all('SELECT DISTINCT branch_id AS id FROM loans WHERE bc_id IS NULL AND branch_id IS NOT NULL AND status = "active"');

        $allocated = 0;

        foreach ($branches as $branch) {
            $currentBranchId = (int) $branch['id'];

            $agents = Database::all(
                'SELECT b.id, (SELECT COUNT(*) FROM loans l WHERE l.bc_id = b.id AND l.status = "active") AS account_load
                 FROM bc_agents b
                 WHERE b.branch_id = ? AND b.status = "active"
                 ORDER BY load ASC',
                [$currentBranchId]
            );

            if ($agents === []) {
                continue;
            }

            $loads = [];
            foreach ($agents as $agent) {
                $loads[(int) $agent['id']] = (int) $agent['account_load'];
            }

            $unallocated = Database::all(
                'SELECT id FROM loans
                 WHERE bc_id IS NULL AND branch_id = ? AND status = "active"
                 ORDER BY overdue_amount DESC',
                [$currentBranchId]
            );

            foreach ($unallocated as $loan) {
                // Pick the agent with the smallest current workload.
                $targetBc = array_key_first($loads);
                $minimum = PHP_INT_MAX;
                foreach ($loads as $bcId => $load) {
                    if ($load < $minimum) {
                        $minimum = $load;
                        $targetBc = $bcId;
                    }
                }

                Database::update('loans', [
                    'bc_id'        => $targetBc,
                    'allocated_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int) $loan['id']]);

                $loads[$targetBc]++;
                $allocated++;
            }
        }

        if ($allocated > 0) {
            Audit::log('loans.auto_allocated', 'loans', null,
                $allocated . ' account(s) distributed equally among branch BC agents', null, null, 'notice');
        }

        return $allocated;
    }

    /** The importable column list, used to build the downloadable template. */
    public static function templateHeaders(): array
    {
        return [
            'ACCOUNT_NUMBER', 'CIF_NUMBER', 'CUSTOMER_NAME', 'GUARDIAN_NAME', 'MOBILE', 'ALT_MOBILE',
            'AADHAAR', 'ADDRESS', 'VILLAGE', 'PANCHAYAT', 'BLOCK', 'DISTRICT', 'STATE', 'PINCODE',
            'OCCUPATION', 'LATITUDE', 'LONGITUDE', 'BRANCH_CODE', 'BRANCH_NAME', 'BC_CODE',
            'PRODUCT_NAME', 'SCHEME_CODE', 'SANCTION_AMOUNT', 'DISBURSED_AMOUNT', 'OUTSTANDING_AMOUNT',
            'OVERDUE_AMOUNT', 'PRINCIPAL_OVERDUE', 'INTEREST_OVERDUE', 'EMI_AMOUNT',
            'DISBURSEMENT_DATE', 'MATURITY_DATE', 'NPA_DATE', 'ASSET_CLASS', 'DPD',
            'LAST_PAID_DATE', 'LAST_PAID_AMOUNT',
        ];
    }

    /** A ready-to-fill .xlsx template with one example row. */
    public static function template(): string
    {
        $example = [
            '38291047561', 'CIF0099213', 'Suresh Prasad', 'Mahesh Prasad', '9123456780', '',
            '', 'Near Post Office', 'Bihta', 'Bihta', 'Bihta', 'Patna', 'Bihar', '801103',
            'Kirana Shop', '25.5514', '84.8783', 'BR001', 'Bihta Branch', 'BC0142',
            'KCC', 'KCC01', '300000', '300000', '245780',
            '61200', '52000', '9200', '8500',
            '2023-06-15', '2028-06-15', '2025-11-30', 'SMA2', '78',
            '2026-04-10', '8500',
        ];

        return SpreadsheetWriter::xlsx(self::templateHeaders(), [$example], 'Allocation Template');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @param list<array{row:int,account:string,reason:string}> $rejected */
    private function writeErrorReport(string $batchUid, array $rejected): ?string
    {
        $relative = 'excel/' . date('Y/m') . '/' . $batchUid . '_errors.csv';
        $absolute = UPLOAD_PATH . '/' . $relative;

        $directory = dirname($absolute);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            Logger::warning('Could not create the error report directory: ' . $directory);
            return null;
        }

        $rows = array_map(
            static fn (array $item): array => [$item['row'], $item['account'], $item['reason']],
            $rejected
        );

        $csv = SpreadsheetWriter::csv(['ROW', 'ACCOUNT_NUMBER', 'REASON'], $rows);

        return file_put_contents($absolute, $csv) === false ? null : $relative;
    }

    private function moveToUploads(string $tempPath, string $relative): void
    {
        $absolute = UPLOAD_PATH . '/' . $relative;
        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                'Upload folder is not writable: ' . $directory
                . '. Set uploads/ to 755 in cPanel File Manager.'
            );
        }

        $moved = is_uploaded_file($tempPath)
            ? move_uploaded_file($tempPath, $absolute)
            : rename($tempPath, $absolute);

        if (!$moved) {
            throw new \RuntimeException('The uploaded file could not be saved to ' . $directory . '.');
        }
    }

    private function nullTrim(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Tolerates "1,23,456.78", "Rs. 1234", "(500)" and blanks. */
    private function amount(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return 0.0;
        }
        $number = (float) $clean;
        return $negative ? -abs($number) : $number;
    }

    private function coordinate(string $value, float $limit): ?float
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if ($number < -$limit || $number > $limit || abs($number) < 0.000001) {
            return null;
        }
        return $number;
    }

    /** Accepts d-m-Y, d/m/Y, Y-m-d and Excel serials already converted upstream. */
    private function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'Y/m/d', 'd-M-Y', 'd M Y', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * Map the many ways banks write asset classification onto our enum.
     */
    private function normaliseAssetClass(string $value): string
    {
        $key = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');

        $map = [
            'STD' => 'STD', 'STANDARD' => 'STD', 'PA' => 'STD', 'PERFORMING' => 'STD', '' => 'STD',
            'SMA0' => 'SMA0', 'SMA' => 'SMA0',
            'SMA1' => 'SMA1',
            'SMA2' => 'SMA2',
            'SS' => 'SS', 'SUBSTANDARD' => 'SS', 'SUB' => 'SS',
            'DF' => 'DF1', 'DOUBTFUL' => 'DF1', 'D1' => 'DF1', 'DF1' => 'DF1', 'DOUBTFUL1' => 'DF1',
            'D2' => 'DF2', 'DF2' => 'DF2', 'DOUBTFUL2' => 'DF2',
            'D3' => 'DF3', 'DF3' => 'DF3', 'DOUBTFUL3' => 'DF3',
            'LOSS' => 'LOSS', 'LA' => 'LOSS', 'LOSSASSET' => 'LOSS',
        ];

        return $map[$key] ?? 'STD';
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'upload';
        return substr($name, -80);
    }
}
