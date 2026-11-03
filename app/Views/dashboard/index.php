<?php
/**
 * CUSTOMERS_LIST analytics dashboard — shell only.
 *
 * @var array $bootstrap endpoints + static config; every figure is fetched
 *                       client-side (see public/assets/js/dashboard.js).
 */
?>
<?= $this->extend('layout/main') ?>

<?= $this->section('page_heading') ?>
<h1 class="bscd-seg-title">
    <span class="bscd-seg-title__item bscd-seg-title__item--payer">
        <span class="bscd-seg-title__icon" aria-hidden="true"><i class="fas fa-wallet"></i></span>
        <span class="bscd-seg-title__text">
            <span class="bscd-seg-title__eyebrow"><?= esc(lang('Glossary.segmentation')) ?></span>
            <span class="bscd-seg-title__label"><?= esc(lang('Glossary.payerProfile')) ?></span>
        </span>
    </span>
    <span class="bscd-seg-title__divider" aria-hidden="true"></span>
    <span class="sr-only"> | </span>
    <span class="bscd-seg-title__item bscd-seg-title__item--consumer">
        <span class="bscd-seg-title__icon" aria-hidden="true"><i class="fas fa-bolt"></i></span>
        <span class="bscd-seg-title__text">
            <span class="bscd-seg-title__eyebrow"><?= esc(lang('Glossary.segmentation')) ?></span>
            <span class="bscd-seg-title__label"><?= esc(lang('Glossary.consumerProfile')) ?></span>
        </span>
    </span>
</h1>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="bscd-dash-header">
    <div class="bscd-dash-actions">
        <span class="bscd-cache-note" id="bscdCacheNote"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="bscdRefresh">
            <i class="fas fa-rotate mr-1"></i> <?= esc(lang('App.actions.refresh')) ?>
        </button>
    </div>
</div>

<div class="bscd-alert bscd-alert--error d-none" id="bscdGlobalError">
    <div>
        <strong><?= esc(lang('Dashboard.loadError')) ?></strong>
        <span id="bscdGlobalErrorRef"></span>
    </div>
    <button type="button" class="btn btn-sm btn-outline-danger" id="bscdRetry"><?= esc(lang('App.actions.retry')) ?></button>
</div>

