<?php

/**
 * @var array<string,mixed>|null $branch
 * @var list<array<string,mixed>> $managers
 */

use App\Core\Csrf;
use App\Core\View;

$isEdit = $branch !== null;
$action = $isEdit ? View::url('branches/' . (int) $branch['id'] . '/edit') : View::url('branches/create');
$value = static fn (string $key, string $default = ''): string => View::e((string) ($branch[$key] ?? $default));
?>

<div class="lrms-page-head">
    <div>
        <h1><?= $isEdit ? 'Edit branch' : 'Add branch' ?></h1>
        <div class="lrms-page-sub">
            The GPS position and geofence radius are used to flag attendance check-ins made away
            from the branch.
        </div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('branches')) ?>">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="card" style="max-width:860px">
    <form method="post" action="<?= View::e($action) ?>" novalidate>
        <?= Csrf::field() ?>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="code">Branch code *</label>
                    <input type="text" class="form-control text-uppercase" id="code" name="code"
                           value="<?= $value('code') ?>" required maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="name">Branch name *</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= $value('name') ?>" required maxlength="150">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="ifsc">IFSC</label>
                    <input type="text" class="form-control text-uppercase" id="ifsc" name="ifsc"
                           value="<?= $value('ifsc') ?>" maxlength="15">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="district">District</label>
                    <input type="text" class="form-control" id="district" name="district"
                           value="<?= $value('district') ?>" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="block">Block</label>
                    <input type="text" class="form-control" id="block" name="block"
                           value="<?= $value('block') ?>" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="pincode">Pincode</label>
                    <input type="text" class="form-control" id="pincode" name="pincode"
                           value="<?= $value('pincode') ?>" maxlength="10">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="address">Address</label>
                    <input type="text" class="form-control" id="address" name="address"
                           value="<?= $value('address') ?>" maxlength="255">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="contact_phone">Contact phone</label>
                    <input type="text" class="form-control" id="contact_phone" name="contact_phone"
                           value="<?= $value('contact_phone') ?>" maxlength="20">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="latitude">Latitude</label>
                    <input type="text" class="form-control" id="latitude" name="latitude"
                           value="<?= $value('latitude') ?>" placeholder="25.5941">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="longitude">Longitude</label>
                    <input type="text" class="form-control" id="longitude" name="longitude"
                           value="<?= $value('longitude') ?>" placeholder="85.1376">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="geofence_m">Attendance geofence (m)</label>
                    <input type="number" class="form-control" id="geofence_m" name="geofence_m"
                           value="<?= $value('geofence_m', '200') ?>" min="50" max="20000">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="manager_id">Branch manager</label>
                    <select class="form-select" id="manager_id" name="manager_id">
                        <option value="">- none -</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?= (int) $manager['id'] ?>"
                                <?= (int) ($branch['manager_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>>
                                <?= View::e($manager['full_name']) ?> (<?= View::e($manager['role_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="active" <?= ($branch['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($branch['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-footer bg-white">
            <button class="btn btn-success" type="submit">
                <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save changes' : 'Create branch' ?>
            </button>
            <a class="btn btn-outline-secondary" href="<?= View::e(View::url('branches')) ?>">Cancel</a>
        </div>
    </form>
</div>
