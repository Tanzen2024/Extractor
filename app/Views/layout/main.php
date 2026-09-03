<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= csrf_token() ?>">
    <meta name="csrf-token-value" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? 'Tableau de bord') ?> — <?= esc(config('App')->appName) ?></title>

    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/fontawesome/css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/adminlte/adminlte.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/custom.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <span class="nav-link app-brand-header"><?= esc(config('App')->appName) ?></span>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="fas fa-user-circle mr-1"></i>
                    <?= esc(session('display_name') ?? session('username')) ?>
                    <?php $navRoles = (array) (session('roles') ?? []); ?>
                    <span class="badge badge-secondary ml-1"><?= esc($navRoles[0] ?? '—') ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="<?= site_url('logout') ?>" class="dropdown-item">
                        <i class="fas fa-sign-out-alt mr-2"></i> Déconnexion
                    </a>
                </div>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="<?= site_url('dashboard') ?>" class="brand-link">
            <i class="fas fa-database brand-icon"></i>
            <span class="brand-text font-weight-bold">MARKETING (MI)</span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <?php
                    $uri            = uri_string();
                    $canUsers       = user_can('USER_VIEW');
                    $canRoles       = user_can('ROLE_VIEW');
                    $canPermissions = user_can('PERMISSION_VIEW');
                    $canAudit       = user_can('AUDIT_VIEW');
                ?>
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="<?= site_url('dashboard') ?>" class="nav-link <?= ($uri === 'dashboard' || $uri === '') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-th-large"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <?php if ($canUsers || $canRoles || $canPermissions || $canAudit): ?>
                    <li class="nav-header">ADMINISTRATION</li>

                    <?php if ($canUsers): ?>
                    <li class="nav-item">
                        <a href="<?= site_url('admin/users') ?>" class="nav-link <?= ($uri === 'admin/users' || preg_match('#^admin/users/\d#', $uri)) ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-user"></i>
                            <p>Utilisateur</p>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($canRoles): ?>
                    <li class="nav-item">
                        <a href="<?= site_url('admin/users/roles') ?>" class="nav-link <?= str_starts_with($uri, 'admin/users/roles') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-user-shield"></i>
                            <p>Rôles</p>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($canPermissions): ?>
                    <li class="nav-item">
                        <a href="<?= site_url('admin/users/permissions') ?>" class="nav-link <?= str_starts_with($uri, 'admin/users/permissions') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Permissions</p>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($canAudit): ?>
                    <li class="nav-item">
                        <a href="<?= site_url('admin/audit') ?>" class="nav-link <?= str_starts_with($uri, 'admin/audit') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-clipboard-list"></i>
                            <p>Audit</p>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </aside>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><?= esc($title ?? '') ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <?= $this->renderSection('breadcrumb') ?>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <?php if (session()->getFlashdata('success')): ?>
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                        <?= esc(session()->getFlashdata('success')) ?>
                    </div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                        <?= esc(session()->getFlashdata('error')) ?>
                    </div>
                <?php endif; ?>

                <?= $this->renderSection('content') ?>
            </div>
        </section>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline-block">
            Version 0.1.0 — Phase 1
        </div>
        <strong>&copy; <?= date('Y') ?> MARKETING (MI).</strong> Tous droits réservés.
    </footer>
</div>

<script src="<?= base_url('assets/vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/frdatepicker.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
