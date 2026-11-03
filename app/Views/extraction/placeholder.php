<?= $this->extend('layout/main') ?>

<?= $this->section('breadcrumb') ?>
<ol class="breadcrumb float-sm-right">
    <li class="breadcrumb-item"><a href="<?= site_url('dashboard') ?>"><?= esc(lang('App.nav.home')) ?></a></li>
    <li class="breadcrumb-item"><?= esc($tool['module_name']) ?></li>
    <li class="breadcrumb-item active"><?= esc($tool['name']) ?></li>
</ol>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-tools fa-3x text-muted mb-3"></i>
        <h5><?= esc($tool['name']) ?></h5>
        <p class="text-muted">
            <?= esc(lang('Extraction.placeholder')) ?><br>
            <?= esc(lang('Extraction.placeholderHint')) ?>
        </p>
    </div>
</div>

<?= $this->endSection() ?>
