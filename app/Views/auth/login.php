<?= $this->extend('layout/guest') ?>

<?= $this->section('content') ?>

<form action="<?= site_url('login') ?>" method="post">
    <?= csrf_field() ?>

    <div class="input-group mb-3">
        <input type="text" name="username" class="form-control" placeholder="Identifiant"
               value="<?= esc(old('username')) ?>" required autofocus>
        <div class="input-group-append">
            <div class="input-group-text"><span class="fas fa-user"></span></div>
        </div>
    </div>

    <div class="input-group mb-3">
        <input type="password" name="password" class="form-control" placeholder="Mot de passe" required>
        <div class="input-group-append">
            <div class="input-group-text"><span class="fas fa-lock"></span></div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <button type="submit" class="btn btn-primary btn-block">SE CONNECTER</button>
        </div>
    </div>
</form>

<?= $this->endSection() ?>
