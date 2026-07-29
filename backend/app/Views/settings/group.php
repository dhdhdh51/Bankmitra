<?php

/**
 * One settings group form.
 *
 * @var string $groupKey
 * @var array{label:string,icon:string,description:string,fields:array<string,array<string,mixed>>} $group
 * @var array<string,array<string,mixed>> $fields
 * @var array<string,array<string,mixed>> $allGroups
 */

use App\Core\Csrf;
use App\Core\View;
?>

<div class="lrms-page-head">
    <div>
        <h1><i class="bi bi-<?= View::e($group['icon']) ?> me-1"></i><?= View::e($group['label']) ?></h1>
        <div class="lrms-page-sub"><?= View::e($group['description']) ?></div>
    </div>
    <div class="ms-auto">
        <a class="btn btn-sm btn-outline-secondary" href="<?= View::e(View::url('settings')) ?>">
            <i class="bi bi-arrow-left me-1"></i>All settings
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-3 order-lg-2">
        <div class="card">
            <div class="card-header">Groups</div>
            <div class="list-group list-group-flush small">
                <?php foreach ($allGroups as $key => $definition): ?>
                    <a class="list-group-item list-group-item-action<?= $key === $groupKey ? ' active' : '' ?>"
                       href="<?= View::e(View::url('settings/' . $key)) ?>">
                        <i class="bi bi-<?= View::e($definition['icon']) ?> me-2"></i><?= View::e($definition['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($groupKey === 'smtp' || $groupKey === 'sms'): ?>
            <div class="card mt-3">
                <div class="card-header">Test delivery</div>
                <div class="card-body">
                    <p class="small text-muted">
                        Save first, then send a test. Failures show the exact gateway error so you can
                        fix the configuration instead of guessing.
                    </p>
                    <div class="input-group input-group-sm mb-2">
                        <input type="<?= $groupKey === 'smtp' ? 'email' : 'tel' ?>" class="form-control"
                               id="testTarget"
                               placeholder="<?= $groupKey === 'smtp' ? 'you@example.com' : '9876543210' ?>">
                        <button class="btn btn-outline-success" type="button" id="btnTest">Send test</button>
                    </div>
                    <div id="testResult" class="small"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-9 order-lg-1">
        <div class="card">
            <form method="post" action="<?= View::e(View::url('settings/' . $groupKey)) ?>" novalidate>
                <?= Csrf::field() ?>
                <div class="card-body">
                    <?php foreach ($fields as $name => $field): ?>
                        <div class="mb-3">
                            <?php if ($field['type'] === 'bool'): ?>

                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="f_<?= View::e($name) ?>" name="<?= View::e($name) ?>" value="1"
                                        <?= Lib\Settings::getBool($groupKey . '.' . $name) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="f_<?= View::e($name) ?>">
                                        <?= View::e($field['label']) ?>
                                    </label>
                                </div>
                                <?php if (!empty($field['help'])): ?>
                                    <div class="form-text ms-4"><?= View::e($field['help']) ?></div>
                                <?php endif; ?>

                            <?php else: ?>

                                <label class="form-label d-flex align-items-center gap-2" for="f_<?= View::e($name) ?>">
                                    <span><?= View::e($field['label']) ?></span>
                                    <?php if (!empty($field['is_sensitive'])): ?>
                                        <?php if (!empty($field['is_configured'])): ?>
                                            <span class="badge badge-soft bg-success">Configured</span>
                                        <?php else: ?>
                                            <span class="badge badge-soft bg-secondary">Not set</span>
                                        <?php endif; ?>
                                        <i class="bi bi-lock-fill text-muted" title="Stored encrypted"></i>
                                    <?php endif; ?>
                                </label>

                                <?php if ($field['type'] === 'textarea'): ?>
                                    <textarea class="form-control font-monospace" style="font-size:.82rem"
                                              id="f_<?= View::e($name) ?>" name="<?= View::e($name) ?>"
                                              rows="<?= $name === 'service_account_json' ? 7 : 3 ?>"
                                              <?= !empty($field['is_sensitive']) ? 'placeholder="Leave blank to keep the saved value"' : '' ?>><?= View::e($field['value']) ?></textarea>

                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select class="form-select" id="f_<?= View::e($name) ?>" name="<?= View::e($name) ?>">
                                        <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                            <option value="<?= View::e($optionValue) ?>"
                                                <?= (string) $field['value'] === (string) $optionValue ? 'selected' : '' ?>>
                                                <?= View::e($optionLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php else: ?>
                                    <input type="<?= View::e($field['type'] === 'password' ? 'password' : $field['type']) ?>"
                                           class="form-control"
                                           id="f_<?= View::e($name) ?>" name="<?= View::e($name) ?>"
                                           value="<?= View::e($field['value']) ?>"
                                           autocomplete="<?= $field['type'] === 'password' ? 'new-password' : 'off' ?>"
                                           <?= !empty($field['is_sensitive']) ? 'placeholder="Leave blank to keep the saved value"' : '' ?>>
                                <?php endif; ?>

                                <?php if (!empty($field['help'])): ?>
                                    <div class="form-text"><?= View::e($field['help']) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($field['is_sensitive']) && !empty($field['is_configured'])): ?>
                                    <div class="form-check form-check-inline mt-1">
                                        <input class="form-check-input" type="checkbox" value="1"
                                               id="clear_<?= View::e($name) ?>" name="__clear_<?= View::e($name) ?>">
                                        <label class="form-check-label small text-danger" for="clear_<?= View::e($name) ?>">
                                            Clear this value
                                        </label>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card-footer bg-white">
                    <button class="btn btn-success" type="submit">
                        <i class="bi bi-check2 me-1"></i>Save <?= View::e($group['label']) ?>
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= View::e(View::url('settings')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($groupKey === 'smtp' || $groupKey === 'sms'): ?>
<script>
(function () {
    var button = document.getElementById('btnTest');
    if (!button) { return; }
    var endpoint = <?= json_encode(View::url('settings/test/' . $groupKey)) ?>;
    var token = <?= json_encode(Csrf::token()) ?>;

    button.addEventListener('click', function () {
        var target = document.getElementById('testTarget').value.trim();
        var output = document.getElementById('testResult');
        output.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Testing...</span>';
        button.disabled = true;

        var body = new URLSearchParams();
        body.set('csrf_token', token);
        body.set('to', target);

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': token
            },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var css = payload.success ? 'text-success' : 'text-danger';
                var icon = payload.success ? 'check-circle-fill' : 'x-circle-fill';
                var html = '<div class="' + css + '"><i class="bi bi-' + icon + ' me-1"></i>' +
                    escapeHtml(payload.message) + '</div>';
                if (payload.data && payload.data.transcript && payload.data.transcript.length) {
                    html += '<pre class="bg-light border rounded p-2 mt-2 mb-0" style="max-height:180px;overflow:auto;font-size:.72rem">' +
                        escapeHtml(payload.data.transcript.join('\n')) + '</pre>';
                }
                if (payload.data && payload.data.gateway_response) {
                    html += '<pre class="bg-light border rounded p-2 mt-2 mb-0" style="max-height:140px;overflow:auto;font-size:.72rem">' +
                        escapeHtml(payload.data.gateway_response) + '</pre>';
                }
                output.innerHTML = html;
            })
            .catch(function (error) {
                output.innerHTML = '<div class="text-danger">Request failed: ' + escapeHtml(error.message) + '</div>';
            })
            .finally(function () { button.disabled = false; });
    });

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
})();
</script>
<?php endif; ?>
