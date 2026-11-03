<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header"><h3 class="card-title mb-0"><?= esc(lang('Admin.permissions.catalog')) ?></h3></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover text-nowrap">
            <thead>
                <tr><th><?= esc(lang('Admin.permissions.code')) ?></th><th><?= esc(lang('Admin.permissions.name')) ?></th><th><?= esc(lang('Admin.permissions.description')) ?></th><th><?= esc(lang('Admin.permissions.status')) ?></th><th class="text-right"><?= esc(lang('Admin.permissions.action')) ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($permissions as $p): ?>
                <tr>
                    <td><code><?= esc($p['code']) ?></code></td>
                    <td><?= esc($p['name']) ?></td>
                    <td class="text-muted"><?= esc($p['description'] ?? '') ?></td>
                    <td>
                        <?= (int) $p['is_active'] === 1
                            ? '<span class="badge badge-success">' . esc(lang('App.status.activeFeminine')) . '</span>'
                            : '<span class="badge badge-secondary">' . esc(lang('App.status.inactiveFeminine')) . '</span>' ?>
                    </td>
                    <td class="text-right">
                        <?php if (user_can('PERMISSION_EDIT')): ?>
                            <form method="post" action="<?= site_url('admin/users/permissions/' . $p['id'] . '/toggle') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-xs btn-default">
                                    <?= esc(lang((int) $p['is_active'] === 1 ? 'App.actions.disable' : 'App.actions.enable')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        <?= lang('Admin.permissions.howToAssign') ?>
    </div>
</div>

<?= $this->endSection() ?>
