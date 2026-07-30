<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Validator;
use App\Services\AuthService;
use Lib\Crypto;

/**
 * User administration: listing, approval, status changes, device reset and
 * password reset.
 */
final class UserController extends Controller
{
    protected ?string $permission = 'users.view';

    public function index(): void
    {
        $pagination = $this->paginate();

        $where = '1 = 1';
        $params = [];

        // Branch managers only see their own branch's staff.
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            $where .= ' AND u.branch_id = ?';
            $params[] = Auth::branchId() ?? 0;
        }

        $search = $this->request->str('search');
        if ($search !== '') {
            $mobileHash = Crypto::blindIndex($search, 'mobile');
            $emailHash = Crypto::blindIndex($search, 'email');
            $where .= ' AND (u.full_name LIKE ? OR u.employee_code LIKE ? OR u.mobile_hash = ? OR u.email_hash = ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = $mobileHash;
            $params[] = $emailHash;
        }

        $status = $this->request->str('status');
        if (in_array($status, ['pending', 'active', 'suspended', 'disabled'], true)) {
            $where .= ' AND u.status = ?';
            $params[] = $status;
        }

        $roleId = $this->request->int('role_id');
        if ($roleId > 0) {
            $where .= ' AND u.role_id = ?';
            $params[] = $roleId;
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM users u WHERE ' . $where, $params, 0);

        $users = Database::all(
            'SELECT u.id, u.full_name, u.employee_code, u.status, u.mobile_last4, u.device_id,
                    u.device_model, u.last_login_at, u.created_at, u.locked_until,
                    r.name AS role_name, r.code AS role_code, b.name AS branch_name,
                    bc.bc_code
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN branches b ON b.id = u.branch_id
             LEFT JOIN bc_agents bc ON bc.user_id = u.id
             WHERE ' . $where . '
             ORDER BY FIELD(u.status, "pending", "active", "suspended", "disabled"), u.created_at DESC
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('users/index', [
            'pageTitle' => 'Users',
            'users'     => $users,
            'roles'     => Database::all('SELECT id, name FROM roles ORDER BY hierarchy'),
            'meta'      => $this->paginationMeta($total, $pagination),
            'filters'   => ['search' => $search, 'status' => $status, 'role_id' => $roleId],
            'counts'    => Database::first(
                'SELECT
                    SUM(status = "pending") AS pending,
                    SUM(status = "active") AS active,
                    SUM(status = "suspended") AS suspended,
                    SUM(status = "disabled") AS disabled
                 FROM users'
            ) ?? [],
        ]);
    }

    public function create(): void
    {
        $this->authorize('users.create');

        if (!$this->request->isPost()) {
            $this->view('users/form', [
                'pageTitle' => 'Add user',
                'user'      => null,
                'roles'     => Database::all('SELECT id, name, code FROM roles ORDER BY hierarchy'),
                'branches'  => $this->branchOptions(),
            ]);
            return;
        }

        $this->verifyCsrf();

        $validator = new Validator($this->request->all());
        $validator->required('full_name')->maxLen('full_name', 150)
            ->required('role_id')->integer('role_id')
            ->required('mobile')->mobile('mobile')
            ->maxLen('employee_code', 40);
        if ($this->request->str('email') !== '') {
            $validator->email('email');
        }
        if ((string) $this->request->input('password', '') !== '') {
            $validator->strongPassword('password');
        }

        if ($validator->fails()) {
            $this->back('danger', $validator->summary());
            return;
        }

        $mobile = $this->request->str('mobile');
        $mobileHash = Crypto::blindIndex($mobile, 'mobile');

        if (Database::first('SELECT id FROM users WHERE mobile_hash = ?', [$mobileHash]) !== null) {
            $this->back('danger', 'A user with this mobile number already exists.');
            return;
        }

        $email = $this->request->str('email');
        $emailHash = $email !== '' ? Crypto::blindIndex($email, 'email') : null;
        if ($emailHash !== null && Database::first('SELECT id FROM users WHERE email_hash = ?', [$emailHash]) !== null) {
            $this->back('danger', 'A user with this email address already exists.');
            return;
        }

        $roleId = $this->request->int('role_id');
        $roleCode = (string) Database::value('SELECT code FROM roles WHERE id = ?', [$roleId], '');
        if ($roleCode === '') {
            $this->back('danger', 'Choose a valid role.');
            return;
        }

        // A branch manager can only create staff inside their own branch.
        $branchId = $this->request->int('branch_id');
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            $branchId = Auth::branchId() ?? 0;
        }

        $password = (string) $this->request->input('password', '');
        $temporaryPassword = null;
        if ($password === '') {
            // Generate one rather than creating an account nobody can sign into.
            $temporaryPassword = 'Lrms@' . Crypto::randomCode(6);
            $password = $temporaryPassword;
        }

        $userId = Database::transaction(function () use ($validator, $roleId, $roleCode, $branchId, $mobile, $mobileHash, $email, $emailHash, $password): int {
            $userId = Database::insert('users', [
                'uuid'                 => Crypto::uuid4(),
                'role_id'              => $roleId,
                'branch_id'            => $branchId > 0 ? $branchId : null,
                'employee_code'        => $this->request->str('employee_code') !== '' ? $this->request->str('employee_code') : null,
                'full_name'            => $this->request->str('full_name'),
                'mobile_enc'           => Crypto::encrypt(Crypto::normalise($mobile, 'mobile')),
                'mobile_hash'          => $mobileHash,
                'mobile_last4'         => Crypto::last4($mobile),
                'email_enc'            => $email !== '' ? Crypto::encrypt(Crypto::normalise($email, 'email')) : null,
                'email_hash'           => $emailHash,
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password' => 1,
                'status'               => 'active',
                'approved_by'          => Auth::id(),
                'approved_at'          => date('Y-m-d H:i:s'),
                'created_by'           => Auth::id(),
            ]);

            if ($roleCode === Auth::ROLE_BC_AGENT) {
                $bcCode = $this->request->str('bc_code');
                if ($bcCode === '') {
                    $bcCode = $this->request->str('employee_code') !== ''
                        ? $this->request->str('employee_code')
                        : 'BC' . str_pad((string) $userId, 5, '0', STR_PAD_LEFT);
                }
                if (Database::first('SELECT id FROM bc_agents WHERE bc_code = ?', [$bcCode]) !== null) {
                    $bcCode .= '-' . $userId;
                }

                Database::insert('bc_agents', [
                    'user_id'        => $userId,
                    'bc_code'        => $bcCode,
                    'branch_id'      => $branchId > 0 ? $branchId : null,
                    'monthly_target' => max(0, $this->request->float('monthly_target')),
                    'visit_target'   => max(0, min(255, $this->request->int('visit_target'))),
                    'status'         => 'active',
                ]);
            }

            return $userId;
        });

        Audit::log('user.created', 'user', $userId,
            'Created user ' . $this->request->str('full_name') . ' (' . $roleCode . ')',
            null, null, 'notice');

        $message = 'User created.';
        if ($temporaryPassword !== null) {
            // Shown once, on screen only. Never written to the log or emailed
            // in clear unless SMTP is configured by the operator.
            $message .= ' Temporary password: ' . $temporaryPassword
                . ' - share it securely; the user must change it at first sign-in.';
        }

        $this->redirect('users', 'success', $message);
    }

