<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Validator;
use App\Services\AuthService;
use Lib\Settings;

/**
 * Invitation codes - the only way a new account can be created by the user
 * themselves, since open signup is disabled.
 */
final class InviteController extends Controller
{
    protected ?string $permission = 'invites.create';

    public function index(): void
    {
        $pagination = $this->paginate();

        $where = '1 = 1';
        $params = [];

        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            $where .= ' AND (ic.branch_id = ? OR ic.created_by = ?)';
            $params[] = Auth::branchId() ?? 0;
            $params[] = Auth::id();
        }

        $status = $this->request->str('status');
        if (in_array($status, ['active', 'exhausted', 'expired', 'revoked'], true)) {
            $where .= ' AND ic.status = ?';
            $params[] = $status;
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM invitation_codes ic WHERE ' . $where, $params, 0);

        $codes = Database::all(
            'SELECT ic.*, r.name AS role_name, b.name AS branch_name, u.full_name AS created_by_name
             FROM invitation_codes ic
             JOIN roles r ON r.id = ic.role_id
             LEFT JOIN branches b ON b.id = ic.branch_id
             LEFT JOIN users u ON u.id = ic.created_by
             WHERE ' . $where . '
             ORDER BY ic.created_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('invites/index', [
            'pageTitle' => 'Invitation codes',
            'codes'     => $codes,
            'meta'      => $this->paginationMeta($total, $pagination),
            'filters'   => ['status' => $status],
        ]);
    }

    public function create(): void
    {
        if (!$this->request->isPost()) {
            $this->view('invites/form', [
                'pageTitle' => 'Generate invitation code',
                'roles'     => $this->availableRoles(),
                'branches'  => $this->branchOptions(),
                'defaults'  => [
                    'expiry_days'       => Settings::getInt('invite.default_expiry_days', 7),
                    'role_id'           => Settings::getInt('invite.default_role_id', 4),
                    'requires_approval' => Settings::getBool('invite.requires_approval', true),
                ],
            ]);
            return;
        }

        $this->verifyCsrf();

        $validator = new Validator($this->request->all());
        $validator->required('role_id')->integer('role_id')
            ->required('max_uses')->integer('max_uses')->min('max_uses', 1)->max('max_uses', 500)
            ->integer('expiry_days')->min('expiry_days', 1)->max('expiry_days', 365)
            ->maxLen('notes', 255);

        if ($validator->fails()) {
            $this->back('danger', $validator->summary());
            return;
        }

        $roleId = $this->request->int('role_id');
        $allowed = array_column($this->availableRoles(), 'id');
        if (!in_array($roleId, array_map('intval', $allowed), true)) {
            $this->back('danger', 'You cannot issue invitations for that role.');
            return;
        }

        $branchId = Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)
            ? (Auth::branchId() ?? 0)
            : $this->request->int('branch_id');

        $expiryDays = $this->request->int('expiry_days', Settings::getInt('invite.default_expiry_days', 7));
        $quantity = max(1, min(50, $this->request->int('quantity', 1)));

        $service = new AuthService();
        $generated = [];

        for ($i = 0; $i < $quantity; $i++) {
            $code = $service->generateInviteCode();

            Database::insert('invitation_codes', [
                'code'              => $code,
                'role_id'           => $roleId,
                'branch_id'         => $branchId > 0 ? $branchId : null,
                'max_uses'          => $this->request->int('max_uses', 1),
                'requires_approval' => $this->request->bool('requires_approval') ? 1 : 0,
                'expires_at'        => date('Y-m-d H:i:s', time() + $expiryDays * 86400),
                'status'            => 'active',
                'notes'             => $this->request->str('notes') !== '' ? $this->request->str('notes') : null,
                'created_by'        => Auth::id(),
            ]);

            $generated[] = $code;
        }

        Audit::log('invite.created', 'invitation_code', null,
            'Generated ' . count($generated) . ' invitation code(s) for role #' . $roleId,
            null, null, 'notice');

        $this->redirect('invites', 'success',
            count($generated) === 1
                ? 'Invitation code created: ' . $generated[0]
                : count($generated) . ' invitation codes created: ' . implode(', ', $generated));
    }

    public function revoke(array $args): void
    {
        $this->verifyCsrf();
        $id = (int) ($args['id'] ?? 0);

        $code = Database::first('SELECT * FROM invitation_codes WHERE id = ? LIMIT 1', [$id]);
        if ($code === null) {
            $this->back('warning', 'That invitation code does not exist.');
            return;
        }

        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)
            && (int) ($code['created_by'] ?? 0) !== Auth::id()
            && (int) ($code['branch_id'] ?? 0) !== Auth::branchId()) {
            $this->back('danger', 'You can only revoke codes you issued.');
            return;
        }

        if ($code['status'] === 'revoked') {
            $this->back('info', 'That code is already revoked.');
            return;
        }

        Database::update('invitation_codes', ['status' => 'revoked'], ['id' => $id]);

        Audit::log('invite.revoked', 'invitation_code', $id,
            'Revoked code ' . $code['code'], null, null, 'warning');

        $this->back('success', 'Code ' . $code['code'] . ' revoked.');
    }

    /**
     * A user can never issue an invitation for a role above their own.
     * @return list<array<string,mixed>>
     */
    private function availableRoles(): array
    {
        $user = Auth::user();
        $hierarchy = (int) ($user['role_hierarchy'] ?? 100);

        return Database::all(
            'SELECT id, code, name FROM roles WHERE hierarchy >= ? ORDER BY hierarchy',
            [Auth::isSuperAdmin() ? 1 : $hierarchy + 1]
        );
    }

    /** @return list<array<string,mixed>> */
    private function branchOptions(): array
    {
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            return Database::all('SELECT id, code, name FROM branches WHERE id = ?', [Auth::branchId() ?? 0]);
        }
        return Database::all('SELECT id, code, name FROM branches WHERE status = "active" ORDER BY name');
    }
}
