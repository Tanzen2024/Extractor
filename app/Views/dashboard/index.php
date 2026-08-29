<?php
/**
 * CUSTOMERS_LIST analytics dashboard — shell only.
 *
 * @var array $bootstrap endpoints + static config; every figure is fetched
 *                       client-side (see public/assets/js/dashboard.js).
 */
?>
<?= $this->extend('layout/main') ?>

<?= $this->section('content') ?>

<div class="bscd-dash-header">
    <p class="bscd-dash-subtitle">Vue globale de la base clients — CMS_RFC.TB_CUSTOMERS_LIST</p>
    <div class="bscd-dash-actions">
        <span class="bscd-cache-note" id="bscdCacheNote"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="bscdRefresh">
            <i class="fas fa-rotate mr-1"></i> Rafraîchir
        </button>
    </div>
</div>

<div class="bscd-alert bscd-alert--error d-none" id="bscdGlobalError">
    <div>
        <strong>Impossible de charger les données.</strong>
        <span id="bscdGlobalErrorRef"></span>
    </div>
    <button type="button" class="btn btn-sm btn-outline-danger" id="bscdRetry">Réessayer</button>
</div>

<!-- ── Filtres ─────────────────────────────────────────────── -->
<form class="bscd-filters" id="bscdFilters" autocomplete="off">
    <div class="bscd-filters__grid">
        <div class="bscd-filter-group">
            <label for="bscdDateFrom">Date d'abonnement — début</label>
            <input type="date" id="bscdDateFrom" name="date_from">
        </div>
        <div class="bscd-filter-group">
            <label for="bscdDateTo">Date d'abonnement — fin</label>
            <input type="date" id="bscdDateTo" name="date_to">
        </div>
        <div class="bscd-filter-group" data-ms="region">
            <label>Région</label>
            <div class="bscd-ms" data-dim="region"></div>
        </div>
        <div class="bscd-filter-group" data-ms="division">
            <label>Division</label>
            <div class="bscd-ms" data-dim="division"></div>
        </div>
        <div class="bscd-filter-group" data-ms="agence">
            <label>Agence</label>
            <div class="bscd-ms" data-dim="agence"></div>
        </div>
        <div class="bscd-filter-group" data-ms="status">
            <label>Statut</label>
            <div class="bscd-ms" data-dim="status"></div>
        </div>
    </div>

    <div class="bscd-filters__advanced d-none" id="bscdAdvanced">
        <div class="bscd-filters__grid">
            <div class="bscd-filter-group" data-ms="segmentation">
                <label>Segmentation</label>
                <div class="bscd-ms" data-dim="segmentation"></div>
            </div>
            <div class="bscd-filter-group" data-ms="segment_tresor">
                <label>Segment trésor</label>
                <div class="bscd-ms" data-dim="segment_tresor"></div>
            </div>
            <div class="bscd-filter-group" data-ms="meter">
                <label>Type de compteur</label>
                <div class="bscd-ms" data-dim="meter"></div>
            </div>
            <div class="bscd-filter-group" data-ms="voltage">
                <label>Tension</label>
                <div class="bscd-ms" data-dim="voltage"></div>
            </div>
            <div class="bscd-filter-group">
                <label for="bscdNiuQc">Qualité NIU</label>
                <select id="bscdNiuQc" name="niu_qc">
                    <option value="">Toutes</option>
                    <option value="0">Valide (0)</option>
                    <option value="1">À contrôler (1)</option>
                </select>
            </div>
        </div>
    </div>

    <div class="bscd-filters__actions">
        <button type="button" class="btn btn-link btn-sm px-0" id="bscdToggleAdvanced">
            <i class="fas fa-sliders mr-1"></i> Filtres avancés
        </button>
        <div class="ml-auto d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="bscdReset">Réinitialiser</button>
            <button type="submit" class="btn btn-primary btn-sm" id="bscdApply">
                <i class="fas fa-filter mr-1"></i> Appliquer
            </button>
        </div>
    </div>
</form>

<div class="bscd-chips" id="bscdChips"></div>

