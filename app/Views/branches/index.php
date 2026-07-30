<?php

/**
 * @var list<array<string,mixed>> $branches
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array{search:string} $filters
 */

use App\Core\Auth;
use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1>Branches</h1>
        <div class="lrms-page-sub">
            Branches are also created automatically when a spreadsheet import contains a new
            <code>BRANCH_CODE</code>; fill in the address and GPS position here.
        </div>
    </div>
    <?php if (Auth::isSuperAdmin()): ?>
        <div class="ms-auto">
            <a class="btn btn-sm btn-success" href="<?= View::e(View::url('branches/create')) ?>">
                <i class="bi bi-plus-lg me-1"></i>Add branch
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-5">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>" placeholder="Code, name or district">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <?php if ($branches === []): ?>
        <div class="lrms-empty"><i class="bi bi-bank"></i>No branches yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>Code</th><th>Name</th><th>District</th><th>Manager</th>
                    <th class="lrms-num">BC</th><th class="lrms-num">Accounts</th>
                    <th class="lrms-num">Outstanding</th><th>GPS</th><th>Status</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($branches as $branch): ?>
                    <tr>
                        <td><code><?= View::e($branch['code']) ?></code></td>
                        <td>
                            <div class="fw-semibold"><?= View::e($branch['name']) ?></div>
                            <?php if (!empty($branch['ifsc'])): ?>
                                <div class="small text-muted">IFSC <?= View::e($branch['ifsc']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= View::e($branch['district'] ?? '-') ?></td>
                        <td class="small"><?= View::e($branch['manager_name'] ?? '-') ?></td>
                        <td class="lrms-num"><?= number_format((int) $branch['bc_count']) ?></td>
                        <td class="lrms-num"><?= number_format((int) $branch['loan_count']) ?></td>
                        <td class="lrms-num"><?= View::e(View::moneyShort($branch['outstanding'])) ?></td>
                        <td class="small">
                            <?php if ($branch['latitude'] !== null && $branch['longitude'] !== null): ?>
                                <span class="badge badge-soft bg-success">set</span>
                            <?php else: ?>
                                <span class="badge badge-soft bg-warning" title="Attendance geofencing needs branch coordinates">missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-soft bg-<?= $branch['status'] === 'active' ? 'success' : 'secondary' ?>">
                                <?= View::e(ucfirst((string) $branch['status'])) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= View::e(View::url('branches/' . (int) $branch['id'] . '/edit')) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
