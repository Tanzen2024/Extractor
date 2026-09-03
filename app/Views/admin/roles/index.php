<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0">Rôles</h3>
        <?php if (user_can('ROLE_CREATE')): ?>
            <a href="<?= site_url('admin/users/roles/create') ?>" class="btn btn-primary btn-sm ml-auto">
                <i class="fas fa-plus mr-1"></i> Nouveau rôle
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover text-nowrap">
            <thead>
                <tr><th>Code</th><th>Nom</th><th>Description</th><th>Statut</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td><code><?= esc($r['code']) ?></code></td>
                    <td><?= esc($r['name']) ?></td>
                    <td class="text-muted"><?= esc($r['description'] ?? '') ?></td>
                    <td>
                        <?= (int) $r['is_active'] === 1
                            ? '<span class="badge badge-success">Actif</span>'
                            : '<span class="badge badge-secondary">Inactif</span>' ?>
                    </td>
                    <td class="text-right">
                        <?php if (user_can('ROLE_EDIT')): ?>
                            <a href="<?= site_url('admin/users/roles/' . $r['id'] . '/permissions') ?>" class="btn btn-xs btn-default"><i class="fas fa-key mr-1"></i>Permissions</a>
                            <a href="<?= site_url('admin/users/roles/' . $r['id'] . '/edit') ?>" class="btn btn-xs btn-default"><i class="fas fa-pen"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