<!-- ── Filtres ─────────────────────────────────────────────── -->
<form class="bscd-filters" id="bscdFilters" autocomplete="off" aria-labelledby="bscdFiltersTitle">
    <h2 class="bscd-filters__title" id="bscdFiltersTitle">
        <i class="fas fa-sliders" aria-hidden="true"></i> <?= esc(lang('Dashboard.filters.title')) ?>
    </h2>
    <?php // One 7-column grid, in the business order: line 1 = Région … Tension,
          // line 2 = Qualité NUI … Date fin abonnement (the dates span 2 columns).
          // Narrower screens re-flow the same order. ?>
    <div class="bscd-filters__grid bscd-filters__grid--main">
        <div class="bscd-filter-group" data-ms="region">
            <label><?= esc(lang('Glossary.region')) ?></label>
            <div class="bscd-ms" data-dim="region"></div>
        </div>
        <div class="bscd-filter-group" data-ms="division">
            <label><?= esc(lang('Glossary.division')) ?></label>
            <div class="bscd-ms" data-dim="division"></div>
        </div>
        <div class="bscd-filter-group" data-ms="agence">
            <label><?= esc(lang('Glossary.agency')) ?></label>
            <div class="bscd-ms" data-dim="agence"></div>
        </div>
        <div class="bscd-filter-group" data-ms="meter">
            <label><?= esc(lang('Glossary.meterType')) ?></label>
            <div class="bscd-ms" data-dim="meter"></div>
        </div>
        <div class="bscd-filter-group" data-ms="segmentation">
            <label><?= esc(lang('Glossary.segmentation')) ?></label>
            <div class="bscd-ms" data-dim="segmentation"></div>
        </div>
        <div class="bscd-filter-group" data-ms="segment_tresor">
            <label><?= esc(lang('Glossary.treasurySegment')) ?></label>
            <div class="bscd-ms" data-dim="segment_tresor"></div>
        </div>
        <div class="bscd-filter-group" data-ms="voltage">
            <label><?= esc(lang('Glossary.voltage')) ?></label>
            <div class="bscd-ms" data-dim="voltage"></div>
        </div>
        <div class="bscd-filter-group" data-ms="niu_qc">
            <label><?= esc(lang('Glossary.niuQuality')) ?></label>
            <div class="bscd-ms" data-dim="niu_qc"></div>
        </div>
        <div class="bscd-filter-group" data-ms="contact">
            <label><?= esc(lang('Glossary.contactFilled')) ?></label>
            <div class="bscd-ms" data-dim="contact"></div>
        </div>
        <div class="bscd-filter-group" data-ms="status">
            <label><?= esc(lang('Glossary.status')) ?></label>
            <div class="bscd-ms" data-dim="status"></div>
        </div>
        <div class="bscd-filter-group bscd-filter-group--wide">
            <label for="bscdDateFrom_d"><?= esc(lang('Dashboard.filters.dateFrom')) ?></label>
            <span class="frdate" data-frdate>
                <input type="text" class="frdate__input" id="bscdDateFrom_d" placeholder="<?= esc(lang('App.datepicker.placeholder'), 'attr') ?>"
                       inputmode="numeric" maxlength="10" autocomplete="off" aria-describedby="bscdDateFrom_e">
                <button type="button" class="frdate__toggle" tabindex="-1" aria-label="<?= esc(lang('App.datepicker.openCalendar'), 'attr') ?>">
                    <i class="fas fa-calendar-day" aria-hidden="true"></i>
                </button>
                <input type="hidden" id="bscdDateFrom" name="date_from">
                <span class="frdate__error" id="bscdDateFrom_e" role="alert" hidden></span>
            </span>
        </div>
        <div class="bscd-filter-group bscd-filter-group--wide">
            <label for="bscdDateTo_d"><?= esc(lang('Dashboard.filters.dateTo')) ?></label>
            <span class="frdate" data-frdate>
                <input type="text" class="frdate__input" id="bscdDateTo_d" placeholder="<?= esc(lang('App.datepicker.placeholder'), 'attr') ?>"
                       inputmode="numeric" maxlength="10" autocomplete="off" aria-describedby="bscdDateTo_e">
                <button type="button" class="frdate__toggle" tabindex="-1" aria-label="<?= esc(lang('App.datepicker.openCalendar'), 'attr') ?>">
                    <i class="fas fa-calendar-day" aria-hidden="true"></i>
                </button>
                <input type="hidden" id="bscdDateTo" name="date_to">
                <span class="frdate__error" id="bscdDateTo_e" role="alert" hidden></span>
            </span>
        </div>
    </div>

    <div class="bscd-filters__actions">
        <div class="ml-auto d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="bscdReset"><?= esc(lang('App.actions.reset')) ?></button>
            <button type="submit" class="btn btn-primary btn-sm" id="bscdApply">
                <i class="fas fa-filter mr-1"></i> <?= esc(lang('App.actions.apply')) ?>
            </button>
        </div>
    </div>
</form>

<div class="bscd-chips" id="bscdChips"></div>

<!-- ── Vues Postpaid / Prepaid ───────────────────────────────
     One section per METER universe (public/assets/js/dashboard-sections.js):
     the same KPI cards and charts, each fed by /dashboard/stats with the
     page filters narrowed to its universe. -->