<!-- ── KPI ─────────────────────────────────────────────────── -->
<div class="row bscd-kpi-row" id="bscdKpis">
    <?php
    $kpiCards = [
        ['total', 'Total clients', 'fa-users', 'blue'],
        ['actifs', 'Clients actifs', 'fa-circle-check', 'green'],
        ['avecCompteur', 'Clients avec compteur', 'fa-gauge', 'purple'],
        ['contacts', 'Contacts renseignés', 'fa-address-book', 'orange'],
    ];
    foreach ($kpiCards as [$key, $label, $icon, $color]): ?>
        <div class="col-12 col-sm-6 col-xl-3 mb-3">
            <div class="bscd-kpi-card" data-kpi-card="<?= $key ?>">
                <div class="bscd-kpi-icon bscd-kpi-icon--<?= $color ?>"><i class="fas <?= $icon ?>"></i></div>
                <div class="bscd-kpi-body">
                    <div class="bscd-kpi-label"><?= esc($label) ?></div>
                    <div class="bscd-kpi-value" data-kpi="<?= $key ?>"><span class="bscd-skeleton bscd-skeleton--text"></span></div>
                    <div class="bscd-kpi-sub" data-kpi-sub="<?= $key ?>">&nbsp;</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ── Graphiques ──────────────────────────────────────────── -->
<div class="row">
    <div class="col-12 col-lg-6 mb-3">
        <div class="bscd-chart-card">
            <div class="bscd-chart-card__header"><h3 class="bscd-chart-card__title">Répartition par région</h3></div>
            <div class="bscd-chart-card__body" data-chart-wrap="region"><canvas id="bscdChartRegion" height="240"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-6 mb-3">
        <div class="bscd-chart-card">
            <div class="bscd-chart-card__header"><h3 class="bscd-chart-card__title">Répartition par statut</h3></div>
            <div class="bscd-chart-card__body bscd-donut-wrap" data-chart-wrap="status"><canvas id="bscdChartStatus" height="240"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-6 mb-3">
        <div class="bscd-chart-card">
            <div class="bscd-chart-card__header"><h3 class="bscd-chart-card__title">Répartition par segmentation</h3></div>
            <div class="bscd-chart-card__body" data-chart-wrap="segmentation"><canvas id="bscdChartSegmentation" height="240"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-6 mb-3">
        <div class="bscd-chart-card">
            <div class="bscd-chart-card__header">
                <h3 class="bscd-chart-card__title">Qualité des données</h3>
                <div class="bscd-chart-card__subtitle">Part des lignes où le champ est renseigné</div>
            </div>
            <div class="bscd-chart-card__body" data-chart-wrap="completeness"><canvas id="bscdChartCompleteness" height="240"></canvas></div>
        </div>
    </div>
</div>

<!-- ── Table ───────────────────────────────────────────────── -->
<div class="bscd-table-card">
    <div class="bscd-table-toolbar">
        <div class="bscd-table-count" id="bscdRowCount"><span class="bscd-skeleton bscd-skeleton--text"></span></div>
        <div class="bscd-table-tools">
            <div class="bscd-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" id="bscdSearch" placeholder="Nom, contrat, code client, n° compteur…" maxlength="80">
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-toggle="dropdown" id="bscdColumnsBtn">
                    <i class="fas fa-table-columns mr-1"></i> Colonnes
                </button>
                <div class="dropdown-menu dropdown-menu-right bscd-columns-menu" id="bscdColumnsMenu"></div>
            </div>
            <select id="bscdPerPage" class="bscd-perpage">
                <?php foreach ($bootstrap['perPageOptions'] as $n): ?>
                    <option value="<?= $n ?>" <?= $n === 50 ? 'selected' : '' ?>><?= $n ?> / page</option>
                <?php endforeach; ?>
            </select>
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
        <p class="mb-2">Aucune ligne ne correspond aux filtres sélectionnés.</p>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-reset-filters>Réinitialiser les filtres</button>
    </div>

    <div class="bscd-pagination" id="bscdPagination"></div>
</div>

<!-- ── Export ──────────────────────────────────────────────── -->
<div class="bscd-export-bar">
    <div>
        <strong>Exporter</strong>
        <span class="text-muted">— la population exportée correspond exactement aux filtres appliqués.</span>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary btn-sm" data-export="csv"><i class="fas fa-file-csv mr-1"></i> Export CSV</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-export="xlsx"><i class="fas fa-file-excel mr-1"></i> Export Excel</button>
    </div>
</div>

<!-- ── Modale export ───────────────────────────────────────── -->
<div class="modal fade" id="bscdExportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Export des données</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="bscdExportBody">
                <div class="bscd-export-summary" id="bscdExportFilters"></div>
                <p class="mb-1"><strong>Résultats :</strong> <span id="bscdExportCount">—</span> ligne(s)</p>
                <p class="mb-3"><strong>Format :</strong> <span id="bscdExportFormat">—</span></p>
                <div class="bscd-export-status d-none" id="bscdExportStatus"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal" id="bscdExportCancel">Annuler</button>
                <button type="button" class="btn btn-primary" id="bscdExportLaunch">Lancer l'export</button>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script type="application/json" id="bscd-dashboard-data"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= base_url('assets/js/dashboard.js') ?>"></script>
<?= $this->endSection() ?>
