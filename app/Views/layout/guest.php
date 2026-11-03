<!DOCTYPE html>
<html lang="<?= esc(current_locale(), 'attr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? lang('Auth.loginTitle')) ?> — <?= esc(config('App')->appName) ?></title>

    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/fontawesome/css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/adminlte/adminlte.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/custom.css') ?>?v=<?= @filemtime(FCPATH . 'assets/css/custom.css') ?: 0 ?>">
</head>
<body class="hold-transition login-page bscd-login-page">
<?= $this->include('partials/language_switcher', ['variant' => 'floating']) ?>
<div class="login-box">
    <div class="login-logo">
        <i class="fas fa-database brand-icon"></i>
        <span class="font-weight-bold"><?= esc(config('App')->appName) ?></span>
        <p class="login-tagline"><?= esc(lang('App.tagline')) ?></p>
    </div>

    <div class="card card-outline card-primary">
        <div class="card-body login-card-body">
            <p class="login-box-msg"><?= esc(lang('Auth.loginIntro')) ?></p>

            <?php if (session()->getFlashdata('error')) : ?>
                <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('success')) : ?>
                <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
            <?php endif; ?>

            <?= $this->renderSection('content') ?>
        </div>
    </div>
</div>

<script src="<?= base_url('assets/vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