<?php
$kpiCards = [
    // No "Total clients" card: every figure covers active customers only
    // (App\Services\CustomersList\ActiveCustomers), so it would always equal
    // "Clients actifs". col-lg (Bootstrap 4 equal flex columns): the 3 cards
    // share one desktop line; below lg they wrap as before.
    ['actifs', 'Dashboard.kpi.actifs', 'fa-circle-check', 'green'],
    ['nuiCorrects', 'Dashboard.kpi.nuiCorrects', 'fa-id-card', 'blue'],
    ['contacts', 'Dashboard.kpi.contacts', 'fa-address-book', 'orange'],
];
// Charts per section. "Type de compteurs" only in Postpaid (POSTPAID vs
// Compteurs communicants); in Prepaid it would always read 100 % PREPAID.
$sections = [
    'postpaid' => ['icon' => 'fa-file-invoice', 'title' => 'Dashboard.sections.postpaid.title', 'subtitle' => 'Dashboard.sections.postpaid.subtitle',
                   'charts' => ['region', 'status', 'segmentation', 'meterType']],
    'prepaid'  => ['icon' => 'fa-credit-card', 'title' => 'Dashboard.sections.prepaid.title', 'subtitle' => 'Dashboard.sections.prepaid.subtitle',
                   'charts' => ['region', 'status', 'segmentation']],
];
$chartTitles = [
    'region'       => 'Dashboard.charts.byRegion',
    'status'       => 'Dashboard.charts.byStatus',
    'segmentation' => 'Dashboard.charts.bySegmentation',
    'meterType'    => 'Dashboard.charts.meterTypes',
];
?>
<nav class="bscd-section-nav" aria-label="<?= esc(lang('Dashboard.sections.nav'), 'attr') ?>">
    <?php foreach ($sections as $sk => $sec): ?>
    <a class="bscd-section-nav__link bscd-section-nav__link--<?= $sk ?>" href="#bscdSection-<?= $sk ?>">
        <i class="fas <?= $sec['icon'] ?>" aria-hidden="true"></i>
        <span><?= esc(lang($sec['title'])) ?></span>
        <span class="bscd-section-nav__count" data-section-count="<?= $sk ?>"></span>
    </a>
    <?php endforeach; ?>
    <a class="bscd-section-nav__link bscd-section-nav__link--detail" href="#bscdSection-detail">
        <i class="fas fa-table-list" aria-hidden="true"></i>
        <span><?= esc(lang('Dashboard.sections.detail.title')) ?></span>
    </a>
    <span class="bscd-section-nav__note" data-section-unassigned hidden></span>
</nav>

<?php foreach ($sections as $sk => $sec): ?>
<section class="bscd-section bscd-section--<?= $sk ?>" id="bscdSection-<?= $sk ?>" data-section="<?= $sk ?>"
         data-export-label="<?= esc(lang($sec['title']), 'attr') ?>" data-export-prefix="<?= $sk ?>"
         aria-labelledby="bscdSectionTitle-<?= $sk ?>">
    <header class="bscd-section__header">
        <span class="bscd-section__icon" aria-hidden="true"><i class="fas <?= $sec['icon'] ?>"></i></span>
        <div class="bscd-section__heading">
            <h2 class="bscd-section__title" id="bscdSectionTitle-<?= $sk ?>"><?= esc(lang($sec['title'])) ?></h2>
            <p class="bscd-section__subtitle"><?= esc(lang($sec['subtitle'])) ?></p>
        </div>
        <span class="bscd-section__badge" data-section-total></span>
    </header>

    <h3 class="bscd-kpi-heading"><?= esc(lang('Dashboard.kpi.heading')) ?></h3>
    <div class="row bscd-kpi-row">
    <?php foreach ($kpiCards as [$key, $label, $icon, $color]): ?>
        <div class="col-12 col-sm-6 col-lg mb-3" data-kpi-col="<?= $key ?>">
            <div class="bscd-kpi-card" data-kpi-card="<?= $key ?>">
                <div class="bscd-kpi-icon bscd-kpi-icon--<?= $color ?>"><i class="fas <?= $icon ?>"></i></div>
                <div class="bscd-kpi-body">
                    <div class="bscd-kpi-label"><?= esc(lang($label)) ?></div>
                    <div class="bscd-kpi-value" data-kpi="<?= $key ?>"><span class="bscd-skeleton bscd-skeleton--text"></span></div>
                    <div class="bscd-kpi-sub" data-kpi-sub="<?= $key ?>">&nbsp;</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="row">
    <?php foreach ($sec['charts'] as $i => $ck):
        // An odd last chart takes the whole line.
        $wide = $i === count($sec['charts']) - 1 && count($sec['charts']) % 2 === 1; ?>
        <div class="col-12<?= $wide ? '' : ' col-lg-6' ?> mb-3">
            <div class="bscd-chart-card">
                <div class="bscd-chart-card__header">
                    <h3 class="bscd-chart-card__title"><?= esc(lang($chartTitles[$ck])) ?></h3>
                    <?php if ($ck === 'meterType'): ?>
                    <div class="bscd-chart-card__subtitle"><?= esc(lang('Dashboard.charts.meterTypesHint')) ?></div>
                    <?php endif; ?>
                    <div class="bscd-cx" data-chart-export="<?= $sk ?>-<?= $ck ?>"></div>
                </div>
                <?php if ($ck === 'meterType'): ?>
                <div class="bscd-chart-card__body" data-chart-wrap="meterType">
                    <div class="bscd-metertype">
                        <div class="bscd-metertype__total">
                            <span class="bscd-metertype__total-label"><?= esc(lang('Dashboard.charts.meterTotal')) ?></span>
                            <span class="bscd-metertype__total-value" data-metertype="total"><span class="bscd-skeleton bscd-skeleton--text"></span></span>
                        </div>
                        <ul class="bscd-metertype__list" data-metertype="list"></ul>
                    </div>
                </div>
                <?php else: ?>
                <div class="bscd-chart-card__body<?= $ck === 'status' ? ' bscd-donut-wrap' : '' ?>" data-chart-wrap="<?= $ck ?>"><canvas id="bscdChart_<?= $sk ?>_<?= $ck ?>" height="240"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<!-- ── Détail des clients: one table, scoped (Tous / Postpaid / Prepaid) ── -->
