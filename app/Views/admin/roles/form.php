<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<?php $isEdit = ($mode === 'edit'); ?>

<div class="row">
    <div class="col-md-8 col-lg-6">
        <?php if (session('errors')): ?>
            <div class="alert alert-danger">
                <ul class="mb-0"><?php foreach ((array) session('errors') as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title mb-0"><?= esc($title) ?></h3></div>
            <form method="post" action="<?= $isEdit ? site_url('admin/users/roles/' . $role['id']) : site_url('admin/users/roles') ?>">
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="form-group">
                        <label>Code <span class="text-muted">(MAJUSCULES, chiffres, _)</span></label>
                        <input type="text" name="code" class="form-control text-uppercase"
                               value="<?= esc(old('code', $role['code'])) ?>" required pattern="[A-Z0-9_]+">
                    </div>
                    <div class="form-group">
                        <label>Nom</label>
                        <input type="text" name="name" class="form-control" value="<?= esc(old('name', $role['name'])) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= esc(old('description', $role['description'] ?? '')) ?></textarea>
                    </div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                               <?= (int) old('is_active', $role['is_active']) === 1 ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="is_active">Rôle actif</label>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save mr-1"></i> Enregistrer</button>
                    <a href="<?= site_url('admin/users/roles') ?>" class="btn btn-default">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