    public function edit(array $args): void
    {
        $this->authorize('users.edit');
        $id = (int) ($args['id'] ?? 0);

        $user = Database::first(
            'SELECT u.*, bc.bc_code, bc.monthly_target, bc.visit_target
             FROM users u LEFT JOIN bc_agents bc ON bc.user_id = u.id
             WHERE u.id = ? LIMIT 1',
            [$id]
        );
        if ($user === null) {
            $this->redirect('users', 'warning', 'That user does not exist.');
            return;
        }
        if (!$this->canManage($user)) {
            $this->redirect('users', 'danger', 'You can only manage users in your own branch.');
            return;
        }

        if (!$this->request->isPost()) {
            $user['mobile'] = Crypto::decrypt($user['mobile_enc'] ?? null);
            $user['email'] = Crypto::decrypt($user['email_enc'] ?? null);

            $this->view('users/form', [
                'pageTitle' => 'Edit user',
                'user'      => $user,
                'roles'     => Database::all('SELECT id, name, code FROM roles ORDER BY hierarchy'),
                'branches'  => $this->branchOptions(),
            ]);
            return;
        }

        $this->verifyCsrf();

        $validator = new Validator($this->request->all());
        $validator->required('full_name')->maxLen('full_name', 150)
            ->required('mobile')->mobile('mobile')
            ->maxLen('employee_code', 40);
        if ($this->request->str('email') !== '') {
            $validator->email('email');
        }
        if ($validator->fails()) {
            $this->back('danger', $validator->summary());
            return;
        }

        $mobile = $this->request->str('mobile');
        $mobileHash = Crypto::blindIndex($mobile, 'mobile');
        $clash = Database::first('SELECT id FROM users WHERE mobile_hash = ? AND id <> ?', [$mobileHash, $id]);
        if ($clash !== null) {
            $this->back('danger', 'Another user already uses this mobile number.');
            return;
        }

        $email = $this->request->str('email');
        $emailHash = $email !== '' ? Crypto::blindIndex($email, 'email') : null;
        if ($emailHash !== null
            && Database::first('SELECT id FROM users WHERE email_hash = ? AND id <> ?', [$emailHash, $id]) !== null) {
            $this->back('danger', 'Another user already uses this email address.');
            return;
        }

        $branchId = Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)
            ? (Auth::branchId() ?? 0)
            : $this->request->int('branch_id');

