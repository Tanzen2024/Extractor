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
    <?php // Two levels, same 7-column grid so both line up on desktop:
          // the 7 main criteria always visible, then the "Filtres avancés"
          // (collapsed by default, `hidden` only — their values stay in the
          // form and are sent by Appliquer like any other filter).
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
    </div>

    <div class="bscd-filters__advanced" id="bscdAdvanced" hidden>
        <div class="bscd-filters__grid bscd-filters__grid--main">
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
    </div>

    <div class="bscd-filters__actions">
        <button type="button" class="bscd-filters__toggle" id="bscdAdvancedToggle" aria-expanded="false" aria-controls="bscdAdvanced">
            <i class="fas fa-chevron-right bscd-filters__chevron" aria-hidden="true"></i>
            <span><?= esc(lang('Dashboard.filters.advanced')) ?></span>
            <span class="bscd-filters__count" id="bscdAdvancedCount" hidden></span>
        </button>
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
     One comparative grid, one column per METER universe
     (public/assets/js/dashboard-sections.js), each fed by /dashboard/stats
     with the page filters narrowed to its universe. The payer-profile
     segmentation is the subject: Postpaid = Répartition par segmentation
     then Décomposition du profil Défaillant, Prepaid = Répartition par
     segmentation, one card as tall as the two Postpaid ones (desktop).
     The profile figures live in the charts (bar values, doughnut legend);
     each view header carries its customer count and the band above both
     columns their sum. /dashboard/stats computes every figure, unchanged. -->
<?php
$sections = [
    'postpaid' => ['icon' => 'fa-file-invoice', 'title' => 'Dashboard.sections.postpaid.title', 'subtitle' => 'Dashboard.sections.postpaid.subtitle'],
    'prepaid'  => ['icon' => 'fa-credit-card', 'title' => 'Dashboard.sections.prepaid.title', 'subtitle' => 'Dashboard.sections.prepaid.subtitle'],
];
?>
<div class="bscd-compare">
<?php // Postpaid + Prepaid, summed by dashboard.js once both views have answered. ?>
<div class="bscd-grand-total" role="status" aria-live="polite">
    <span class="bscd-grand-total__icon" aria-hidden="true"><i class="fas fa-users"></i></span>
    <span class="bscd-grand-total__label"><?= esc(lang('Dashboard.sections.grandTotal')) ?></span>
    <span class="bscd-grand-total__value" data-grand-total><span class="bscd-skeleton bscd-skeleton--text"></span></span>
    <span class="bscd-grand-total__hint"><?= esc(lang('Dashboard.sections.grandTotalHint')) ?></span>
</div>
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

    <div class="bscd-section__body">
        <?php // 1. Répartition par segmentation: the section's 6 official profiles
              // (business order), counts on the bars. A bar opens its customers
              // in "Détail des clients". ?>
        <div class="bscd-chart-card bscd-viz">
            <div class="bscd-chart-card__header">
                <h3 class="bscd-chart-card__title"><?= esc(lang('Dashboard.charts.bySegmentation')) ?></h3>
                <div class="bscd-chart-card__subtitle"><?= esc(lang('Dashboard.charts.segmentationHint')) ?></div>
                <div class="bscd-cx" data-chart-export="<?= $sk ?>-segmentation"></div>
            </div>
            <div class="bscd-chart-card__body bscd-viz__chart bscd-viz__chart--bars" data-chart-wrap="segmentation"><canvas id="bscdChart_<?= $sk ?>_segmentation"></canvas></div>
        </div>

        <?php if ($sk === 'postpaid'): ?>
        <?php // 2. Décomposition du profil Défaillant: 4 analytic components, never
              // profiles; counts in the legend, a slice or legend item opens its customers. ?>
        <div class="bscd-chart-card bscd-viz bscd-failure">
            <div class="bscd-chart-card__header">
                <h3 class="bscd-chart-card__title"><?= esc(lang('Dashboard.failure.title')) ?></h3>
                <div class="bscd-chart-card__subtitle"><?= esc(lang('Dashboard.failure.subtitle')) ?></div>
                <div class="bscd-cx" data-chart-export="postpaid-failure"></div>
            </div>
            <div class="bscd-chart-card__body bscd-viz__chart bscd-viz__chart--doughnut" data-chart-wrap="failure"><canvas id="bscdChart_postpaid_failure"></canvas></div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>
</div>

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

<!-- ── Data freshness (sync-status.js) ─────────────────────── -->
<div class="bscd-sync" id="bscdSyncStatus" role="status" aria-label="<?= esc(lang('Dashboard.sync.label'), 'attr') ?>">
    <div class="bscd-sync__state">
        <span class="bscd-sync__badge" data-sync-badge><span class="bscd-skeleton bscd-skeleton--text"></span></span>
        <span class="bscd-sync__running" data-sync-running hidden></span>
    </div>
    <dl class="bscd-sync__facts" data-sync-facts></dl>
    <button type="button" class="btn btn-link bscd-sync__history" data-sync-history data-toggle="modal" data-target="#bscdSyncHistoryModal">
        <i class="fas fa-clock-rotate-left mr-1" aria-hidden="true"></i><?= esc(lang('Dashboard.sync.history')) ?>
    </button>
</div>

<!-- ── Table ───────────────────────────────────────────────── -->
<div class="bscd-table-card">
    <div class="bscd-table-toolbar">
        <div class="bscd-chips bscd-failure-filter" id="bscdFailureFilter"></div>
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
<div class="modal fade" id="bscdSyncHistoryModal" tabindex="-1" role="dialog" aria-labelledby="bscdSyncHistoryTitle">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bscdSyncHistoryTitle"><?= esc(lang('Dashboard.sync.historyTitle')) ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('App.actions.close'), 'attr') ?>">&times;</button>
            </div>
            <div class="modal-body table-responsive">
                <table class="bscd-sync-history">
                    <thead>
                        <tr>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.start')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.duration')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.trigger')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.status')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.read')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.added')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.modified')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.ignored')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.removed')) ?></th>
                            <th scope="col"><?= esc(lang('Dashboard.sync.columns.error')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="bscdSyncHistoryBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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
<link rel="stylesheet" href="<?= base_url('assets/css/sync-status.css') ?>?v=<?= @filemtime(FCPATH . 'assets/css/sync-status.css') ?: 0 ?>">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<?php // Permanent value labels on every chart — a Chart.js plugin dashboard.js registers. ?>
<script src="<?= base_url('assets/js/chart-value-labels.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/chart-value-labels.js') ?: 0 ?>"></script>
<?php // Per-chart CSV / Excel / PNG export menu — before dashboard.js, which registers each chart's dataset in it. ?>
<script src="<?= base_url('assets/js/chart-export.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/chart-export.js') ?: 0 ?>"></script>
<script type="application/json" id="bscd-dashboard-data"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= base_url('assets/js/sync-status.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/sync-status.js') ?: 0 ?>"></script>
<?php // ?v=<mtime>: a reload always fetches the deployed version, never a stale cached copy.
      // export-progress.js / export-widget.js: loaded by layout/main.php, before these. ?>
<script src="<?= base_url('assets/js/dashboard-sections.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/dashboard-sections.js') ?: 0 ?>"></script>
<script src="<?= base_url('assets/js/dashboard.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/dashboard.js') ?: 0 ?>"></script>
<?= $this->endSection() ?>
