<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header"><h3 class="card-title mb-0">Journal d'audit</h3></div>
    <div class="card-body">
        <form method="get" class="form-row align-items-end mb-3">
            <div class="col-md-3 mb-2">
                <label class="mb-0 small">Utilisateur</label>
                <input type="text" name="username" class="form-control form-control-sm" value="<?= esc($filters['username']) ?>">
            </div>
            <div class="col-md-3 mb-2">
                <label class="mb-0 small">Action</label>
                <select name="action" class="form-control form-control-sm">
                    <option value="">— toutes —</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= esc($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= esc($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small" for="auditDateFrom_d">Du</label>
                <span class="frdate" data-frdate>
                    <input type="text" class="frdate__input form-control form-control-sm" id="auditDateFrom_d"
                           placeholder="jj/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off">
                    <button type="button" class="frdate__toggle" tabindex="-1" aria-label="Ouvrir le calendrier">
                        <i class="fas fa-calendar-day" aria-hidden="true"></i>
                    </button>
                    <input type="hidden" name="date_from" value="<?= esc($filters['date_from']) ?>">
                    <span class="frdate__error" role="alert" hidden></span>
                </span>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small" for="auditDateTo_d">Au</label>
                <span class="frdate" data-frdate>
                    <input type="text" class="frdate__input form-control form-control-sm" id="auditDateTo_d"
                           placeholder="jj/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off">
                    <button type="button" class="frdate__toggle" tabindex="-1" aria-label="Ouvrir le calendrier">
                        <i class="fas fa-calendar-day" aria-hidden="true"></i>
                    </button>
                    <input type="hidden" name="date_to" value="<?= esc($filters['date_to']) ?>">
                    <span class="frdate__error" role="alert" hidden></span>
                </span>
            </div>
            <div class="col-md-2 mb-2">
                <button class="btn btn-sm btn-primary btn-block">Filtrer</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped text-nowrap">
                <thead>
                    <tr>
                        <th>Date</th><th>Utilisateur</th><th>Action</th><th>Module</th>
                        <th>Cible</th><th>Description</th><th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucune entrée.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc(format_datetime_fr($row['created_at'])) ?></td>
                        <td><?= esc($row['username'] ?? '—') ?></td>
                        <td>
                            <?php $ok = ! str_contains((string) $row['action'], 'FAILURE'); ?>
                            <span class="badge badge-<?= $ok ? 'light border' : 'danger' ?>"><?= esc($row['action']) ?></span>
                        </td>
                        <td><?= esc($row['module'] ?? '—') ?></td>
                        <td><?= esc(trim(($row['target_type'] ?? '') . ' ' . ($row['target_id'] ?? ''))) ?: '—' ?></td>
                        <td class="text-muted"><?= esc($row['description'] ?? '') ?></td>
                        <td><small><?= esc($row['ip_address'] ?? '') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $pager ? $pager->links() : '' ?>
    </div>
</div>

<?= $this->endSection() ?>