        $before = [
            'full_name'     => $user['full_name'],
            'employee_code' => $user['employee_code'],
            'branch_id'     => $user['branch_id'],
            'role_id'       => $user['role_id'],
        ];

        $update = [
            'full_name'     => $this->request->str('full_name'),
            'employee_code' => $this->request->str('employee_code') !== '' ? $this->request->str('employee_code') : null,
            'branch_id'     => $branchId > 0 ? $branchId : null,
            'mobile_enc'    => Crypto::encrypt(Crypto::normalise($mobile, 'mobile')),
            'mobile_hash'   => $mobileHash,
            'mobile_last4'  => Crypto::last4($mobile),
            'email_enc'     => $email !== '' ? Crypto::encrypt(Crypto::normalise($email, 'email')) : null,
            'email_hash'    => $emailHash,
        ];

        // Only a super admin may change roles, and never their own.
        if (Auth::isSuperAdmin() && $this->request->int('role_id') > 0 && $id !== Auth::id()) {
            $update['role_id'] = $this->request->int('role_id');
        }

        Database::update('users', $update, ['id' => $id]);

        if ($user['bc_code'] !== null) {
            Database::update('bc_agents', [
                'branch_id'      => $branchId > 0 ? $branchId : null,
                'monthly_target' => max(0, $this->request->float('monthly_target')),
                'visit_target'   => max(0, min(255, $this->request->int('visit_target'))),
            ], ['user_id' => $id]);
        }

        [$old, $new] = Audit::diff($before, [
            'full_name'     => $update['full_name'],
            'employee_code' => $update['employee_code'],
            'branch_id'     => $update['branch_id'],
            'role_id'       => $update['role_id'] ?? $user['role_id'],
        ]);

        Audit::log('user.updated', 'user', $id, 'Updated user profile', $old, $new, 'notice');

