<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="row">
    <div class="col-md-4">
        <div class="card card-widget widget-user-2">
            <div class="card-body">
                <h4 class="mb-1"><?= esc($user['display_name'] ?: $user['username']) ?></h4>
                <p class="text-muted mb-2"><code><?= esc($user['username']) ?></code></p>
                <p>
                    <?php if ((int) $user['is_active'] === 1): ?>
                        <span class="badge badge-success"><?= esc(lang('App.status.active')) ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary"><?= esc(lang('App.status.disabled')) ?></span>
                    <?php endif; ?>
                </p>
                <?php if (user_can('USER_EDIT')): ?>
                    <a href="<?= site_url('admin/users/' . $user['id'] . '/edit') ?>" class="btn btn-sm btn-default">
                        <i class="fas fa-pen mr-1"></i> <?= esc(lang('App.actions.edit')) ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0"><?= esc(lang('Admin.users.roles')) ?></h3></div>
            <div class="card-body">
                <?php foreach ($roles as $r): ?><span class="badge badge-info mr-1"><?= esc($r) ?></span><?php endforeach; ?>
                <?= $roles === [] ? '<span class="text-muted">' . esc(lang('Admin.users.noRole')) . '</span>' : '' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0"><?= esc(lang('Admin.users.effectivePermissions')) ?></h3></div>
            <div class="card-body">
                <?php foreach ($permissions as $p): ?><span class="badge badge-light border mr-1 mb-1"><?= esc($p) ?></span><?php endforeach; ?>
                <?= $permissions === [] ? '<span class="text-muted">' . esc(lang('Admin.users.noPermission')) . '</span>' : '' ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
