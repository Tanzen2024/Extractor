<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<?php $isEdit = ($mode === 'edit'); ?>

<div class="row">
    <div class="col-md-8 col-lg-6">
        <?php if (session('errors')): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ((array) session('errors') as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title mb-0"><?= esc($title) ?></h3></div>
            <form method="post" action="<?= $isEdit ? site_url('admin/users/' . $user['id']) : site_url('admin/users') ?>">
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="form-group">
                        <label>Identifiant Active Directory (sAMAccountName)</label>
                        <input type="text" name="username" class="form-control"
                               value="<?= esc(old('username', $user['username'])) ?>" required
                               <?= $isEdit ? '' : 'autofocus' ?>>
                        <small class="form-text text-muted">
                            Le compte doit déjà exister dans l'AD. Créer ici n'ouvre qu'un accès applicatif.
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Nom affiché <span class="text-muted">(optionnel — sinon récupéré de l'AD à la connexion)</span></label>
                        <input type="text" name="display_name" class="form-control"
                               value="<?= esc(old('display_name', $user['display_name'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label>Rôles</label>
                        <?php $current = (array) old('roles', $userRoles); ?>
                        <?php foreach ($allRoles as $r): ?>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="role_<?= $r['id'] ?>"
                                       name="roles[]" value="<?= $r['id'] ?>"
                                       <?= in_array((string) $r['id'], array_map('strval', $current), true) ? 'checked' : '' ?>
                                       <?= (int) $r['is_active'] === 1 ? '' : 'disabled' ?>>
                                <label class="custom-control-label" for="role_<?= $r['id'] ?>">
                                    <code><?= esc($r['code']) ?></code> — <?= esc($r['name']) ?>
                                    <?= (int) $r['is_active'] === 1 ? '' : '<span class="badge badge-secondary">inactif</span>' ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                               <?= (int) old('is_active', $user['is_active']) === 1 ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="is_active">Compte actif</label>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save mr-1"></i> Enregistrer</button>
                    <a href="<?= site_url('admin/users') ?>" class="btn btn-default">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
