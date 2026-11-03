<?= $this->extend('layout/main') ?>

<?= $this->section('breadcrumb') ?>
<ol class="breadcrumb float-sm-right">
    <li class="breadcrumb-item"><a href="<?= site_url('dashboard') ?>"><?= esc(lang('App.nav.home')) ?></a></li>
    <li class="breadcrumb-item active">EXTRACTOR</li>
</ol>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<p class="text-muted mb-4"><?= esc(lang('Extraction.chooseSource')) ?></p>

<div class="row <?= count($modules) === 1 ? 'justify-content-center' : '' ?>">
    <?php $columnClass = count($modules) === 1 ? 'col-lg-5 col-md-8' : 'col-lg-4 col-md-6'; ?>
    <?php foreach ($modules as $module): ?>
        <div class="<?= $columnClass ?> mb-4">
            <div class="card bscd-module-card h-100 text-center">
                <div class="card-header">
                    <i class="<?= esc($module['icon'], 'attr') ?> mr-2"></i>
                    <span class="font-weight-bold"><?= esc($module['name']) ?></span>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?= esc(lang('Extraction.moduleData', ['name' => $module['name']])) ?></p>

                    <?php if (empty($module['tools'])): ?>
                        <p class="text-muted mb-0"><?= esc(lang('Extraction.noTool')) ?></p>
                    <?php else: ?>
                        <?php foreach ($module['tools'] as $tool): ?>
                            <a href="<?= site_url($tool['route']) ?>" class="btn btn-primary btn-block">
                                <i class="<?= esc($tool['icon'], 'attr') ?> mr-1"></i> <?= esc($tool['name']) ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($modules)): ?>
        <div class="col-12">
            <div class="alert alert-info"><?= esc(lang('Extraction.noSource')) ?></div>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