        $this->redirect('users', 'success', 'User updated.');
    }

    public function approve(array $args): void
    {
        $this->authorize('users.approve');
        $this->verifyCsrf();
        $id = (int) ($args['id'] ?? 0);

        $user = Database::first('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === null) {
            $this->back('warning', 'That user does not exist.');
            return;
        }
        if (!$this->canManage($user)) {
            $this->back('danger', 'You can only approve users in your own branch.');
            return;
        }
        if ($user['status'] !== 'pending') {
            $this->back('info', 'This account is already ' . $user['status'] . '.');
            return;
        }

        Database::update('users', [
            'status'      => 'active',
            'approved_by' => Auth::id(),
            'approved_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        Audit::log('user.approved', 'user', $id, 'Approved ' . $user['full_name'], null, null, 'notice');

        $this->back('success', $user['full_name'] . ' can now sign in.');
    }

    public function changeStatus(array $args): void
    {
        $this->authorize('users.edit');
        $this->verifyCsrf();
        $id = (int) ($args['id'] ?? 0);
        $status = $this->request->str('status');

        if (!in_array($status, ['active', 'suspended', 'disabled'], true)) {
            $this->back('danger', 'Invalid status.');
            return;
        }
        if ($id === Auth::id()) {
            $this->back('danger', 'You cannot change your own account status.');
            return;
        }

        $user = Database::first('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === null || !$this->canManage($user)) {
            $this->back('danger', 'You cannot manage that user.');
            return;
        }
        if ($user['role_id'] === 1 && !Auth::isSuperAdmin()) {
            $this->back('danger', 'Only a Super Admin can change another Super Admin.');
            return;
        }

        Database::update('users', [
            'status'          => $status,
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], ['id' => $id]);

        // A suspended or disabled account must lose its app sessions at once.
        if ($status !== 'active') {
            (new AuthService())->revokeAllTokens($id, 'account ' . $status);
        }

        Audit::log('user.status_changed', 'user', $id,
            $user['full_name'] . ' set to ' . $status,
            ['status' => $user['status']], ['status' => $status], 'warning');

        $this->back('success', $user['full_name'] . ' is now ' . $status . '.');
    }

    /** Clear the device binding so the user can sign in from a new handset. */
    public function resetDevice(array $args): void
    {
        $this->authorize('users.edit');
        $this->verifyCsrf();
        $id = (int) ($args['id'] ?? 0);

        $user = Database::first('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === null || !$this->canManage($user)) {
            $this->back('danger', 'You cannot manage that user.');
            return;
        }

        Database::update('users', [
            'device_id'       => null,
            'device_model'    => null,
            'device_bound_at' => null,
        ], ['id' => $id]);

        Database::run(
            'UPDATE user_devices SET status = "released", released_at = NOW(), released_by = ?
             WHERE user_id = ? AND status = "active"',
            [Auth::id(), $id]
        );

        (new AuthService())->revokeAllTokens($id, 'device reset by admin');

        Audit::log('user.device_reset', 'user', $id,
            'Device binding cleared for ' . $user['full_name'], null, null, 'warning');

        $this->back('success', $user['full_name'] . ' can now register a new device on next sign-in.');
    }

    public function resetPassword(array $args): void
    {
        $this->authorize('users.edit');
        $this->verifyCsrf();
        $id = (int) ($args['id'] ?? 0);

        $user = Database::first('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === null || !$this->canManage($user)) {
            $this->back('danger', 'You cannot manage that user.');
            return;
        }

        $temporary = 'Lrms@' . Crypto::randomCode(6);

        Database::update('users', [
            'password_hash'        => password_hash($temporary, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ], ['id' => $id]);

        (new AuthService())->revokeAllTokens($id, 'password reset by admin');

        // The password itself is deliberately NOT written to the audit log.
        Audit::log('user.password_reset', 'user', $id,
            'Temporary password issued for ' . $user['full_name'], null, null, 'warning');

        $this->back('success', 'Temporary password for ' . $user['full_name'] . ': ' . $temporary
            . ' - share it securely. They must change it at first sign-in.');
    }

    /** @param array<string,mixed> $user */
    private function canManage(array $user): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER)) {
            return $user['branch_id'] !== null && (int) $user['branch_id'] === Auth::branchId();
        }
        return false;
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