<section class="bscd-section bscd-section--detail" id="bscdSection-detail" aria-labelledby="bscdSectionTitle-detail">
    <header class="bscd-section__header">
        <span class="bscd-section__icon" aria-hidden="true"><i class="fas fa-table-list"></i></span>
        <div class="bscd-section__heading">
            <h2 class="bscd-section__title" id="bscdSectionTitle-detail"><?= esc(lang('Dashboard.sections.detail.title')) ?></h2>
            <p class="bscd-section__subtitle"><?= esc(lang('Dashboard.sections.detail.subtitle')) ?></p>
        </div>
        <div class="bscd-scope" role="group" aria-label="<?= esc(lang('Dashboard.sections.scope.label'), 'attr') ?>">
            <?php foreach (['all' => 'Dashboard.sections.scope.all', 'postpaid' => 'Dashboard.sections.scope.postpaid', 'prepaid' => 'Dashboard.sections.scope.prepaid'] as $scope => $scopeLabel): ?>
            <button type="button" class="bscd-scope__btn<?= $scope === 'all' ? ' is-active' : '' ?>" data-table-scope="<?= $scope ?>" aria-pressed="<?= $scope === 'all' ? 'true' : 'false' ?>"><?= esc(lang($scopeLabel)) ?></button>
            <?php endforeach; ?>
        </div>
    </header>

