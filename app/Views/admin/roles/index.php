<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0"><?= esc(lang('Admin.roles.title')) ?></h3>
        <?php if (user_can('ROLE_CREATE')): ?>
            <a href="<?= site_url('admin/users/roles/create') ?>" class="btn btn-primary btn-sm ml-auto">
                <i class="fas fa-plus mr-1"></i> <?= esc(lang('Admin.roles.new')) ?>
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover text-nowrap">
            <thead>
                <tr><th><?= esc(lang('Admin.roles.code')) ?></th><th><?= esc(lang('Admin.roles.name')) ?></th><th><?= esc(lang('Admin.roles.description')) ?></th><th><?= esc(lang('Admin.roles.status')) ?></th><th class="text-right"><?= esc(lang('Admin.roles.actions')) ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td><code><?= esc($r['code']) ?></code></td>
                    <td><?= esc($r['name']) ?></td>
                    <td class="text-muted"><?= esc($r['description'] ?? '') ?></td>
                    <td>
                        <?= (int) $r['is_active'] === 1
                            ? '<span class="badge badge-success">' . esc(lang('App.status.active')) . '</span>'
                            : '<span class="badge badge-secondary">' . esc(lang('App.status.inactive')) . '</span>' ?>
                    </td>
                    <td class="text-right">
                        <?php if (user_can('ROLE_EDIT')): ?>
                            <a href="<?= site_url('admin/users/roles/' . $r['id'] . '/permissions') ?>" class="btn btn-xs btn-default"><i class="fas fa-key mr-1"></i><?= esc(lang('Admin.roles.permissions')) ?></a>
                            <a href="<?= site_url('admin/users/roles/' . $r['id'] . '/edit') ?>" class="btn btn-xs btn-default" title="<?= esc(lang('App.actions.edit'), 'attr') ?>" aria-label="<?= esc(lang('App.actions.edit'), 'attr') ?>"><i class="fas fa-pen"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
