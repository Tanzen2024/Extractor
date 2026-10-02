/**
 * CUSTOMERS_LIST analytics dashboard — live against Oracle.
 *
 * The page shell ships no data. On load this fetches, in parallel:
 *   - GET /dashboard/filter-options  (region→division→agence tree + value lists)
 *   - GET /dashboard/stats           (KPIs + 4 chart datasets)
 *   - GET /dashboard/rows            (first server-side page of the table)
 *
 * Filters are staged in the form and only sent on "Appliquer". The exact
 * same criteria drive the KPIs, the charts, the table and the export.
 */
(function () {
    'use strict';

    var boot = JSON.parse(document.getElementById('bscd-dashboard-data').textContent || '{}');
    var EP = boot.endpoints;
    var DEFAULTS = window.bscdDashboardDefaults; // public/assets/js/dashboard-defaults.js

    var PALETTE = ['#1f6fb2', '#7c3aed', '#d97706', '#1a7f4d', '#c0392b', '#0891b2', '#db2777', '#ca8a04', '#475569', '#94a3b8'];
    var OTHER_COLOR = '#c7ccd6';

    var COLUMN_LABELS = {
        REGION: 'Région', DIVISION: 'Division', AGENCE: 'Agence', COD_UNICOM: 'COD_UNICOM',
        COD_CLI: 'Code client', CONTRACT: 'Contrat', STATUS: 'Statut', METER_NO: 'N° compteur',
        CUST_NAME: 'Client', PHONE_NUMBERS: 'Téléphone', E_MAIL: 'E-mail', REF_GEO: 'REF_GEO',
        DATE_AB: 'Date abo.', DATE_RESILIATION: 'Date résil.', VOLTAGE: 'VOLTAGE',
        SEGMENT_TRESOR: 'SEGMENT_TRESOR', METER: 'METER', NIU_RIGHT: 'NIU_RIGHT',
        XCOORD: 'XCOORD', YCOORD: 'YCOORD', NIU_TO_RECLASS: 'NIU à reclasser', NUI_QC: 'NUI_QC',
        LAST_VC_DATE: 'LAST_VC_DATE', SEGMENT_RFM_2: 'SEGMENT_RFM_2',
        POSTPAID_PROFILE_DATE: 'POSTPAID_PROFILE_DATE', SEGMENTATION: 'Segmentation',
        UPDATED_AT: 'Mis à jour le'
    };

    // Columns selected/visible on first load — server-provided (see
    // QueryBuilder::DEFAULT_VISIBLE_COLUMNS), so the 16-default vs
    // 27-available split has one source of truth. Everything in
    // boot.tableColumns but not here starts hidden — see loadHiddenColumns().
    var DEFAULT_VISIBLE_COLUMNS = boot.defaultVisibleColumns || boot.tableColumns;

    var MS_DIMS = ['region', 'division', 'agence', 'status', 'meter', 'segmentation', 'segment_tresor', 'voltage', 'niu_qc'];

    // STATUT: the 4 statuses the business defines as "actif" (client/contrat
    // actif). Drives the dropdown's two-group layout (see
    // configureStatusGroups()) and the initial selection on first load (see
    // bootstrapAll(); "Réinitialiser" still clears it like any filter). The KPI
    // definitions in Config\Oracle::$activeStatuses are the real source of
    // truth for what counts as "actif" server-side; this array is a display
    // concern only and is kept in sync with it by convention.
    var ACTIVE_STATUSES = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)'];

    // SEGMENTATION values per Type de compteur — exact technical values, as
    // observed in TB_CUSTOMERS_LIST (METER x SEGMENTATION, 2026-09-24):
    // every POSTPAID value below occurs only on POSTPAID (and Compteurs
    // Communicants) contracts, every PREPAID value only on PREPAID ones.
    // "8 Autre" (Postpaid) and "Other" (Prepaid) are two distinct values that
    // merely share the "Other" display label. Both lists are in the STRICT
    // business display order (dropdown and "Répartition par segmentation"
    // chart — never by volume, alphabet or arrival order). PREPAID = the 8
    // exact SQL values of prepaid_profile_p.sql, mirrored from
    // App\Services\CustomersList\PrepaidSegmentations (the backend adds the
    // ones absent from the data at count 0, so all 8 are always offered).
    // Drives the dropdown groups/order, the chart order and the Type compteur
    // -> Segmentation cascade only — never used to build a filter or a query.
    var POSTPAID_SEGMENTATIONS = [
        '1 PERFECT', '2 RELIABLE', '3 Occasionnal', '4 At-Risk', '5 Delinquant',
        '6 Always Late', '8 Never Paid', '7 sometime Paid', '8 Autre'
    ];
    var PREPAID_SEGMENTATIONS = [
        '1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant',
        '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'
    ];

    // Technical value -> friendly display label, for dimensions where the raw
    // Oracle value is either not meant to be read as-is (NIU_QC: a corrupted
    // accented character upstream) or carries a numeric business-rule prefix
    // (SEGMENTATION). The value actually sent to the backend is always the
    // untouched technical one (see MultiSelect#renderList / getValues) — this
    // only changes what the user sees.
    var SEGMENTATION_LABELS = {
        '1 PERFECT': 'PERFECT', '2 RELIABLE': 'RELIABLE', '3 Occasionnal': 'Occasional',
        '4 At-Risk': 'At-Risk', '5 Delinquant': 'Delinquant', '6 Always Late': 'Always Late',
        '8 Never Paid': 'Never Paid', '7 sometime Paid': 'sometime Paid', '8 Autre': 'Other'
    };
    var PREPAID_SEGMENTATION_LABELS = {
        '1-Stable': 'Stable', '2-InStable': 'Instable', '3-At Risk': 'À risque',
        '4-Suspect Dormant': 'Suspect Dormant', '5-Dormant': 'Dormant',
        '6 Old_Dormant': 'Old Dormant', '7 Never Vending': 'Never Vending', 'Other': 'Other'
    };
    Object.keys(PREPAID_SEGMENTATION_LABELS).forEach(function (v) { SEGMENTATION_LABELS[v] = PREPAID_SEGMENTATION_LABELS[v]; });
    var DIM_LABELS = {
        // Only two real NUI_QC values exist (see QueryBuilder::TABLE_COLUMNS
        // comment). The "reclasser" one carries an upstream encoding
        // corruption that also eats letters out of "RECLASSER" itself, so an
        // indexOf('RECLASSER') match is unreliable — match the one clean
        // value ("correct") and treat anything else as the other.
        niu_qc: function (v) {
            var upper = String(v).toUpperCase();
            if (upper.indexOf('CORRECT') !== -1) return 'NUI CORRECT';
            return 'NUI à RECLASSER';
        },
        meter: function (v) {
            var upper = String(v).toUpperCase();
            if (upper.indexOf('COMMUNICANT') !== -1) return 'COMPTEURS COMMUNICANTS';
            return v;
        },
        segmentation: function (v) { return SEGMENTATION_LABELS[v] || v; }
    };
    function dimLabel(dim, value) { return DIM_LABELS[dim] ? DIM_LABELS[dim](value) : value; }

    // Table columns that carry a date — displayed dd/mm/yyyy (the wire value
    // stays ISO: filters/sort/SQL are untouched).
    var DATE_COLUMNS = ['DATE_AB', 'DATE_RESILIATION', 'LAST_VC_DATE', 'POSTPAID_PROFILE_DATE'];

    var state = {
        options: null,
        applied: {},                 // criteria last sent to the server
        charts: {},
        table: { page: 1, perPage: 50, sort: 'CONTRACT', dir: 'asc', search: '' },
        hiddenColumns: loadHiddenColumns(),
        lastStatsAt: null,
        exporting: false,
        filterTotal: null            // snapshot count for state.applied (GET /dashboard/count)
    };

    var els = {
        form: document.getElementById('bscdFilters'),
        dateFrom: document.getElementById('bscdDateFrom'),
        dateTo: document.getElementById('bscdDateTo'),
        chips: document.getElementById('bscdChips'),
        globalError: document.getElementById('bscdGlobalError'),
        globalErrorRef: document.getElementById('bscdGlobalErrorRef'),
        cacheNote: document.getElementById('bscdCacheNote'),
        rowCount: document.getElementById('bscdRowCount'),
        tableHead: document.getElementById('bscdTableHead'),
        tableBody: document.getElementById('bscdTableBody'),
        tableEmpty: document.getElementById('bscdTableEmpty'),
        pagination: document.getElementById('bscdPagination'),
        search: document.getElementById('bscdSearch'),
        perPage: document.getElementById('bscdPerPage'),
        columnsMenu: document.getElementById('bscdColumnsMenu')
    };

    // ── helpers ────────────────────────────────────────────────────────
    function fmt(n) { return (Number(n) || 0).toLocaleString('fr-FR'); }
    function pct(v) { return (Number(v) || 0).toFixed(1).replace('.', ',') + ' %'; }

    // "2026-09-03" or "2026-09-03 14:30:00" -> "03/09/2026". Empty/invalid ->
    // returned as-is (never "Invalid Date"). String-only: no new Date(), so a
    // plain YYYY-MM-DD is never shifted by the browser timezone.
    function frDate(v) {
        if (v == null || v === '') { return ''; }
        var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : String(v);
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function colorFor(i) { return PALETTE[i % PALETTE.length]; }

    function json(url, options) {
        return bscdFetch(url, options).then(function (data) { return data; });
    }

    function showGlobalError(ref) {
        els.globalErrorRef.textContent = ref ? ('Référence : ' + ref) : '';
        els.globalError.classList.remove('d-none');
    }
    function hideGlobalError() { els.globalError.classList.add('d-none'); }

    function loadHiddenColumns() {
        var hidden;
        try { hidden = JSON.parse(localStorage.getItem('bscd_hidden_cols') || '[]'); } catch (e) { return []; }

        // One-time seed: every ALL_COLUMNS entry not in DEFAULT_VISIBLE_COLUMNS
        // (the 11 opt-in columns, incl. NIU_TO_RECLASS) starts hidden for
        // every visitor — new and returning — until they opt in via the
        // column selector, without touching a returning visitor's existing
        // choices for the other columns.
        try {
            if (!localStorage.getItem('bscd_hidden_cols_seeded_v3')) {
                boot.tableColumns.forEach(function (c) {
                    if (DEFAULT_VISIBLE_COLUMNS.indexOf(c) === -1 && hidden.indexOf(c) === -1) hidden.push(c);
                });
                localStorage.setItem('bscd_hidden_cols_seeded_v3', '1');
                localStorage.setItem('bscd_hidden_cols', JSON.stringify(hidden));
            }
        } catch (e) { /* private mode */ }

        return hidden;
    }
    function saveHiddenColumns() {
        try { localStorage.setItem('bscd_hidden_cols', JSON.stringify(state.hiddenColumns)); } catch (e) { /* private mode */ }
    }

    // ── multi-select component ─────────────────────────────────────────
    function MultiSelect(root) {
        this.root = root;
        this.dim = root.dataset.dim;
        this.values = [];
        this.selected = [];
        this.onChange = null;

        root.innerHTML =
            '<button type="button" class="bscd-ms__button"><span class="bscd-ms__label">Toutes</span><i class="fas fa-chevron-down"></i></button>' +
            '<div class="bscd-ms__panel">' +
            '  <input type="search" class="bscd-ms__filter" placeholder="Filtrer…">' +
            '  <div class="bscd-ms__list"></div>' +
            '</div>';

        this.button = root.querySelector('.bscd-ms__button');
        this.labelEl = root.querySelector('.bscd-ms__label');
        this.panel = root.querySelector('.bscd-ms__panel');
        this.list = root.querySelector('.bscd-ms__list');
        this.filterInput = root.querySelector('.bscd-ms__filter');

        var self = this;
        this.button.addEventListener('click', function (e) { e.stopPropagation(); self.toggle(); });
        this.filterInput.addEventListener('input', function () { self.renderList(); });
        document.addEventListener('click', function (e) { if (!root.contains(e.target)) self.close(); });
    }
    MultiSelect.prototype.setOptions = function (pairs) {
        this.values = (pairs || []).map(function (p) { return { value: p.value, count: p.count }; });
        this.selected = this.selected.filter(function (v) { return this.values.some(function (o) { return o.value === v; }); }, this);
        this.renderList();
        this.renderLabel();
    };
    // Optional visual grouping (used by the "status" and "segmentation" dims,
    // see configureStatusGroups() / configureSegmentationGroups()) — a group
    // label is a non-selectable divider, never sent to the backend; the
    // option itself still carries the exact technical value regardless of
    // which group it renders under. Optional orderOf(value) re-orders options
    // inside a group (stable: ties keep the backend's count order).
    MultiSelect.prototype.optionHtml = function (o) {
        var checked = this.selected.indexOf(o.value) !== -1 ? 'checked' : '';
        return '<label class="bscd-ms__opt"><input type="checkbox" value="' + escapeAttr(o.value) + '" ' + checked + '>' +
            '<span>' + escapeHtml(dimLabel(this.dim, o.value)) + '</span><em>' +
            (typeof o.count === 'number' ? fmt(o.count) : escapeHtml(String(o.count))) + '</em></label>';
    };
    MultiSelect.prototype.renderList = function () {
        // Case-insensitive, and "-"/"_" count as spaces ("at risk" finds "At-Risk").
        function norm(s) { return String(s).toLowerCase().replace(/[-_\s]+/g, ' ').trim(); }
        var q = norm(this.filterInput.value || '');
        var self = this;
        // Matches the technical value or the label the user actually sees.
        var filtered = this.values.filter(function (o) {
            return !q || norm(o.value).indexOf(q) !== -1 || norm(dimLabel(self.dim, o.value)).indexOf(q) !== -1;
        });
        var html;

        if (this.groupOf && filtered.length) {
            var buckets = this.groupLabels.map(function () { return []; });
            filtered.forEach(function (o) { buckets[self.groupOf(o.value)].push(o); });
            var rendered = 0;
            html = buckets.map(function (list, i) {
                if (!list.length) return '';
                if (self.orderOf) list.sort(function (a, b) { return self.orderOf(a.value) - self.orderOf(b.value); });
                // A standalone, non-selectable divider between groups — its own
                // element, never a checkbox/option, hidden from screen readers
                // and never part of getValues().
                var separator = rendered > 0 ? '<div class="bscd-ms__group-separator" aria-hidden="true"></div>' : '';
                rendered++;
                return separator + '<div class="bscd-ms__group-label">' + escapeHtml(self.groupLabels[i]) + '</div>' +
                    list.map(function (o) { return self.optionHtml(o); }).join('');
            }).join('');
        } else {
            html = filtered.map(function (o) { return self.optionHtml(o); }).join('');
        }

        this.list.innerHTML = html || '<div class="bscd-ms__none">Aucune valeur</div>';

        this.list.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (cb.checked) { if (self.selected.indexOf(cb.value) === -1) self.selected.push(cb.value); }
                else { self.selected = self.selected.filter(function (v) { return v !== cb.value; }); }
                self.renderLabel();
                if (self.onChange) self.onChange();
            });
        });
    };
    MultiSelect.prototype.renderLabel = function () {
        if (this.selected.length === 0) { this.labelEl.textContent = 'Toutes'; this.root.classList.remove('is-active'); }
        else if (this.selected.length === 1) { this.labelEl.textContent = dimLabel(this.dim, this.selected[0]); this.root.classList.add('is-active'); }
        else { this.labelEl.textContent = this.selected.length + ' sélectionnées'; this.root.classList.add('is-active'); }
        this.updateTooltip();
    };
    // Native `title` tooltip on the closed field — set ONLY when the text is
    // actually clipped by its own ellipsis (scrollWidth > clientWidth is the
    // standard truncation test), so a short label never gets a useless
    // tooltip. Generic across every dim (status, meter, segmentation, …),
    // not specific to any one value.
    MultiSelect.prototype.updateTooltip = function () {
        var el = this.labelEl;
        if (el.scrollWidth > el.clientWidth + 1) { el.setAttribute('title', el.textContent); }
        else { el.removeAttribute('title'); }
    };
    MultiSelect.prototype.toggle = function () { this.panel.classList.toggle('is-open'); };
    MultiSelect.prototype.close = function () { this.panel.classList.remove('is-open'); };
    MultiSelect.prototype.getValues = function () { return this.selected.slice(); };
    MultiSelect.prototype.setValues = function (vals) { this.selected = (vals || []).slice(); this.renderList(); this.renderLabel(); };
    MultiSelect.prototype.clear = function () { this.selected = []; this.renderList(); this.renderLabel(); };

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function escapeAttr(s) { return escapeHtml(s); }

    var ms = {};
    MS_DIMS.forEach(function (dim) {
        var root = document.querySelector('.bscd-ms[data-dim="' + dim + '"]');
        if (root) ms[dim] = new MultiSelect(root);
    });

    function refreshAllTooltips() {
        Object.keys(ms).forEach(function (dim) { ms[dim].updateTooltip(); });
    }
    window.addEventListener('resize', debounce(refreshAllTooltips, 200));
    configureStatusGroups();
    configureSegmentationGroups();

    // ── geo cascade ───────────────────────────────────────────────────
    function refreshCascade() {
        if (!state.options) return;
        var tree = state.options.geoTree || {};
        var regions = ms.region.getValues();
        var scopeRegions = regions.length ? regions : Object.keys(tree);

        var divisionPairs = [];
        var seenDiv = {};
        scopeRegions.forEach(function (r) {
            Object.keys(tree[r] || {}).forEach(function (d) {
                if (!seenDiv[d]) { seenDiv[d] = 0; }
                (tree[r][d] || []).forEach(function (a) { seenDiv[d] += a.count; });
            });
        });
        Object.keys(seenDiv).forEach(function (d) { divisionPairs.push({ value: d, count: seenDiv[d] }); });
        divisionPairs.sort(function (a, b) { return b.count - a.count; });
        ms.division.setOptions(divisionPairs);

        var divisions = ms.division.getValues();
        var scopeDivisions = divisions.length ? divisions : Object.keys(seenDiv);
        var agencePairs = [];
        var seenAg = {};
        scopeRegions.forEach(function (r) {
            Object.keys(tree[r] || {}).forEach(function (d) {
                if (scopeDivisions.indexOf(d) === -1) return;
                (tree[r][d] || []).forEach(function (a) { seenAg[a.value] = (seenAg[a.value] || 0) + a.count; });
            });
        });
        Object.keys(seenAg).forEach(function (a) { agencePairs.push({ value: a, count: seenAg[a] }); });
        agencePairs.sort(function (a, b) { return b.count - a.count; });
        ms.agence.setOptions(agencePairs);
    }

    ms.region.onChange = function () { refreshCascade(); };
    ms.division.onChange = function () { refreshCascade(); };

    // ── type de compteur <-> segmentation cascade ──────────────────────
    // Each Type compteur restricts Segmentation to its own list
    // (POSTPAID_SEGMENTATIONS / PREPAID_SEGMENTATIONS, top of file); both
    // selected -> union of the two. The previous single shared RFM list
    // offered the PREPAID-only "5-Dormant" under POSTPAID and hid the
    // POSTPAID "5 Delinquant" / "8 Autre". Matching always compares the FULL
    // normalised value, never a substring or the numeric prefix.
    // "Compteurs Communicants" uses the POSTPAID segmentation (business rule),
    // even when POSTPAID itself is not selected. Any other METER value is
    // neutral: on its own it shows every segmentation.
    function normalizeMatch(v) { return String(v).trim().toUpperCase().replace(/\s+/g, ' '); }
    function buildNormSet(list) {
        var set = {};
        list.forEach(function (v) { set[normalizeMatch(v)] = true; });
        return set;
    }
    var SEGMENTATION_SETS = {
        POSTPAID: buildNormSet(POSTPAID_SEGMENTATIONS),
        PREPAID: buildNormSet(PREPAID_SEGMENTATIONS)
    };

    // Same "communicant" test as DIM_LABELS.meter.
    function isSmartMeter(value) { return normalizeMatch(value).indexOf('COMMUNICANT') !== -1; }
    // -> 'POSTPAID' | 'PREPAID' | null — the SEGMENTATION universe of a meter
    // type; Compteurs Communicants -> 'POSTPAID'.
    function meterUniverse(value) {
        var n = normalizeMatch(value);
        if (n === 'POSTPAID' || isSmartMeter(value)) return 'POSTPAID';
        if (n === 'PREPAID') return 'PREPAID';
        return null;
    }
    // Label of the POSTPAID segmentation group, from the meter types actually
    // selected (display only — the options under it are the same):
    // POSTPAID + communicants -> both, one of them -> that one, neither ->
    // unchanged "Postpaid". Type de compteur = Toutes (nothing selected) ->
    // both as well.
    function postpaidGroupLabel(meterValues) {
        if (meterValues.length === 0) return 'Postpaid | Compteurs communicants';
        var postpaid = meterValues.some(function (m) { return normalizeMatch(m) === 'POSTPAID'; });
        var smart = meterValues.some(isSmartMeter);
        if (postpaid && smart) return 'Postpaid | Compteurs communicants';
        if (smart) return 'Compteurs communicants';
        return 'Postpaid';
    }
    // -> 'POSTPAID' | 'PREPAID' | null (a value in neither list).
    function segmentationUniverse(value) {
        var n = normalizeMatch(value);
        if (SEGMENTATION_SETS.POSTPAID[n]) return 'POSTPAID';
        if (SEGMENTATION_SETS.PREPAID[n]) return 'PREPAID';
        return null;
    }

    // Numbers next to the Segmentation options = rows matching the filters
    // currently in the form (except the segmentation one) AND that segment,
    // from GET /dashboard/segmentation-counts (live Oracle, same WHERE as the
    // KPIs and the table). Only the numbers change: the options, their order
    // and their groups still come from filter-options. '…' while loading,
    // '—' if unavailable — never the global counts as a stand-in.
    var segCounts = { key: null, map: null, failed: false, seq: 0 };

    function segmentationScopeKey() {
        var c = readForm();
        delete c.segmentation;
        return toParams(c).toString();
    }

    function loadSegmentationCounts() {
        if (!EP.segmentationCounts) return;
        var key = segmentationScopeKey();
        if (key === segCounts.key && (segCounts.map || !segCounts.failed)) return; // loaded or loading
        var seq = ++segCounts.seq;
        segCounts.key = key; segCounts.map = null; segCounts.failed = false;
        refreshSegmentationOptions();

        json(EP.segmentationCounts + '?' + key).then(function (data) {
            if (seq !== segCounts.seq) return; // superseded by a newer filter set
            if (!data || data.error || !Array.isArray(data.counts)) throw data;
            var map = {};
            data.counts.forEach(function (p) { map[normalizeMatch(p.value)] = Number(p.count) || 0; });
            segCounts.map = map;
            refreshSegmentationOptions();
        }).catch(function () {
            if (seq !== segCounts.seq) return;
            segCounts.failed = true;
            refreshSegmentationOptions();
        });
    }

    function withScopedCounts(pairs) {
        if (!EP.segmentationCounts) return pairs; // older page shell: previous behaviour
        return pairs.map(function (p) {
            var count = segCounts.map ? (segCounts.map[normalizeMatch(p.value)] || 0) : (segCounts.failed ? '—' : '…');
            return { value: p.value, count: count };
        });
    }

    // The 8 PREPAID categories are always offered, whatever the backend
    // lists (it may send only the values present in the data): a missing
    // one is added at count 0 — its real scoped count, if any, still comes
    // from withScopedCounts(). Same completion as
    // PrepaidSegmentations::completeOptions() server-side.
    function withAllPrepaid(pairs) {
        var present = {};
        pairs.forEach(function (p) { present[normalizeMatch(p.value)] = true; });
        return pairs.concat(PREPAID_SEGMENTATIONS
            .filter(function (v) { return !present[normalizeMatch(v)]; })
            .map(function (v) { return { value: v, count: 0 }; }));
    }

    function segmentationOptionsForMeters(meterValues) {
        var all = withScopedCounts(withAllPrepaid((state.options && state.options.segmentations) || []));
        var universes = {};
        meterValues.forEach(function (m) { var u = meterUniverse(m); if (u) universes[u] = true; });
        // No POSTPAID/PREPAID universe selected (nothing, or only a neutral
        // meter) -> stay neutral, show everything.
        if (!universes.POSTPAID && !universes.PREPAID) return all;
        return all.filter(function (p) { return !!universes[segmentationUniverse(p.value)]; });
    }
    // Segmentation -> Type compteur is intentionally NOT implemented: a
    // Segmentation choice never forces a Type compteur selection.
    function refreshSegmentationOptions() {
        var meters = ms.meter.getValues();
        ms.segmentation.groupLabels[0] = postpaidGroupLabel(meters);
        ms.segmentation.setOptions(segmentationOptionsForMeters(meters));
    }
    ms.meter.onChange = function () { refreshSegmentationOptions(); loadSegmentationCounts(); };
    // Opening Segmentation: counts for the filters now in the form (region,
    // dates, … may have changed without "Appliquer").
    var segToggle = ms.segmentation.toggle;
    ms.segmentation.toggle = function () {
        segToggle.call(this);
        if (this.panel.classList.contains('is-open')) loadSegmentationCounts();
    };

    // Two visual groups in the STATUT dropdown — "Contrats actifs" first
    // (the 4 business-active statuses), then "Contrats inactifs" (every
    // other status the backend actually returns, never hardcoded here).
    // Purely a display grouping: group labels are not options, selection
    // and the values sent to the backend are unaffected.
    function configureStatusGroups() {
        ms.status.groupLabels = ['Contrats actifs', 'Contrats inactifs'];
        ms.status.groupOf = function (value) { return ACTIVE_STATUSES.indexOf(value) !== -1 ? 0 : 1; };
    }

    // Three visual groups in the SEGMENTATION dropdown, same mechanism as
    // STATUT: "Postpaid" (POSTPAID_SEGMENTATIONS, in that order), "Prepaid"
    // (PREPAID_SEGMENTATIONS, count order), then "Autres" for any value the
    // backend returns that is in neither list — none today, and like any
    // empty group it is then not rendered. Display only: selection, the
    // values sent to the backend and the counts are unaffected.
    function configureSegmentationGroups() {
        var groupIndex = { POSTPAID: 0, PREPAID: 1 };
        ms.segmentation.groupLabels = ['Postpaid', 'Prepaid', 'Autres'];
        ms.segmentation.groupOf = function (value) {
            var u = segmentationUniverse(value);
            return u ? groupIndex[u] : 2;
        };
        ms.segmentation.orderOf = function (value) {
            var i = POSTPAID_SEGMENTATIONS.indexOf(value);
            if (i !== -1) return i;
            i = prepaidRank(value);
            return i === -1 ? PREPAID_SEGMENTATIONS.length : i;
        };
    }

    // ── filter form <-> criteria ──────────────────────────────────────
    function readForm() {
        var c = {};
        if (els.dateFrom.value) c.date_from = els.dateFrom.value;
        if (els.dateTo.value) c.date_to = els.dateTo.value;
        MS_DIMS.forEach(function (dim) {
            var v = ms[dim].getValues();
            if (v.length) c[dim] = v;
        });
        return c;
    }

    function toParams(c) {
        var p = new URLSearchParams();
        Object.keys(c).forEach(function (k) {
            var v = c[k];
            // Bracket notation so PHP/CI4 parses repeated values as an array
            // (a bare "region=A&region=B" collapses to the last value).
            if (Array.isArray(v)) v.forEach(function (x) { p.append(k + '[]', x); });
            else p.append(k, v);
        });
        return p;
    }

    var FILTER_LABELS = {
        date_from: 'Depuis', date_to: "Jusqu'au", region: 'Région', division: 'Division',
        agence: 'Agence', status: 'Statut', segmentation: 'Segmentation',
        segment_tresor: 'Segment trésor', meter: 'Compteur', voltage: 'Tension', niu_qc: 'Qualité NIU'
    };

    function filterText(k, v) {
        if (k === 'date_from' || k === 'date_to') return frDate(v);
        if (Array.isArray(v)) return v.map(function (x) { return dimLabel(k, x); }).join(', ');
        return dimLabel(k, v);
    }

    function renderChips() {
        var c = state.applied;
        var chips = [];
        Object.keys(c).forEach(function (k) {
            var text = filterText(k, c[k]);
            chips.push('<span class="bscd-chip" data-chip="' + k + '">' + escapeHtml(FILTER_LABELS[k] || k) + ' : ' +
                escapeHtml(text) + '<button type="button" aria-label="Retirer">&times;</button></span>');
        });
        els.chips.innerHTML = chips.join('');
        els.chips.querySelectorAll('.bscd-chip button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.parentNode.dataset.chip;
                if (key === 'date_from') window.frdate.clear(els.dateFrom);
                else if (key === 'date_to') window.frdate.clear(els.dateTo);
                else if (ms[key]) ms[key].clear();
                refreshCascade();
                if (key === 'meter') refreshSegmentationOptions();
                apply();
            });
        });
    }

    // ── data loads ────────────────────────────────────────────────────
    function loadFilterOptions(fresh) {
        return json(EP.filterOptions + (fresh ? '?fresh=1' : '')).then(function (data) {
            if (data.error) throw data;
            state.options = data;

            var bounds = data.dateBounds || {};
            [els.dateFrom, els.dateTo].forEach(function (input) {
                window.frdate.setBounds(input, bounds.min || '', bounds.max || '');
            });

            ms.region.setOptions(data.regions);
            ms.status.setOptions(data.statuses);
            refreshSegmentationOptions();
            ms.segment_tresor.setOptions(data.segmentsTresor);
            ms.meter.setOptions(data.meters);
            ms.voltage.setOptions(data.voltages);
            ms.niu_qc.setOptions(data.niuQualities);
            refreshCascade();
        });
    }

    var statsSeq = 0;

    function loadStats(fresh) {
        var seq = ++statsSeq;
        var applied = state.applied;
        applyKpiLayout();
        setKpiSkeleton(true);
        setChartsLoading('Chargement…');
        var key = toParams(applied).toString();
        if (EP.count) loadCount(key); // in parallel: the snapshot count behind export "Résultats"
        var url = EP.stats + '?' + key + (fresh ? '&fresh=1' : '');
        return json(url).then(function (data) {
            // A slower answer for an older filter set never overwrites the current one.
            if (seq !== statsSeq) return;
            if (data.error) throw data;
            renderKpis(data);
            renderCharts(data, applied);
            state.lastStatsAt = Date.now();
            updateCacheNote();
        }).catch(function (err) {
            if (seq !== statsSeq) return;
            setChartsLoading('Données indisponibles.'); // never the previous charts
            throw err;
        });
    }

    // Rows matching the filters in the ACTIVE SNAPSHOT — the file the exports
    // read (same count as POST /dashboard/export). Drives the export modal's
    // "Résultats"; never replaced by an Oracle figure.
    var countSeq = 0;

    function loadCount(key) {
        var seq = ++countSeq;
        state.filterTotal = null;
        refreshExportCount();

        return json(EP.count + '?' + key).then(function (data) {
            if (seq !== countSeq) return; // superseded by a newer filter set
            if (!data || data.error || typeof data.count !== 'number') throw data;
            state.filterTotal = data.count;
            refreshExportCount();
        }).catch(function () {
            // No snapshot count: the modal keeps showing '…', never an Oracle stand-in.
        });
    }

    // The export modal reads the snapshot count — refreshed in place when it
    // arrives while the modal is already open.
    function refreshExportCount() {
        var modal = document.getElementById('bscdExportModal');
        if (!modal || !modal.classList.contains('show')) return;
        document.getElementById('bscdExportCount').textContent = state.filterTotal != null ? fmt(state.filterTotal) : '…';
    }

    var rowsSeq = 0;

    function loadRows(fresh) {
        var params = toParams(state.applied);
        params.set('page', state.table.page);
        params.set('per_page', state.table.perPage);
        params.set('sort', state.table.sort);
        params.set('dir', state.table.dir);
        if (state.table.search) params.set('search', state.table.search);
        if (fresh) params.set('fresh', '1');

        var seq = ++rowsSeq; // only the most recent call is allowed to render
        els.rowCount.innerHTML = '<span class="bscd-skeleton bscd-skeleton--text"></span>';
        els.tableBody.classList.add('is-loading');

        return json(EP.rows + '?' + params.toString()).then(function (data) {
            if (seq !== rowsSeq) return; // superseded by a newer request
            els.tableBody.classList.remove('is-loading');
            if (data.error) {
                if (data.error === 'filter') { els.rowCount.textContent = data.message; return; }
                throw data;
            }
            renderTable(data);
        });
    }

    // ── renderers ─────────────────────────────────────────────────────
    // "Total clients" only when STATUT is not exactly the 4 active statuses
    // (it would just repeat "Clients actifs"). The KPI columns are equal flex
    // columns (col-lg), so the visible cards — 3 or 4 — always share one
    // desktop line. Decided from the filters the stats are requested for.
    function applyKpiLayout() {
        var showTotal = !DEFAULTS.isExactlyActiveStatuses(state.applied.status, ACTIVE_STATUSES);
        document.querySelector('[data-kpi-col="total"]').classList.toggle('d-none', !showTotal);
    }

    function setKpiSkeleton(on) {
        ['total', 'actifs', 'nuiCorrects', 'contacts'].forEach(function (k) {
            var v = document.querySelector('[data-kpi="' + k + '"]');
            var s = document.querySelector('[data-kpi-sub="' + k + '"]');
            if (on) { v.innerHTML = '<span class="bscd-skeleton bscd-skeleton--text"></span>'; s.innerHTML = '&nbsp;'; }
        });
    }

    function renderKpis(stats) {
        var k = stats.kpis;
        if (!EP.count) state.filterTotal = stats.totalRows; // no count endpoint (older page shell): previous behaviour
        // Same filters and same query as the other cards (QueryBuilder::kpiStatement() NUI_CORRECT).
        // "% du total" = NUI corrects (filtered) / all NUI of the unfiltered reference.
        document.querySelector('[data-kpi="nuiCorrects"]').textContent = k.nuiCorrects ? fmt(k.nuiCorrects.value) : '—';
        setKpiSub('nuiCorrects', k.nuiCorrects ? pct(k.nuiCorrects.pct) + ' du total' : '', 'NUI corrects correspondant aux filtres');
        // Same query and filters as the other cards (QueryBuilder::kpiStatement() TOTAL);
        // "% du total" = selected contracts / unfiltered reference total.
        document.querySelector('[data-kpi="total"]').textContent = fmt(k.total);
        setKpiSub('total', k.totalPct != null ? pct(k.totalPct) + ' du total' : '', 'contrats correspondant aux filtres');
        [['actifs', k.actifs], ['contacts', k.contacts]].forEach(function (pair) {
            if (!pair[1]) { // stats cached before this card existed
                document.querySelector('[data-kpi="' + pair[0] + '"]').textContent = '—';
                return;
            }
            document.querySelector('[data-kpi="' + pair[0] + '"]').textContent = fmt(pair[1].value);
            document.querySelector('[data-kpi-sub="' + pair[0] + '"]').textContent = pct(pair[1].pct) + ' du total';
        });
    }

    // Percentage line + the card's descriptive note underneath (both text only).
    function setKpiSub(key, pctText, note) {
        var s = document.querySelector('[data-kpi-sub="' + key + '"]');
        s.textContent = pctText;
        var n = document.createElement('span');
        n.className = 'bscd-kpi-note';
        n.textContent = note;
        s.appendChild(n);
    }

    function destroyChart(id) { if (state.charts[id]) { state.charts[id].destroy(); delete state.charts[id]; } }

    // `applied` = the filters these stats were computed for.
    function renderCharts(stats, applied) {
        var ch = stats.charts;
        bar('bscdChartRegion', ch.region, true);
        donut('bscdChartStatus', ch.status);
        bar('bscdChartSegmentation', segmentationChartPairs(ch.segmentation, applied || {}), false);
        meterTypeSummary(ch.meterType);
    }

    // Position in PREPAID_SEGMENTATIONS (full normalised value), -1 if none.
    function prepaidRank(value) {
        var n = normalizeMatch(value);
        for (var i = 0; i < PREPAID_SEGMENTATIONS.length; i++) {
            if (normalizeMatch(PREPAID_SEGMENTATIONS[i]) === n) return i;
        }
        return -1;
    }

    // Segmentation chart categories, in the strict business order (never by
    // volume): POSTPAID list when the Type compteur scope includes POSTPAID /
    // Compteurs communicants (or is "Toutes"), then PREPAID ones. A value
    // existing in the data but empty in this scope stays, at 0. Segments
    // ticked in the Segmentation filter = only those. Counts are the API's
    // (already filtered); a value in no list is appended, never dropped.
    function segmentationChartPairs(pairs, applied) {
        pairs = pairs || [];
        var counts = {};
        pairs.forEach(function (p) { counts[p.value] = p.count; });

        var universes = {};
        (applied.meter || []).forEach(function (m) { var u = meterUniverse(m); if (u) universes[u] = true; });
        if (!universes.POSTPAID && !universes.PREPAID) universes = { POSTPAID: true, PREPAID: true };

        var known = ((state.options && state.options.segmentations) || []).map(function (o) { return o.value; });
        pairs.forEach(function (p) { if (known.indexOf(p.value) === -1) known.push(p.value); });

        var postpaid = universes.POSTPAID ? POSTPAID_SEGMENTATIONS.filter(function (v) { return known.indexOf(v) !== -1; }) : [];
        // All 8 PREPAID categories, always, in PREPAID_SEGMENTATIONS order.
        var prepaid = universes.PREPAID ? PREPAID_SEGMENTATIONS.slice() : [];
        var order = postpaid.concat(prepaid);

        var selected = applied.segmentation || [];
        if (selected.length) order = order.filter(function (v) { return selected.indexOf(v) !== -1; });

        pairs.forEach(function (p) { if (order.indexOf(p.value) === -1 && p.count > 0) order.push(p.value); });
        // PREPAID bars carry their display label (no numeric prefix).
        return order.map(function (v) {
            return { value: v, count: counts[v] || 0, label: PREPAID_SEGMENTATION_LABELS[v] || v };
        });
    }

    // Loading / empty / error message in place of a chart (CSS shows
    // data-chart-msg over a hidden canvas). null = show the chart.
    var CHART_EMPTY = 'Aucune donnée disponible pour les filtres sélectionnés.';
    function setChartMessage(canvasId, text) {
        var wrap = document.getElementById(canvasId).parentNode;
        if (!wrap || !wrap.dataset) return;
        if (text) wrap.dataset.chartMsg = text; else delete wrap.dataset.chartMsg;
    }

    // New filter set: the previous charts are removed right away — never
    // left on screen as if they matched the new filters.
    function setChartsLoading(text) {
        ['bscdChartRegion', 'bscdChartStatus', 'bscdChartSegmentation'].forEach(function (id) {
            destroyChart(id);
            setChartMessage(id, text);
        });
        var totalEl = document.querySelector('[data-metertype="total"]');
        var listEl = document.querySelector('[data-metertype="list"]');
        if (totalEl) totalEl.innerHTML = '<span class="bscd-skeleton bscd-skeleton--text"></span>';
        if (listEl) listEl.innerHTML = '<li class="bscd-metertype__empty">' + escapeHtml(text) + '</li>';
    }

    function bar(canvasId, pairs, horizontal) {
        destroyChart(canvasId);
        var canvas = document.getElementById(canvasId);
        pairs = pairs || [];
        var total = pairs.reduce(function (s, p) { return s + p.count; }, 0);
        setChartMessage(canvasId, total ? null : CHART_EMPTY);
        if (!total) return;
        state.charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: pairs.map(function (p) { return p.label || p.value; }),
                datasets: [{
                    data: pairs.map(function (p) { return p.count; }),
                    backgroundColor: pairs.map(function (p, i) { return p.value === 'Autres' ? OTHER_COLOR : colorFor(i); }),
                    borderRadius: 3, maxBarThickness: 26
                }]
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) {
                        var val = horizontal ? ctx.parsed.x : ctx.parsed.y;
                        return fmt(val) + (total ? ' (' + pct(val / total * 100) + ')' : '');
                    } } }
                },
                scales: (function () {
                    var s = {};
                    s[horizontal ? 'x' : 'y'] = { ticks: { callback: function (v) { return fmt(v); } } };
                    return s;
                })()
            }
        });
    }

    function donut(canvasId, pairs) {
        destroyChart(canvasId);
        var canvas = document.getElementById(canvasId);
        pairs = foldTail(pairs || [], 6);
        var total = pairs.reduce(function (s, p) { return s + p.count; }, 0);
        setChartMessage(canvasId, total ? null : CHART_EMPTY);
        if (!total) return;
        state.charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: pairs.map(function (p) { return p.label || p.value; }),
                datasets: [{ data: pairs.map(function (p) { return p.count; }),
                    backgroundColor: pairs.map(function (p, i) { return p.value === 'Autres' ? OTHER_COLOR : colorFor(i); }), borderWidth: 0 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } },
                    tooltip: { callbacks: { label: function (ctx) {
                        return ctx.label + ': ' + fmt(ctx.parsed) + (total ? ' (' + pct(ctx.parsed / total * 100) + ')' : '');
                    } } }
                }
            }
        });
    }

    // "Type de compteurs" — total + one row per METER value, counts and % from
    // Oracle (GROUP BY METER). No hardcoded type list: whatever buckets the
    // backend returns are rendered, in descending-count order, blank -> the
    // "Non renseigné" bucket the backend already labels.
    function meterTypeSummary(pairs) {
        pairs = pairs || [];
        var totalEl = document.querySelector('[data-metertype="total"]');
        var listEl = document.querySelector('[data-metertype="list"]');
        if (!totalEl || !listEl) return;

        var total = pairs.reduce(function (s, p) { return s + (Number(p.count) || 0); }, 0);
        totalEl.textContent = fmt(total);

        listEl.innerHTML = '';
        pairs.forEach(function (p, i) {
            var share = total ? (Number(p.count) || 0) / total * 100 : 0;
            var li = document.createElement('li');
            li.className = 'bscd-metertype__row';
            li.innerHTML =
                '<span class="bscd-metertype__swatch" style="background:' + colorFor(i) + '"></span>' +
                '<span class="bscd-metertype__name">' + escapeHtml(p.value) + '</span>' +
                '<span class="bscd-metertype__count">' + fmt(p.count) + '</span>' +
                '<span class="bscd-metertype__pct">' + pct(share) + '</span>';
            listEl.appendChild(li);
        });

        if (pairs.length === 0) {
            listEl.innerHTML = '<li class="bscd-metertype__empty">' + escapeHtml(CHART_EMPTY) + '</li>';
        }
    }

    function foldTail(pairs, topN) {
        if (pairs.length <= topN) return pairs;
        var head = pairs.slice(0, topN - 1);
        var rest = pairs.slice(topN - 1).reduce(function (s, p) { return s + p.count; }, 0);
        return head.concat([{ value: 'Autres', count: rest }]);
    }

    function visibleColumns() {
        return boot.tableColumns.filter(function (c) { return state.hiddenColumns.indexOf(c) === -1; });
    }

    function renderTable(data) {
        state.lastRows = data;
        state.table.page = data.page;
        state.table.total = data.total;

        els.rowCount.textContent = fmt(data.total) + ' résultat' + (data.total > 1 ? 's' : '') +
            (isFiltered() ? ' correspondant aux filtres' : '');

        var cols = visibleColumns();
        els.tableHead.innerHTML = cols.map(function (col) {
            var sortable = boot.sortable.indexOf(col) !== -1;
            var arrow = '';
            if (state.table.sort === col) arrow = state.table.dir === 'asc' ? ' ▲' : ' ▼';
            return '<th' + (sortable ? ' class="is-sortable" data-sort="' + col + '"' : '') + '>' +
                escapeHtml(COLUMN_LABELS[col] || col) + arrow + '</th>';
        }).join('');

        els.tableHead.querySelectorAll('th.is-sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var col = th.dataset.sort;
                if (state.table.sort === col) state.table.dir = state.table.dir === 'asc' ? 'desc' : 'asc';
                else { state.table.sort = col; state.table.dir = 'asc'; }
                state.table.page = 1;
                loadRows().catch(handleError);
            });
        });

        if (!data.data.length) {
            els.tableBody.innerHTML = '';
            els.tableEmpty.classList.remove('d-none');
        } else {
            els.tableEmpty.classList.add('d-none');
            els.tableBody.innerHTML = data.data.map(function (row) {
                return '<tr>' + cols.map(function (col) {
                    var raw = row[col] == null ? '' : row[col];
                    var val = DATE_COLUMNS.indexOf(col) >= 0 ? frDate(raw) : raw;
                    return '<td>' + escapeHtml(val) + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }

        renderPagination(data);
    }

    function renderPagination(data) {
        var pages = Math.max(1, Math.ceil(data.total / data.perPage));
        var cur = data.page;
        if (pages <= 1) { els.pagination.innerHTML = ''; return; }

        var btns = [];
        btns.push(pageBtn('«', cur - 1, cur === 1));
        var start = Math.max(1, cur - 2), end = Math.min(pages, start + 4);
        start = Math.max(1, end - 4);
        if (start > 1) { btns.push(pageBtn('1', 1, false)); if (start > 2) btns.push('<span class="bscd-page-gap">…</span>'); }
        for (var i = start; i <= end; i++) btns.push(pageBtn(String(i), i, false, i === cur));
        if (end < pages) { if (end < pages - 1) btns.push('<span class="bscd-page-gap">…</span>'); btns.push(pageBtn(String(pages), pages, false)); }
        btns.push(pageBtn('»', cur + 1, cur === pages));

        els.pagination.innerHTML = '<div class="bscd-page-info">Page ' + cur + ' / ' + fmt(pages) + '</div><div class="bscd-page-btns">' + btns.join('') + '</div>';
        els.pagination.querySelectorAll('button[data-page]').forEach(function (b) {
            b.addEventListener('click', function () {
                state.table.page = parseInt(b.dataset.page, 10);
                loadRows().catch(handleError);
                window.scrollTo({ top: els.rowCount.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
            });
        });
    }
    function pageBtn(label, page, disabled, active) {
        return '<button type="button" data-page="' + page + '"' + (disabled ? ' disabled' : '') +
            (active ? ' class="is-active"' : '') + '>' + label + '</button>';
    }

    function renderColumnsMenu() {
        els.columnsMenu.innerHTML = boot.tableColumns.map(function (col) {
            var checked = state.hiddenColumns.indexOf(col) === -1 ? 'checked' : '';
            return '<label class="dropdown-item bscd-col-opt"><input type="checkbox" value="' + col + '" ' + checked + '> ' +
                escapeHtml(COLUMN_LABELS[col] || col) + '</label>';
        }).join('');
        els.columnsMenu.querySelectorAll('input').forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (cb.checked) state.hiddenColumns = state.hiddenColumns.filter(function (c) { return c !== cb.value; });
                else if (state.hiddenColumns.indexOf(cb.value) === -1) state.hiddenColumns.push(cb.value);
                saveHiddenColumns();
                if (state.lastRows) renderTable(state.lastRows);
            });
        });
        els.columnsMenu.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    function updateCacheNote() {
        if (!state.lastStatsAt) { els.cacheNote.textContent = ''; return; }
        els.cacheNote.textContent = 'Actualisé ' + timeAgo(state.lastStatsAt);
    }
    function timeAgo(ts) {
        var s = Math.round((Date.now() - ts) / 1000);
        if (s < 60) return "à l'instant";
        var m = Math.round(s / 60);
        return 'il y a ' + m + ' min';
    }

    function isFiltered() { return Object.keys(state.applied).length > 0; }

    // ── apply / errors ────────────────────────────────────────────────
    function apply() {
        state.applied = readForm();
        state.table.page = 1;
        renderChips();
        hideGlobalError();
        loadSegmentationCounts();
        return Promise.all([
            loadStats().catch(handleError),
            loadRows().catch(handleError)
        ]);
    }

    function handleError(err) {
        if (err && err.error === 'filter') { alert(err.message || 'Filtre invalide.'); return; }
        showGlobalError(err && err.reference);
    }

    // ── export ────────────────────────────────────────────────────────
    // This modal only shows what will be exported and launches it. From the
    // click on, the export lives in the floating window shared by every page
    // (export-widget.js, window.bscdExportWidget): progress, "Annuler"
    // (real POST /exports/{id}/cancel), download, errors — the one polling loop.
    var currentExportFormat = 'csv';
    var exportLocked = false; // true once the modal shows a terminal message; next click closes
    var WIDGET = window.bscdExportWidget || null;

    function openExportModal(format) {
        currentExportFormat = format;
        document.getElementById('bscdExportFormat').textContent = format === 'xlsx' ? 'Excel (.xlsx)' : 'CSV';

        // The export applies the filters only (never the table search box),
        // so show the filter-only count from the last stats call.
        // Snapshot count only (see loadCount) — never the table's live Oracle
        // count as a stand-in: '…' until it arrives, then refreshExportCount().
        var total = state.filterTotal != null ? state.filterTotal : (EP.count ? null : state.table.total);
        document.getElementById('bscdExportCount').textContent = total != null ? fmt(total) : '…';

        var rows = Object.keys(state.applied).map(function (k) {
            return '<div><strong>' + escapeHtml(FILTER_LABELS[k] || k) + '</strong> : ' +
                escapeHtml(filterText(k, state.applied[k])) + '</div>';
        });
        if (state.table.search) {
            rows.push('<div class="text-muted mt-1"><em>La recherche « ' + escapeHtml(state.table.search) +
                ' » n\'est pas appliquée à l\'export.</em></div>');
        }
        document.getElementById('bscdExportFilters').innerHTML = rows.length
            ? rows.join('')
            : '<div class="text-muted">Aucun filtre — tout le référentiel.</div>';

        var status = document.getElementById('bscdExportStatus');
        status.classList.add('d-none'); status.innerHTML = '';
        var launch = document.getElementById('bscdExportLaunch');
        launch.textContent = "Lancer l'export";
        launch.disabled = false;
        exportLocked = false;

        // One export followed at a time: the running one stays in its window.
        if (WIDGET && WIDGET.isBusy()) {
            launch.disabled = true;
            status.classList.remove('d-none');
            status.innerHTML = '<span class="text-muted">Un export est déjà en cours (fenêtre en bas à droite). ' +
                'Attendez sa fin ou annulez-le avant d\'en lancer un autre.</span>';
        }

        $('#bscdExportModal').modal('show');
    }

    function exportFail(msg, canRetry) {
        var btn = document.getElementById('bscdExportLaunch');
        var status = document.getElementById('bscdExportStatus');
        status.classList.remove('d-none');
        status.innerHTML = '<span class="text-danger">' + escapeHtml(msg) + '</span>';
        btn.disabled = false;
        if (canRetry === false) {
            exportLocked = true;
            btn.textContent = 'Fermer';
        } else {
            btn.textContent = 'Réessayer';
        }
    }

    function launchExport() {
        if (exportLocked) { $('#bscdExportModal').modal('hide'); return; }
        if (!WIDGET) { exportFail('Suivi des exports indisponible. Rechargez la page.', false); return; }

        var format = currentExportFormat;
        if (!WIDGET.begin({ format: format, count: state.filterTotal })) {
            exportFail("Un export est déjà en cours. Attendez sa fin ou annulez-le.", false);
            return;
        }
        // The floating window takes over ("Préparation de l'export…"): the page stays usable.
        $('#bscdExportModal').modal('hide');

        var body = toParams(state.applied);
        body.set('format', format);

        bscdFetch(EP.export, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (res) {
                if (!res) {
                    WIDGET.fail({ message: 'Réponse vide du serveur. Rechargez la page puis réessayez.' });
                    return;
                }
                // A server-reported failure — surface its reference/message so
                // the real cause is visible instead of a generic "n'a pas pu
                // démarrer" (a 422 invalid filter, a 500 with an EXP-… ref, a
                // 403 from a stale CSRF token, a redirect to /login, …).
                if (res.error) {
                    WIDGET.fail({
                        message: "L'export n'a pas pu être lancé" + (res.message ? ' : ' + res.message : (res.reference ? '.' : ' (HTTP ' + (res.status || '?') + ').')) + ' Réessayez.',
                        reference: res.reference || ''
                    });
                    return;
                }
                if (res.mode === 'empty') {
                    WIDGET.empty();
                    return;
                }
                if (res.mode === 'sync') {
                    WIDGET.syncDone({
                        format: format, count: res.count, rows: res.rows, downloadUrl: res.downloadUrl,
                        fileSize: res.fileSize, generationSeconds: res.generationSeconds
                    });
                    return;
                }
                if (res.mode === 'async' && res.jobId) {
                    WIDGET.attach(res.jobId, { format: format, count: res.count });
                    return;
                }
                // No recognised mode and no error flag — never fall through to
                // the async branch with no job id.
                WIDGET.fail({ message: 'Réponse inattendue du serveur. Rechargez la page puis réessayez.' });
            })
            .catch(function () {
                WIDGET.fail({ message: 'Erreur réseau. Réessayez.' });
            });
    }

    // ── wiring ────────────────────────────────────────────────────────
    els.form.addEventListener('submit', function (e) { e.preventDefault(); apply(); });
    document.getElementById('bscdReset').addEventListener('click', resetFilters);
    document.querySelectorAll('[data-reset-filters]').forEach(function (b) { b.addEventListener('click', resetFilters); });
    document.getElementById('bscdToggleAdvanced').addEventListener('click', function () {
        document.getElementById('bscdAdvanced').classList.toggle('d-none');
        // The advanced dims (segmentation, meter, …) were hidden (display:none)
        // when their tooltip was first computed, so scrollWidth/clientWidth
        // read 0/0 back then — recheck now that they are actually laid out.
        refreshAllTooltips();
    });
    document.getElementById('bscdRefresh').addEventListener('click', function () {
        loadFilterOptions(true).then(function () { return Promise.all([loadStats(true), loadRows(true)]); }).catch(handleError);
    });
    document.getElementById('bscdRetry').addEventListener('click', function () { bootstrapAll(); });

    els.search.addEventListener('input', debounce(function () {
        state.table.search = els.search.value.trim();
        state.table.page = 1;
        loadRows().catch(handleError);
    }, 350));
    els.perPage.addEventListener('change', function () {
        state.table.perPage = parseInt(els.perPage.value, 10);
        state.table.page = 1;
        loadRows().catch(handleError);
    });
    document.querySelectorAll('[data-export]').forEach(function (b) {
        b.addEventListener('click', function () { openExportModal(b.dataset.export); });
    });
    document.getElementById('bscdExportLaunch').addEventListener('click', launchExport);

    function resetFilters() {
        window.frdate.clear(els.dateFrom); window.frdate.clear(els.dateTo);
        MS_DIMS.forEach(function (d) { ms[d].clear(); }); // STATUT: reset leaves it empty, like every other filter
        refreshSegmentationOptions(); // meter now empty -> all options, label "Postpaid | Compteurs communicants"
        refreshCascade();
        apply();
    }

    function bootstrapAll() {
        hideGlobalError();
        renderColumnsMenu();
        // Fresh start: clear the staged form and chips, then — once the
        // options are known — pre-check the "Contrats actifs" statuses and
        // apply them, so the very first KPI / chart / table / count / export
        // requests carry exactly what the STATUT checkboxes show.
        window.frdate.clear(els.dateFrom); window.frdate.clear(els.dateTo); els.search.value = '';
        MS_DIMS.forEach(function (d) { ms[d].clear(); });
        state.applied = {};
        state.table.search = '';
        state.table.page = 1;
        renderChips();
        loadFilterOptions().then(function () {
            ms.status.setValues(DEFAULTS.initialStatuses(state.options.statuses, ACTIVE_STATUSES));
            state.applied = readForm();
            renderChips();
            loadSegmentationCounts();
            return Promise.all([loadStats().catch(handleError), loadRows().catch(handleError)]);
        }).catch(handleError);
    }

    bootstrapAll();
    setInterval(updateCacheNote, 30000);
})();
