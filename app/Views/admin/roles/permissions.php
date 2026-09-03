<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card card-primary">
            <div class="card-header">
                <h3 class="card-title mb-0">Permissions du rôle <code><?= esc($role['code']) ?></code></h3>
            </div>
            <form method="post" action="<?= site_url('admin/users/roles/' . $role['id'] . '/permissions') ?>">
                <?= csrf_field() ?>
                <div class="card-body">
                    <?php $granted = array_map('strval', $granted); ?>
                    <?php foreach ($permissions as $p): ?>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="perm_<?= $p['id'] ?>"
                                   name="permissions[]" value="<?= $p['id'] ?>"
                                   <?= in_array((string) $p['id'], $granted, true) ? 'checked' : '' ?>
                                   <?= (int) $p['is_active'] === 1 ? '' : 'disabled' ?>>
                            <label class="custom-control-label" for="perm_<?= $p['id'] ?>">
                                <code><?= esc($p['code']) ?></code> — <?= esc($p['name']) ?>
                                <?= (int) $p['is_active'] === 1 ? '' : ' <span class="badge badge-secondary">inactive</span>' ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save mr-1"></i> Enregistrer</button>
                    <a href="<?= site_url('admin/users/roles') ?>" class="btn btn-default">Retour</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
