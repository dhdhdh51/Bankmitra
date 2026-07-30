<?php

/**
 * @var list<array<string,mixed>> $customers
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array{search:string,village:string,branch_id:int} $filters
 * @var list<array<string,mixed>> $branches
 * @var list<array<string,mixed>> $villages
 */

use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Customers</h1>
        <div class="lrms-page-sub">
            Search by account number, CIF, mobile, name or village. Mobile numbers are stored
            encrypted and matched through a blind index.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-5">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>"
                       placeholder="Account no / CIF / mobile / name / village" autofocus>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="village">Village</label>
                <select class="form-select form-select-sm" id="village" name="village">
                    <option value="">All villages</option>
                    <?php foreach ($villages as $row): ?>
                        <option value="<?= View::e($row['village']) ?>"
                            <?= $filters['village'] === $row['village'] ? 'selected' : '' ?>>
                            <?= View::e($row['village']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="branch_id">Branch</label>
                <select class="form-select form-select-sm" id="branch_id" name="branch_id">
                    <option value="">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"
                            <?= $filters['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>>
                            <?= View::e($branch['code']) ?> - <?= View::e($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-success w-100" type="submit">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>

    <?php if ($customers === []): ?>
        <div class="lrms-empty">
            <i class="bi bi-person-x"></i>
            No customers match. Import an allocation spreadsheet from
            <a href="<?= View::e(View::url('imports')) ?>">Excel Upload</a> to load your portfolio.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Customer</th><th>CIF</th><th>Mobile</th><th>Village</th><th>Branch</th>
                    <th class="lrms-num">A/cs</th><th class="lrms-num">Outstanding</th>
                    <th class="lrms-num">Overdue</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td>
                            <a class="fw-semibold text-decoration-none"
                               href="<?= View::e(View::url('customers/' . (int) $customer['id'])) ?>">
                                <?= View::e($customer['full_name']) ?>
                            </a>
                            <?php if (!empty($customer['guardian_name'])): ?>
                                <div class="small text-muted">S/o, W/o <?= View::e($customer['guardian_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= View::e($customer['cif_number']) ?></td>
                        <td class="small">
                            <?= $customer['mobile_last4'] !== null ? '******' . View::e($customer['mobile_last4']) : '-' ?>
                        </td>
                        <td class="small"><?= View::e($customer['village'] ?? '-') ?></td>
                        <td class="small"><?= View::e($customer['branch_name'] ?? '-') ?></td>
                        <td class="lrms-num"><?= (int) $customer['loan_count'] ?></td>
                        <td class="lrms-num"><?= View::e(View::money($customer['outstanding'], false)) ?></td>
                        <td class="lrms-num text-danger"><?= View::e(View::money($customer['overdue'], false)) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= View::e(View::url('customers/' . (int) $customer['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
