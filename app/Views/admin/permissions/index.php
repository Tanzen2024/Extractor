<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header"><h3 class="card-title mb-0">Catalogue des permissions</h3></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover text-nowrap">
            <thead>
                <tr><th>Code</th><th>Nom</th><th>Description</th><th>Statut</th><th class="text-right">Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($permissions as $p): ?>
                <tr>
                    <td><code><?= esc($p['code']) ?></code></td>
                    <td><?= esc($p['name']) ?></td>
                    <td class="text-muted"><?= esc($p['description'] ?? '') ?></td>
                    <td>
                        <?= (int) $p['is_active'] === 1
                            ? '<span class="badge badge-success">Active</span>'
                            : '<span class="badge badge-secondary">Inactive</span>' ?>
                    </td>
                    <td class="text-right">
                        <?php if (user_can('PERMISSION_EDIT')): ?>
                            <form method="post" action="<?= site_url('admin/users/permissions/' . $p['id'] . '/toggle') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-xs btn-default">
                                    <?= (int) $p['is_active'] === 1 ? 'Désactiver' : 'Activer' ?>
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
        Associer une permission à un rôle se fait depuis <strong>Rôles &rsaquo; Permissions</strong>.
    </div>
</div>

<?= $this->endSection() ?>
