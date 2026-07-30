<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Validator;

final class BranchController extends Controller
{
    protected ?string $permission = 'branches.manage';

    public function index(): void
    {
        $pagination = $this->paginate();
        [$scope, $params] = Auth::scopeSql('br.id');

        $where = '1 = 1' . $scope;
        $search = $this->request->str('search');
        if ($search !== '') {
            $where .= ' AND (br.code LIKE ? OR br.name LIKE ? OR br.district LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $total = (int) Database::value('SELECT COUNT(*) FROM branches br WHERE ' . $where, $params, 0);

        $branches = Database::all(
            'SELECT br.*,
                    (SELECT COUNT(*) FROM bc_agents b WHERE b.branch_id = br.id AND b.status = "active") AS bc_count,
                    (SELECT COUNT(*) FROM loans l WHERE l.branch_id = br.id AND l.status = "active") AS loan_count,
                    (SELECT COALESCE(SUM(l.outstanding_amount),0) FROM loans l WHERE l.branch_id = br.id AND l.status = "active") AS outstanding,
                    m.full_name AS manager_name
             FROM branches br
             LEFT JOIN users m ON m.id = br.manager_id
             WHERE ' . $where . '
             ORDER BY br.name
             LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'],
            $params
        );

        $this->view('branches/index', [
            'pageTitle' => 'Branches',
            'branches'  => $branches,
            'meta'      => $this->paginationMeta($total, $pagination),
            'filters'   => ['search' => $search],
        ]);
    }

    public function create(): void
    {
        if (!$this->request->isPost()) {
            $this->view('branches/form', [
                'pageTitle' => 'Add branch',
                'branch'    => null,
                'managers'  => $this->managerOptions(),
            ]);
            return;
        }

        $this->verifyCsrf();

        $validator = $this->validate();
        if ($validator->fails()) {
            $this->back('danger', $validator->summary());
            return;
        }

        $code = strtoupper($this->request->str('code'));
        if (Database::first('SELECT id FROM branches WHERE code = ?', [$code]) !== null) {
            $this->back('danger', 'A branch with code ' . $code . ' already exists.');
            return;
        }

        $id = Database::insert('branches', $this->payload($code));

        Audit::log('branch.created', 'branch', $id,
            'Created branch ' . $code . ' - ' . $this->request->str('name'), null, null, 'notice');

        $this->redirect('branches', 'success', 'Branch created.');
    }

    public function edit(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $branch = Database::first('SELECT * FROM branches WHERE id = ? LIMIT 1', [$id]);

        if ($branch === null) {
            $this->redirect('branches', 'warning', 'That branch does not exist.');
            return;
        }
        if (Auth::hasRole(Auth::ROLE_BRANCH_MANAGER) && $id !== Auth::branchId()) {
            $this->redirect('branches', 'danger', 'You can only edit your own branch.');
            return;
        }

        if (!$this->request->isPost()) {
            $this->view('branches/form', [
                'pageTitle' => 'Edit branch',
                'branch'    => $branch,
                'managers'  => $this->managerOptions(),
            ]);
            return;
        }

        $this->verifyCsrf();

        $validator = $this->validate();
        if ($validator->fails()) {
            $this->back('danger', $validator->summary());
            return;
        }

        $code = strtoupper($this->request->str('code'));
        if (Database::first('SELECT id FROM branches WHERE code = ? AND id <> ?', [$code, $id]) !== null) {
            $this->back('danger', 'Another branch already uses code ' . $code . '.');
            return;
        }

        $payload = $this->payload($code);
        [$old, $new] = Audit::diff($branch, $payload);

        Database::update('branches', $payload, ['id' => $id]);

        Audit::log('branch.updated', 'branch', $id, 'Updated branch ' . $code, $old, $new, 'notice');

        $this->redirect('branches', 'success', 'Branch updated.');
    }

    private function validate(): Validator
    {
        $validator = new Validator($this->request->all());
        $validator->required('code')->maxLen('code', 30)
            ->required('name')->maxLen('name', 150)
            ->maxLen('ifsc', 15)
            ->maxLen('district', 80)
            ->maxLen('block', 80)
            ->maxLen('address', 255)
            ->maxLen('pincode', 10)
            ->maxLen('contact_phone', 20)
            ->integer('geofence_m')->min('geofence_m', 50)->max('geofence_m', 20000);

        if ($this->request->str('latitude') !== '') {
            $validator->latitude('latitude');
        }
        if ($this->request->str('longitude') !== '') {
            $validator->longitude('longitude');
        }

        return $validator;
    }

    /** @return array<string,mixed> */
    private function payload(string $code): array
    {
        $managerId = $this->request->int('manager_id');

        return [
            'code'          => $code,
            'name'          => $this->request->str('name'),
            'ifsc'          => $this->nullable('ifsc'),
            'district'      => $this->nullable('district'),
            'block'         => $this->nullable('block'),
            'address'       => $this->nullable('address'),
            'pincode'       => $this->nullable('pincode'),
            'contact_phone' => $this->nullable('contact_phone'),
            'latitude'      => $this->request->str('latitude') !== '' ? $this->request->float('latitude') : null,
            'longitude'     => $this->request->str('longitude') !== '' ? $this->request->float('longitude') : null,
            'geofence_m'    => max(50, min(20000, $this->request->int('geofence_m', 200))),
            'manager_id'    => $managerId > 0 ? $managerId : null,
            'status'        => $this->request->str('status') === 'inactive' ? 'inactive' : 'active',
        ];
    }

    private function nullable(string $key): ?string
    {
        $value = $this->request->str($key);
        return $value === '' ? null : $value;
    }

    /** @return list<array<string,mixed>> */
    private function managerOptions(): array
    {
        return Database::all(
            'SELECT u.id, u.full_name, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.status = "active" AND r.code IN ("branch_manager","super_admin","regional_office")
             ORDER BY u.full_name'
        );
    }
}
