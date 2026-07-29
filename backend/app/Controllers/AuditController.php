<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class AuditController extends Controller
{
    protected ?string $permission = 'audit.view';

    public function index(): void
    {
        $pagination = $this->paginate(60);

        $where = '1 = 1';
        $params = [];

        $search = $this->request->str('search');
        if ($search !== '') {
            $where .= ' AND (a.action LIKE ? OR a.description LIKE ? OR a.actor_name LIKE ?'
                . ' OR a.entity_type LIKE ? OR a.entity_id = ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $search);
        }

        $severity = $this->request->str('severity');
        if (in_array($severity, ['info', 'notice', 'warning', 'critical'], true)) {
            $where .= ' AND a.severity = ?';
            $params[] = $severity;
        }

        $channel = $this->request->str('channel');
        if (in_array($channel, ['web', 'api', 'cron', 'cli'], true)) {
            $where .= ' AND a.channel = ?';
            $params[] = $channel;
        }

        $userId = $this->request->int('user_id');
        if ($userId > 0) {
            $where .= ' AND a.user_id = ?';
            $params[] = $userId;
        }

        $from = $this->request->str('from');
        $to = $this->request->str('to');
        if ($from !== '') {
            $where .= ' AND a.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime($from) ?: time());
        }
        if ($to !== '') {
            $where .= ' AND a.created_at <= ?';
            $params[] = date('Y-m-d 23:59:59', strtotime($to) ?: time());
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM audit_logs a WHERE ' . $where, $params, 0);

        $entries = Database::all(
            'SELECT a.* FROM audit_logs a WHERE ' . $where . '
             ORDER BY a.id DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('audit/index', [
            'pageTitle' => 'Audit log',
            'entries'   => $entries,
            'meta'      => $this->paginationMeta($total, $pagination),
            'filters'   => [
                'search' => $search, 'severity' => $severity,
                'channel' => $channel, 'user_id' => $userId,
                'from' => $from, 'to' => $to,
            ],
            'users'     => Database::all(
                'SELECT id, full_name FROM users ORDER BY full_name LIMIT 500'
            ),
            'retention' => \Lib\Settings::getInt('security.audit_retention_days', 365),
        ]);
    }
}
