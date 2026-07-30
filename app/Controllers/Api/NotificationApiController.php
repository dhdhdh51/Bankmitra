<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ApiController;
use App\Core\Database;
use App\Core\Response;

/**
 * In-app notification inbox. Rows are written whether or not push delivery
 * succeeded, so an unconfigured Firebase never loses a message.
 */
final class NotificationApiController extends ApiController
{
    /** GET /notifications */
    public function index(): void
    {
        $pagination = $this->pagination(30);

        $where = '(n.user_id = ? OR n.user_id IS NULL)';
        $params = [$this->userId()];

        if ($this->request->bool('unread_only', false)) {
            $where .= ' AND n.read_at IS NULL';
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM notifications n WHERE ' . $where, $params, 0);

        $rows = Database::all(
            'SELECT n.id, n.title, n.body, n.data_json, n.channel, n.status, n.sent_at, n.read_at, n.created_at
             FROM notifications n WHERE ' . $where . '
             ORDER BY n.created_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $unread = (int) Database::value(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND read_at IS NULL',
            [$this->userId()],
            0
        );

        $data = array_map(static function (array $row): array {
            $payload = null;
            if (is_string($row['data_json']) && $row['data_json'] !== '') {
                $decoded = json_decode((string) $row['data_json'], true);
                $payload = is_array($decoded) ? $decoded : null;
            }

            return [
                'id'         => (int) $row['id'],
                'title'      => (string) $row['title'],
                'body'       => $row['body'],
                'data'       => $payload,
                'channel'    => (string) $row['channel'],
                'is_read'    => $row['read_at'] !== null,
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        Response::ok($data, 'OK', array_merge($this->meta($total, $pagination), ['unread' => $unread]));
    }

    /** POST /notifications/{id}/read */
    public function markRead(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);

        $affected = Database::run(
            'UPDATE notifications SET read_at = NOW()
             WHERE id = ? AND (user_id = ? OR user_id IS NULL) AND read_at IS NULL',
            [$id, $this->userId()]
        )->rowCount();

        if ($affected === 0) {
            // Either it does not exist, is not ours, or was already read - all
            // harmless, so report success rather than an error the app must handle.
            Response::ok(null, 'Notification already read.');
            return;
        }

        Response::ok(null, 'Notification marked as read.');
    }
}
