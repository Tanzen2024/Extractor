<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0"><?= esc(lang('Admin.users.listTitle')) ?></h3>
        <?php if (user_can('USER_CREATE')): ?>
            <a href="<?= site_url('admin/users/create') ?>" class="btn btn-primary btn-sm ml-auto">
                <i class="fas fa-plus mr-1"></i> <?= esc(lang('Admin.users.authorize')) ?>
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover text-nowrap">
            <thead>
                <tr>
                    <th><?= esc(lang('Admin.users.adUsername')) ?></th>
                    <th><?= esc(lang('Admin.users.displayName')) ?></th>
                    <th><?= esc(lang('Admin.users.roles')) ?></th>
                    <th><?= esc(lang('Admin.users.status')) ?></th>
                    <th class="text-right"><?= esc(lang('Admin.users.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($users === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= esc(lang('Admin.users.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><code><?= esc($u['username']) ?></code></td>
                    <td><?= esc($u['display_name'] ?? '—') ?></td>
                    <td><?= esc($u['role_names'] ?? '—') ?></td>
                    <td>
                        <?php if ((int) $u['is_active'] === 1): ?>
                            <span class="badge badge-success"><?= esc(lang('App.status.active')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary"><?= esc(lang('App.status.disabled')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if (user_can('USER_VIEW')): ?>
                            <a href="<?= site_url('admin/users/' . $u['id']) ?>" class="btn btn-xs btn-default" title="<?= esc(lang('Admin.users.viewPermissions'), 'attr') ?>" aria-label="<?= esc(lang('Admin.users.viewPermissions'), 'attr') ?>"><i class="fas fa-eye"></i></a>
                        <?php endif; ?>
                        <?php if (user_can('USER_EDIT')): ?>
                            <a href="<?= site_url('admin/users/' . $u['id'] . '/edit') ?>" class="btn btn-xs btn-default" title="<?= esc(lang('App.actions.edit'), 'attr') ?>" aria-label="<?= esc(lang('App.actions.edit'), 'attr') ?>"><i class="fas fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (user_can('USER_DISABLE')): ?>
                            <?php $toggle = (int) $u['is_active'] === 1 ? 'disable' : 'enable'; ?>
                            <form method="post" action="<?= site_url('admin/users/' . $u['id'] . '/' . $toggle) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-xs <?= $toggle === 'disable' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= esc(lang($toggle === 'disable' ? 'App.actions.disable' : 'App.actions.reactivate')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
