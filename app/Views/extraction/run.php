<?php
/**
 * @var array          $tool         Tool row (code, name, module_name, ...).
 * @var bool            $executed     Whether an extraction was just run.
 * @var list<string>    $columns     Result columns (only when $executed).
 * @var list<array>     $rows        Result rows (only when $executed).
 * @var string|null     $errorMessage
 */
?>
<?= $this->extend('layout/main') ?>

<?= $this->section('breadcrumb') ?>
<ol class="breadcrumb float-sm-right">
    <li class="breadcrumb-item"><a href="<?= site_url('dashboard') ?>">Accueil</a></li>
    <li class="breadcrumb-item"><a href="<?= site_url('extractor') ?>">EXTRACTOR</a></li>
    <li class="breadcrumb-item"><?= esc($tool['module_name']) ?></li>
    <li class="breadcrumb-item active"><?= esc($tool['name']) ?></li>
</ol>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="mb-3 d-flex flex-wrap justify-content-between align-items-center">
    <a href="<?= site_url('extractor') ?>" class="btn btn-outline-secondary btn-sm mb-2 mb-sm-0">
        <i class="fas fa-arrow-left mr-1"></i> Retour à EXTRACTOR
    </a>
</div>

<?php if (! $executed): ?>

    <div class="card">
        <div class="card-body text-center py-5">
            <i class="fas fa-database fa-2x text-muted mb-3"></i>
            <h5><?= esc($tool['name']) ?></h5>
            <p class="text-muted">Lance l'extraction pour interroger la base Oracle CMS.</p>

            <form action="<?= site_url(uri_string()) ?>" method="post" id="extractForm">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary" id="btnExtract">
                    <i class="fas fa-play mr-1"></i> Lancer l'extraction
                </button>
            </form>
        </div>
    </div>

<?php else: ?>

    <?php if (! empty($errorMessage)): ?>
        <div class="alert alert-danger"><?= esc($errorMessage) ?></div>
    <?php else: ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle mr-1"></i>
            Extraction réussie — <?= count($rows) ?> ligne(s) retournée(s).
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0"><?= esc($tool['name']) ?></h3>
            <form action="<?= site_url(uri_string()) ?>" method="post" id="extractForm" class="mb-0">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline-primary" id="btnExtract">
                    <i class="fas fa-rotate mr-1"></i> Relancer
                </button>
            </form>
        </div>
        <div class="card-body <?= empty($rows) ? '' : 'p-0' ?>">
            <?php if (empty($errorMessage) && empty($rows)): ?>
                <p class="text-muted text-center mb-0 py-4">Aucune donnée trouvée.</p>
            <?php elseif (! empty($rows)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <th><?= esc($column) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($columns as $column): ?>
                                        <td><?= esc((string) ($row[$column] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('extractForm')?.addEventListener('submit', function () {
    var btn = document.getElementById('btnExtract');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Extraction en cours...';
});
</script>
<?= $this->endSection() ?>