<!-- ── Table ───────────────────────────────────────────────── -->
<div class="bscd-table-card">
    <div class="bscd-table-toolbar">
        <div class="bscd-table-tools">
            <div class="bscd-table-count" id="bscdRowCount"><span class="bscd-skeleton bscd-skeleton--text"></span></div>
            <div class="bscd-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" id="bscdSearch" placeholder="<?= esc(lang('Dashboard.table.search'), 'attr') ?>" aria-label="<?= esc(lang('Dashboard.table.search'), 'attr') ?>" maxlength="80">
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-toggle="dropdown" id="bscdColumnsBtn">
                    <i class="fas fa-table-columns mr-1"></i> <?= esc(lang('Dashboard.table.columns')) ?>
                </button>
                <div class="dropdown-menu dropdown-menu-right bscd-columns-menu" id="bscdColumnsMenu"></div>
            </div>
            <select id="bscdPerPage" class="bscd-perpage">
                <?php foreach ($bootstrap['perPageOptions'] as $n): ?>
                    <option value="<?= $n ?>" <?= $n === 50 ? 'selected' : '' ?>><?= esc(lang('Dashboard.table.perPage', [$n])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bscd-table-export">
            <button type="button" class="btn btn-outline-primary btn-sm" data-export="csv" title="<?= esc(lang('Dashboard.table.exportHint'), 'attr') ?>">
                <i class="fas fa-file-csv mr-1"></i> <?= esc(lang('Dashboard.table.exportCsv')) ?>
            </button>
            <?php /* Export Excel masqué côté UI (backend XLSX/OpenSpout et routes conservés).
            <button type="button" class="btn btn-outline-primary btn-sm" data-export="xlsx" title="<?= esc(lang('Dashboard.table.exportHint'), 'attr') ?>">
                <i class="fas fa-file-excel mr-1"></i> <?= esc(lang('Dashboard.table.exportXlsx')) ?>
            </button>
            */ ?>
        </div>
    </div>

    <div class="bscd-table-scroll">
        <table class="bscd-datatable" id="bscdTable">
            <thead><tr id="bscdTableHead"></tr></thead>
            <tbody id="bscdTableBody"></tbody>
        </table>
    </div>

    <div class="bscd-table-empty d-none" id="bscdTableEmpty">
        <i class="fas fa-filter-circle-xmark mb-2"></i>
        <p class="mb-2"><?= esc(lang('Dashboard.table.empty')) ?></p>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-reset-filters><?= esc(lang('Dashboard.table.resetFilters')) ?></button>
    </div>

    <div class="bscd-pagination" id="bscdPagination"></div>
</div>

</section>

<!-- ── Modale export ───────────────────────────────────────── -->
<div class="modal fade" id="bscdExportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= esc(lang('Dashboard.export.title')) ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('App.actions.close'), 'attr') ?>">&times;</button>
            </div>
            <div class="modal-body" id="bscdExportBody">
                <div class="bscd-export-summary" id="bscdExportFilters"></div>
                <p class="mb-1"><strong><?= esc(lang('Dashboard.export.results')) ?></strong> <span id="bscdExportCount">—</span> <?= esc(lang('Dashboard.export.rows')) ?></p>
                <p class="mb-1"><strong><?= esc(lang('Dashboard.export.format')) ?></strong> <span id="bscdExportFormat">—</span></p>
                <div class="bscd-export-status d-none" id="bscdExportStatus"></div>
            </div>
            <div class="modal-footer">
                <?php // Before launch only: closes this dialog. A launched export is cancelled from its floating window (export-widget.js). ?>
                <button type="button" class="btn btn-outline-secondary" id="bscdExportCancel" data-dismiss="modal"><?= esc(lang('App.actions.cancel')) ?></button>
                <button type="button" class="btn btn-primary" id="bscdExportLaunch"><?= esc(lang('Dashboard.export.launch')) ?></button>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/chart-export.css') ?>?v=<?= @filemtime(FCPATH . 'assets/css/chart-export.css') ?: 0 ?>">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<?php // Permanent value labels on every chart — a Chart.js plugin dashboard.js registers. ?>
<script src="<?= base_url('assets/js/chart-value-labels.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/chart-value-labels.js') ?: 0 ?>"></script>
<?php // Per-chart CSV / Excel / PNG export menu — before dashboard.js, which registers each chart's dataset in it. ?>
<script src="<?= base_url('assets/js/chart-export.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/chart-export.js') ?: 0 ?>"></script>
<script type="application/json" id="bscd-dashboard-data"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<?php // ?v=<mtime>: a reload always fetches the deployed version, never a stale cached copy.
      // export-progress.js / export-widget.js: loaded by layout/main.php, before these. ?>
<script src="<?= base_url('assets/js/dashboard-sections.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/dashboard-sections.js') ?: 0 ?>"></script>
<script src="<?= base_url('assets/js/dashboard.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/dashboard.js') ?: 0 ?>"></script>
<?= $this->endSection() ?>
