<?php

/**
 * @var list<array<string,mixed>> $entries
 * @var array{total:int,page:int,perPage:int,pages:int} $meta
 * @var array<string,mixed> $filters
 * @var list<array<string,mixed>> $users
 * @var int $retention
 */

use App\Core\View;

$severityTone = ['info' => 'secondary', 'notice' => 'primary', 'warning' => 'warning', 'critical' => 'danger'];
?>

<div class="lrms-page-head">
    <div>
        <h1>Audit log</h1>
        <div class="lrms-page-sub">
            Append-only trail of every significant action. Secrets (OTPs, passwords, API keys) are
            masked before writing. Retention: <?= (int) $retention ?> days.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body pb-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label" for="search">Search</label>
                <input type="search" class="form-control form-control-sm" id="search" name="search"
                       value="<?= View::e($filters['search']) ?>" placeholder="Action, actor, description or entity id">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="from">From</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from"
                       value="<?= View::e($filters['from']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="to">To</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to"
                       value="<?= View::e($filters['to']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="severity">Severity</label>
                <select class="form-select form-select-sm" id="severity" name="severity">
                    <option value="">All</option>
                    <?php foreach (['info', 'notice', 'warning', 'critical'] as $severity): ?>
                        <option value="<?= View::e($severity) ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>>
                            <?= View::e(ucfirst($severity)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="channel">Channel</label>
                <select class="form-select form-select-sm" id="channel" name="channel">
                    <option value="">All</option>
                    <?php foreach (['web', 'api', 'cron', 'cli'] as $channel): ?>
                        <option value="<?= View::e($channel) ?>" <?= $filters['channel'] === $channel ? 'selected' : '' ?>>
                            <?= View::e(strtoupper($channel)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-success w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <?php if ($entries === []): ?>
        <div class="lrms-empty"><i class="bi bi-shield-check"></i>No audit entries match these filters.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-lrms table-hover align-middle">
                <thead>
                <tr>
                    <th>When</th><th>Actor</th><th>Action</th><th>Entity</th>
                    <th>Description</th><th>Channel</th><th>Severity</th><th>IP</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $id = (int) $entry['id'];
                    $hasDiff = !empty($entry['old_values']) || !empty($entry['new_values']);
                    ?>
                    <tr>
                        <td class="small text-nowrap"><?= View::e(View::dateTime($entry['created_at'])) ?></td>
                        <td class="small"><?= View::e($entry['actor_name'] ?? 'system') ?></td>
                        <td class="small"><code><?= View::e($entry['action']) ?></code></td>
                        <td class="small text-muted">
                            <?= View::e($entry['entity_type'] ?? '-') ?>
                            <?php if (!empty($entry['entity_id'])): ?>
                                #<?= View::e($entry['entity_id']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="small" style="max-width:320px"><?= View::e($entry['description'] ?? '-') ?></td>
                        <td class="small"><?= View::e(strtoupper((string) $entry['channel'])) ?></td>
                        <td>
                            <span class="badge badge-soft bg-<?= View::e($severityTone[$entry['severity']] ?? 'secondary') ?>">
                                <?= View::e(ucfirst((string) $entry['severity'])) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= View::e($entry['ip_address'] ?? '-') ?></td>
                        <td class="text-end">
                            <?php if ($hasDiff): ?>
                                <button class="btn btn-sm btn-outline-secondary" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#audit<?= $id ?>"
                                        aria-expanded="false" aria-controls="audit<?= $id ?>">
                                    <i class="bi bi-braces"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($hasDiff): ?>
                        <tr class="collapse" id="audit<?= $id ?>">
                            <td colspan="9" class="bg-light">
                                <div class="row g-2 small">
                                    <div class="col-md-6">
                                        <div class="fw-semibold mb-1">Before</div>
                                        <pre class="mb-0 bg-white border rounded p-2"
                                             style="font-size:.72rem;max-height:180px;overflow:auto"><?= View::e((string) ($entry['old_values'] ?? '{}')) ?></pre>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="fw-semibold mb-1">After</div>
                                        <pre class="mb-0 bg-white border rounded p-2"
                                             style="font-size:.72rem;max-height:180px;overflow:auto"><?= View::e((string) ($entry['new_values'] ?? '{}')) ?></pre>
                                    </div>
                                    <?php if (!empty($entry['user_agent'])): ?>
                                        <div class="col-12 text-muted">
                                            User agent: <?= View::e($entry['user_agent']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::capture('partials/pagination', ['meta' => $meta]) ?>
    <?php endif; ?>
</div>
