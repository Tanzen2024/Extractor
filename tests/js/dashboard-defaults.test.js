// Run: node --test tests/js/dashboard-defaults.test.js
// Initial STATUT selection (public/assets/js/dashboard-defaults.js) and what
// dashboard.js actually sends on first load, driven through a minimal fake DOM.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { initialStatuses } = require('../../public/assets/js/dashboard-defaults.js');

const ROOT = path.join(__dirname, '../..');
const ACTIVE = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)'];
const INACTIVE = ['INACTIVE WITH BALANCE.', 'INACTIVE.', 'IN PROCESS (PENDING CONNECTION)', 'IN PROCESS (PENDING READING)'];
// Real STATUT options of the active snapshot (20260927T183827), backend count order.
const STATUS_OPTIONS = [
    { value: 'ACTIVE', count: 1453055 }, { value: 'INACTIVE WITH BALANCE.', count: 823486 },
    { value: 'ACTIVE (PENDING BILLING)', count: 718633 }, { value: 'SUSPENDED (DELINQUENT ACCOUNT)', count: 254623 },
    { value: 'INACTIVE.', count: 49228 }, { value: 'INACTIVATION IN PROCESS.', count: 3342 },
    { value: 'IN PROCESS (PENDING CONNECTION)', count: 473 }, { value: 'IN PROCESS (PENDING READING)', count: 1 },
];

test('initialStatuses: the 4 active statuses, none of the inactive ones', () => {
    const s = initialStatuses(STATUS_OPTIONS, ACTIVE);
    assert.deepEqual([...s].sort(), [...ACTIVE].sort());
    INACTIVE.forEach((v) => assert.ok(!s.includes(v), v));
});

test('initialStatuses: never pre-checks a value the filter does not offer (backend would 422)', () => {
    assert.deepEqual(initialStatuses([{ value: 'ACTIVE' }, { value: 'INACTIVE.' }], ACTIVE), ['ACTIVE']);
    assert.deepEqual(initialStatuses([], ACTIVE), []);
    assert.deepEqual(initialStatuses(null, ACTIVE), []);
});

test('the JS active list matches Config\\Oracle::$activeStatuses', () => {
    const cfg = fs.readFileSync(path.join(ROOT, 'app/Config/Oracle.php'), 'utf8');
    const block = /\$activeStatuses = \[([\s\S]*?)\];/.exec(cfg)[1];
    const php = [...block.matchAll(/'([^']+)'/g)].map((m) => m[1]);
    const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');
    const jsList = JSON.parse(/var ACTIVE_STATUSES = (\[[^\]]*\]);/.exec(js)[1].replace(/'/g, '"'));
    assert.deepEqual([...jsList].sort(), [...php].sort());
    assert.deepEqual([...jsList].sort(), [...ACTIVE].sort());
});

test('view: no Référentiel line, no Oracle/snapshot banner, NOMBRE DE NUI CORRECTS card', () => {
    const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
    const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');
    for (const src of [view, js]) {
        assert.ok(!src.includes('Référentiel :'));
        assert.ok(!src.includes('bscdSourceNote') && !src.includes('bscdSourceWarning') && !src.includes('bscdExportSource'));
        assert.ok(!src.includes('La base Oracle en direct'));
    }
    assert.ok(view.includes("'NOMBRE DE NUI CORRECTS'"));
    // "Total clients" is back as a conditional card: hidden unless STATUT differs
    // from exactly the 4 active statuses (see the KPI layout tests below).
    assert.match(view, /\['total', 'Total clients'/);
    assert.ok(view.indexOf('assets/js/dashboard-defaults.js') < view.indexOf("assets/js/dashboard.js')"));
});

// ── dashboard.js in a fake DOM ────────────────────────────────────────

function unescapeHtml(s) {
    return s.replace(/&(amp|lt|gt|quot|#39);/g, (m, e) => ({ amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'" })[e]);
}

function makeEl(dataset = {}) {
    const listeners = {};
    const cls = new Set();
    const el = {
        dataset, style: {}, value: '', textContent: '', _html: '', checked: false, scrollWidth: 0, clientWidth: 0,
        classList: {
            add: (...c) => c.forEach((x) => cls.add(x)), remove: (...c) => c.forEach((x) => cls.delete(x)),
            toggle: (c, on) => ((on === undefined ? !cls.has(c) : on) ? cls.add(c) : cls.delete(c)), contains: (c) => cls.has(c),
        },
        get innerHTML() { return this._html; },
        set innerHTML(v) { this._html = String(v); this._checkboxes = null; this._sel = {}; },
        _sel: {},
        addEventListener: (t, f) => { (listeners[t] = listeners[t] || []).push(f); },
        fire(t) { (listeners[t] || []).forEach((f) => f({ preventDefault() {}, stopPropagation() {}, target: el })); },
        querySelector(sel) { return this._sel[sel] || (this._sel[sel] = makeEl()); },
        querySelectorAll(sel) {
            if (sel !== 'input[type=checkbox]') return [];
            if (!this._checkboxes) {
                this._checkboxes = [...this._html.matchAll(/<input type="checkbox" value="([^"]*)" (checked)?>/g)].map((m) => {
                    const cb = makeEl();
                    cb.value = unescapeHtml(m[1]);
                    cb.checked = m[2] === 'checked';
                    return cb;
                });
            }
            return this._checkboxes;
        },
        contains: () => true, setAttribute() {}, removeAttribute() {}, getAttribute: () => null,
        appendChild() {}, getContext: () => ({}), getBoundingClientRect: () => ({ top: 0 }), focus() {},
    };
    return el;
}

function bootDashboard(overrides = {}, extraEndpoints = {}) {
    const byId = {};
    const bySel = {};
    const requests = [];
    const charts = {}; // canvas id -> last Chart config created on it
    const boot = {
        endpoints: { stats: '/dashboard/stats', count: '/dashboard/count', rows: '/dashboard/rows', filterOptions: '/dashboard/filter-options', export: '/dashboard/export', jobStatus: '/exports', ...extraEndpoints },
        sortable: ['CONTRACT'], tableColumns: ['CONTRACT', 'STATUS'], defaultVisibleColumns: ['CONTRACT', 'STATUS'], perPageOptions: [20, 50, 100, 200],
    };
    const document = {
        getElementById(id) {
            if (!byId[id]) {
                byId[id] = makeEl();
                if (id === 'bscd-dashboard-data') byId[id].textContent = JSON.stringify(boot);
                if (id.startsWith('bscdChart')) { byId[id].parentNode = makeEl(); byId[id].getContext = () => ({ canvas: id }); }
            }
            return byId[id];
        },
        querySelector(sel) {
            if (!bySel[sel]) {
                const ds = {};
                const m = /\[data-([a-z-]+)="([^"]*)"\]/.exec(sel);
                if (m) ds[m[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = m[2];
                bySel[sel] = makeEl(ds);
            }
            return bySel[sel];
        },
        querySelectorAll: () => [],
        addEventListener() {},
        createElement: () => makeEl(),
    };
    const responses = {
        '/dashboard/filter-options': { regions: [], statuses: STATUS_OPTIONS, segmentations: [], segmentsTresor: [], meters: [], voltages: [], niuQualities: [{ value: 'NUI CORRECT', count: 2624178 }], geoTree: {}, dateBounds: {} },
        '/dashboard/stats': { totalRows: 10, kpis: { total: 10, totalPct: 40, nuiCorrects: { value: 7, pct: 70 }, actifs: { value: 10, pct: 100 }, avecCompteur: { value: 4, pct: 40 }, contacts: { value: 2, pct: 20 } }, charts: { region: [], status: [], segmentation: [], meterType: [] } },
        '/dashboard/count': { count: 10 },
        '/dashboard/rows': { data: [], columns: ['CONTRACT', 'STATUS'], page: 1, perPage: 50, total: 10, sort: 'CONTRACT', dir: 'asc', search: '' },
    };
    // An override may be a function (url) => body, for answers that depend on the query.
    Object.keys(overrides).forEach((k) => { responses[k] = typeof overrides[k] === 'function' ? overrides[k] : { ...responses[k], ...overrides[k] }; });
    const sandbox = {
        document, JSON, Math, Date, Object, Array, String, Number, Promise, URLSearchParams, console,
        setTimeout, clearTimeout, setInterval: () => 0, clearInterval() {},
        localStorage: { getItem: () => null, setItem() {} },
        Chart: function (ctx, config) { charts[ctx.canvas] = config; this.destroy = () => { if (charts[ctx.canvas] === config) delete charts[ctx.canvas]; }; },
        $: () => ({ modal() {} }),
        frdate: { clear() {}, setBounds() {} },
        bscdExportProgress: require('../../public/assets/js/export-progress.js'),
        bscdDashboardDefaults: require('../../public/assets/js/dashboard-defaults.js'),
        bscdFetch(url) {
            requests.push(url);
            const key = Object.keys(responses).find((k) => url.startsWith(k));
            const body = typeof responses[key] === 'function' ? responses[key](url) : responses[key];
            const clone = (b) => JSON.parse(JSON.stringify(b));
            return body && typeof body.then === 'function' ? body.then(clone) : Promise.resolve(clone(body));
        },
    };
    sandbox.window = sandbox;
    sandbox.addEventListener = () => {};
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8'), sandbox);
    const statusList = document.querySelector('.bscd-ms[data-dim="status"]').querySelector('.bscd-ms__list');
    return { document, requests, statusList, byId, charts };
}

const settle = () => new Promise((r) => setTimeout(r, 20));
const statusesOf = (url) => new URLSearchParams(url.split('?')[1] || '').getAll('status[]');

test('first load: the 4 active boxes are checked, the inactive ones are not', async () => {
    const { statusList } = bootDashboard();
    await settle();
    const boxes = statusList.querySelectorAll('input[type=checkbox]');
    assert.equal(boxes.length, 8);
    boxes.forEach((cb) => assert.equal(cb.checked, ACTIVE.includes(cb.value), cb.value));
});

test('first load: stats, count and rows are all requested with exactly the 4 active statuses', async () => {
    const { requests } = bootDashboard();
    await settle();
    ['/dashboard/stats', '/dashboard/count', '/dashboard/rows'].forEach((ep) => {
        const calls = requests.filter((u) => u.startsWith(ep));
        assert.equal(calls.length, 1, ep);
        assert.deepEqual([...statusesOf(calls[0])].sort(), [...ACTIVE].sort(), ep);
    });
});

test('first load: the NUI card shows the stats figure', async () => {
    const { document } = bootDashboard();
    await settle();
    assert.equal(document.querySelector('[data-kpi="nuiCorrects"]').textContent, '7');
    assert.equal(document.querySelector('[data-kpi-sub="nuiCorrects"]').textContent, '70,0 % du total'); // NUI corrects / all NUI (backend pct)
});

test('the user can uncheck / recheck: the next apply sends exactly the new selection', async () => {
    const { statusList, requests, byId } = bootDashboard();
    await settle();
    statusList.querySelectorAll('input[type=checkbox]').find((c) => c.value === 'ACTIVE').fire('change'); // checked stays true -> no-op
    const active = statusList.querySelectorAll('input[type=checkbox]').find((c) => c.value === 'ACTIVE');
    active.checked = false; active.fire('change');
    const inactive = statusList.querySelectorAll('input[type=checkbox]').find((c) => c.value === 'INACTIVE.');
    inactive.checked = true; inactive.fire('change');
    requests.length = 0;
    byId.bscdFilters.fire('submit');
    await settle();
    const expected = ['ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVE.'].sort();
    ['/dashboard/stats', '/dashboard/count', '/dashboard/rows'].forEach((ep) => {
        const call = requests.find((u) => u.startsWith(ep));
        assert.deepEqual([...statusesOf(call)].sort(), expected, ep);
    });
});

// ── KPI layout: "Total clients" only when STATUT ≠ exactly the 4 active statuses ──

const { isExactlyActiveStatuses } = require('../../public/assets/js/dashboard-defaults.js');

test('isExactlyActiveStatuses: all 4 active statuses and nothing else, in any order', () => {
    assert.equal(isExactlyActiveStatuses([...ACTIVE], ACTIVE), true);
    assert.equal(isExactlyActiveStatuses([...ACTIVE].reverse(), ACTIVE), true);
    assert.equal(isExactlyActiveStatuses(ACTIVE.slice(0, 3), ACTIVE), false);            // one missing
    assert.equal(isExactlyActiveStatuses([...ACTIVE, 'INACTIVE.'], ACTIVE), false);      // one extra
    assert.equal(isExactlyActiveStatuses(['INACTIVE.', 'ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.'], ACTIVE), false);
    assert.equal(isExactlyActiveStatuses(undefined, ACTIVE), false);                     // no STATUT filter
    assert.equal(isExactlyActiveStatuses([], ACTIVE), false);
});

test('KPI row, first load (exactly the 4 active statuses): 3 cards, "Total clients" hidden', async () => {
    const { document } = bootDashboard();
    await settle();
    assert.equal(document.querySelector('[data-kpi-col="total"]').classList.contains('d-none'), true);
    assert.equal(document.querySelector('[data-kpi="actifs"]').textContent, '10');
});

test('KPI row, another STATUT selection: 4 cards, "Total clients" shown with the stats total', async () => {
    const { statusList, byId, document } = bootDashboard();
    await settle();
    const inactive = statusList.querySelectorAll('input[type=checkbox]').find((c) => c.value === 'INACTIVE.');
    inactive.checked = true; inactive.fire('change');
    byId.bscdFilters.fire('submit');
    await settle();
    assert.equal(document.querySelector('[data-kpi-col="total"]').classList.contains('d-none'), false);
    assert.equal(document.querySelector('[data-kpi="total"]').textContent, '10');
    assert.equal(document.querySelector('[data-kpi-sub="total"]').textContent, '40,0 % du total'); // selected / global reference total (backend totalPct)
});

// ── Segmentation groups: Type de compteur -> group label (display only) ──

const METER_OPTIONS = [{ value: 'POSTPAID', count: 2 }, { value: 'PREPAID', count: 2 }, { value: 'Compteurs Communicants', count: 1 }];
const SEG_OPTIONS = [
    { value: '1 PERFECT', count: 5 }, { value: '2 RELIABLE', count: 4 }, { value: '8 Autre', count: 3 },
    { value: '5-Dormant', count: 2 }, { value: 'Other', count: 1 },
];

async function bootWithMeters(selectedMeters) {
    const ctx = bootDashboard({ '/dashboard/filter-options': { meters: METER_OPTIONS, segmentations: SEG_OPTIONS } });
    await settle();
    const list = (dim) => ctx.document.querySelector('.bscd-ms[data-dim="' + dim + '"]').querySelector('.bscd-ms__list');
    list('meter').querySelectorAll('input[type=checkbox]').forEach((cb) => {
        if (selectedMeters.includes(cb.value)) { cb.checked = true; cb.fire('change'); }
    });
    const html = list('segmentation').innerHTML;
    return {
        groups: [...html.matchAll(/<div class="bscd-ms__group-label">([^<]*)<\/div>/g)].map((m) => unescapeHtml(m[1])),
        options: [...html.matchAll(/<input type="checkbox" value="([^"]*)"/g)].map((m) => unescapeHtml(m[1])),
    };
}

// The 8 PREPAID categories, business order — always offered by the dropdown.
const PREPAID_8 = ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'];

test('segmentation group, Type de compteur = Toutes: "Postpaid | Compteurs communicants", Prepaid, every option + the 8 PREPAID', async () => {
    const r = await bootWithMeters([]);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants', 'Prepaid']);
    const expected = new Set(SEG_OPTIONS.map((o) => o.value).concat(PREPAID_8));
    assert.deepEqual([...r.options].sort(), [...expected].sort());
});

test('segmentation group, PREPAID only: "Prepaid", the 8 PREPAID categories in business order (backend sent 2)', async () => {
    const r = await bootWithMeters(['PREPAID']);
    assert.deepEqual(r.groups, ['Prepaid']);
    assert.deepEqual(r.options, PREPAID_8);
});

test('segmentation group, POSTPAID only: "Postpaid", POSTPAID segmentations only', async () => {
    const r = await bootWithMeters(['POSTPAID']);
    assert.deepEqual(r.groups, ['Postpaid']);
    assert.deepEqual(r.options, ['1 PERFECT', '2 RELIABLE', '8 Autre']);
});

test('segmentation group, communicants only: "Compteurs communicants", still the POSTPAID segmentation', async () => {
    const r = await bootWithMeters(['Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Compteurs communicants']);
    assert.deepEqual(r.options, ['1 PERFECT', '2 RELIABLE', '8 Autre']);
});

test('segmentation group, POSTPAID + communicants: "Postpaid | Compteurs communicants"', async () => {
    const r = await bootWithMeters(['POSTPAID', 'Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants']);
    assert.deepEqual(r.options, ['1 PERFECT', '2 RELIABLE', '8 Autre']);
});

test('segmentation group, PREPAID + communicants: Prepaid group untouched, communicants label on the POSTPAID group', async () => {
    const r = await bootWithMeters(['PREPAID', 'Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Compteurs communicants', 'Prepaid']);
});

// ── Segmentation option counts: scoped to the filters in the form ──
// Real Oracle proportions (30/09): PERFECT = 28 795 POSTPAID + 1 248 communicants.

const SCOPED = {
    '':                                                    { '1 PERFECT': 30043, '2 RELIABLE': 159220, '8 Autre': 817910, '5-Dormant': 1091001, Other: 3890 },
    'POSTPAID':                                            { '1 PERFECT': 28795, '2 RELIABLE': 152163, '8 Autre': 816038 },
    'Compteurs Communicants':                              { '1 PERFECT': 1248, '2 RELIABLE': 7057, '8 Autre': 1872 },
    'POSTPAID|Compteurs Communicants':                     { '1 PERFECT': 30043, '2 RELIABLE': 159220, '8 Autre': 817910 },
    'PREPAID':                                             { '5-Dormant': 1091001, Other: 3890 },
};

async function bootScoped(selectedMeters, extra = () => {}) {
    const seen = [];
    const ctx = bootDashboard({
        '/dashboard/filter-options': { meters: METER_OPTIONS, segmentations: SEG_OPTIONS, regions: [{ value: 'DCUD', count: 1 }] },
        '/dashboard/segmentation-counts': (url) => {
            const q = new URLSearchParams(url.split('?')[1] || '');
            seen.push(q);
            const byValue = SCOPED[q.getAll('meter[]').join('|')] || {};
            const factor = q.getAll('region[]').length ? 0.5 : 1; // a region narrows every count
            return { counts: Object.keys(byValue).map((value) => ({ value, count: Math.floor(byValue[value] * factor) })) };
        },
    }, { segmentationCounts: '/dashboard/segmentation-counts' });
    await settle();
    const list = (dim) => ctx.document.querySelector('.bscd-ms[data-dim="' + dim + '"]').querySelector('.bscd-ms__list');
    list('meter').querySelectorAll('input[type=checkbox]').forEach((cb) => {
        if (selectedMeters.includes(cb.value)) { cb.checked = true; cb.fire('change'); }
    });
    await extra(ctx, list);
    await settle();
    const html = list('segmentation').innerHTML;
    const counts = {};
    for (const m of html.matchAll(/<input type="checkbox" value="([^"]*)"[^>]*><span>[^<]*<\/span><em>([^<]*)<\/em>/g)) counts[unescapeHtml(m[1])] = m[2];
    return {
        seen, counts,
        groups: [...html.matchAll(/<div class="bscd-ms__group-label">([^<]*)<\/div>/g)].map((m) => unescapeHtml(m[1])),
    };
}

const n = (v) => v.toLocaleString('fr-FR');

test('segment counts, Type = POSTPAID: POSTPAID numbers, not the global ones', async () => {
    const r = await bootScoped(['POSTPAID']);
    assert.deepEqual(r.groups, ['Postpaid']);
    assert.deepEqual(r.counts, { '1 PERFECT': n(28795), '2 RELIABLE': n(152163), '8 Autre': n(816038) });
});

test('segment counts, Type = Compteurs communicants: smart-meter numbers under the POSTPAID segmentation', async () => {
    const r = await bootScoped(['Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Compteurs communicants']);
    assert.deepEqual(r.counts, { '1 PERFECT': n(1248), '2 RELIABLE': n(7057), '8 Autre': n(1872) });
});

test('segment counts, POSTPAID + communicants: the combined meter scope (same rule as the filter)', async () => {
    const r = await bootScoped(['POSTPAID', 'Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants']);
    assert.deepEqual(r.counts, { '1 PERFECT': n(30043), '2 RELIABLE': n(159220), '8 Autre': n(817910) });
});

test('segment counts, Type = Toutes: scope of every meter, group "Postpaid | Compteurs communicants"', async () => {
    const r = await bootScoped([]);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants', 'Prepaid']);
    assert.equal(r.counts['1 PERFECT'], n(30043));
    assert.equal(r.counts['5-Dormant'], n(1091001));
});

test('segment counts, PREPAID: real numbers for the returned values, 0 for the 6 others, all 8 listed', async () => {
    const r = await bootScoped(['PREPAID']);
    assert.deepEqual(r.counts, {
        '1-Stable': '0', '2-InStable': '0', '3-At Risk': '0', '4-Suspect Dormant': '0',
        '5-Dormant': n(1091001), '6 Old_Dormant': '0', '7 Never Vending': '0', Other: n(3890),
    });
});

test('segment counts follow the other form filters (region), and never send the segmentation filter', async () => {
    const r = await bootScoped(['POSTPAID'], async (ctx, list) => {
        list('segmentation').querySelectorAll('input[type=checkbox]').find((c) => c.value === '1 PERFECT').fire('change');
        list('region').querySelectorAll('input[type=checkbox]').forEach((cb) => { cb.checked = true; cb.fire('change'); });
        ctx.document.querySelector('.bscd-ms[data-dim="segmentation"]').querySelector('.bscd-ms__button').fire('click'); // opening refreshes
    });
    const last = r.seen[r.seen.length - 1];
    assert.deepEqual(last.getAll('region[]'), ['DCUD']);
    assert.deepEqual(last.getAll('meter[]'), ['POSTPAID']);
    assert.ok(r.seen.every((q) => q.getAll('segmentation[]').length === 0));
    assert.equal(r.counts['1 PERFECT'], n(Math.floor(28795 * 0.5)));
});

// ── "Répartition par segmentation": strict business order, filtered scope ──

// Every value of the data (Oracle 30/09), in volume order as the API sends it.
const ALL_SEGS = [
    ['5-Dormant', 1091001], ['8 Autre', 817910], ['4 At-Risk', 234045], ['5 Delinquant', 229204], ['3 Occasionnal', 206345],
    ['8 Never Paid', 196876], ['2 RELIABLE', 159220], ['7 sometime Paid', 141863], ['6 Old_Dormant', 131112],
    ['6 Always Late', 52889], ['1 PERFECT', 30043], ['7 Never Vending', 9888], ['Other', 3890],
].map(([value, count]) => ({ value, count }));
// The 8 PREPAID SQL values (App\Services\CustomersList\PrepaidSegmentations),
// business order; only the last 4 exist in the data today.
const PREPAID_VALUES = ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'];
const POSTPAID_ORDER = ['1 PERFECT', '2 RELIABLE', '3 Occasionnal', '4 At-Risk', '5 Delinquant', '6 Always Late', '8 Never Paid', '7 sometime Paid', '8 Autre'];
// Chart labels of the PREPAID bars: display labels, no numeric prefix.
const PREPAID_ORDER = ['Stable', 'Instable', 'À risque', 'Suspect Dormant', 'Dormant', 'Old Dormant', 'Never Vending', 'Other'];

// stats answer for a query: segmentation = the values of the requested
// meters / segmentations, in VOLUME order (what the API really returns).
function statsFor(url, { zero = [], empty = false } = {}) {
    const q = new URLSearchParams(url.split('?')[1] || '');
    const meters = q.getAll('meter[]');
    const segs = q.getAll('segmentation[]');
    const withPrepaid = !meters.length || meters.includes('PREPAID');
    const withPostpaid = !meters.length || meters.some((m) => m !== 'PREPAID');
    const seg = empty ? [] : ALL_SEGS
        .filter((p) => (PREPAID_VALUES.includes(p.value) ? withPrepaid : withPostpaid))
        .filter((p) => !segs.length || segs.includes(p.value))
        .filter((p) => !zero.includes(p.value));
    const total = seg.reduce((a, p) => a + p.count, 0);
    return {
        totalRows: total,
        kpis: { total, totalPct: 0, nuiCorrects: { value: 0, pct: 0 }, actifs: { value: 0, pct: 0 }, avecCompteur: { value: 0, pct: 0 }, contacts: { value: 0, pct: 0 } },
        charts: {
            region: empty ? [] : [{ value: 'DCUD', count: total }],
            status: empty ? [] : [{ value: 'ACTIVE', count: total }],
            segmentation: seg,
            meterType: empty ? [] : [{ value: 'POSTPAID', count: total }],
        },
    };
}

const CHART_METERS = [{ value: 'POSTPAID', count: 2 }, { value: 'PREPAID', count: 2 }, { value: 'COMPTEURS COMMUNICANTS', count: 1 }];

function msList(ctx, dim) {
    return ctx.document.querySelector('.bscd-ms[data-dim="' + dim + '"]').querySelector('.bscd-ms__list');
}

async function applyScope(meters, segs = [], statsOpts = {}) {
    const ctx = bootDashboard({
        '/dashboard/filter-options': { meters: CHART_METERS, segmentations: ALL_SEGS },
        '/dashboard/stats': (url) => statsFor(url, statsOpts),
    });
    await settle();
    msList(ctx, 'meter').querySelectorAll('input[type=checkbox]').forEach((cb) => { if (meters.includes(cb.value)) { cb.checked = true; cb.fire('change'); } });
    msList(ctx, 'segmentation').querySelectorAll('input[type=checkbox]').forEach((cb) => { if (segs.includes(cb.value)) { cb.checked = true; cb.fire('change'); } });
    ctx.byId.bscdFilters.fire('submit');
    await settle();
    const chart = ctx.charts.bscdChartSegmentation;
    return {
        ctx,
        labels: chart ? [...chart.data.labels] : null,
        counts: chart ? [...chart.data.datasets[0].data] : null,
        message: ctx.byId.bscdChartSegmentation.parentNode.dataset.chartMsg,
    };
}

test('segmentation chart, POSTPAID: the 9 POSTPAID segments in the business order, not by volume', async () => {
    const r = await applyScope(['POSTPAID']);
    assert.deepEqual(r.labels, POSTPAID_ORDER);
    assert.notDeepEqual(r.labels.slice(0, 3), ['8 Autre', '4 At-Risk', '5 Delinquant']); // volume order is forbidden
    assert.equal(r.counts[0], 30043);
    assert.equal(r.message, undefined);
});

test('segmentation chart, Compteurs communicants: the POSTPAID segmentation and order', async () => {
    const r = await applyScope(['COMPTEURS COMMUNICANTS']);
    assert.deepEqual(r.labels, POSTPAID_ORDER);
});

test('segmentation chart, PREPAID: the 8 categories, business order, display labels, absent ones at 0', async () => {
    const r = await applyScope(['PREPAID']);
    assert.deepEqual(r.labels, PREPAID_ORDER);
    assert.deepEqual(r.counts, [0, 0, 0, 0, 1091001, 131112, 9888, 3890]); // never by volume
});

test('segmentation chart, PREPAID, Dormant + Old Dormant ticked: only those two, in order', async () => {
    const r = await applyScope(['PREPAID'], ['6 Old_Dormant', '5-Dormant']);
    assert.deepEqual(r.labels, ['Dormant', 'Old Dormant']);
    assert.deepEqual(r.counts, [1091001, 131112]);
});

test('PREPAID values and labels match PrepaidSegmentations.php, in the same order', () => {
    const php = fs.readFileSync(path.join(ROOT, 'app/Services/CustomersList/PrepaidSegmentations.php'), 'utf8');
    const block = /const LABELS = \[([\s\S]*?)\];/.exec(php)[1];
    const pairs = [...block.matchAll(/'([^']+)'\s*=>\s*'([^']+)'/g)].map((m) => [m[1], m[2]]);
    assert.deepEqual(pairs.map((p) => p[0]), PREPAID_VALUES);
    assert.deepEqual(pairs.map((p) => p[1]), PREPAID_ORDER);
});

test('Segmentation filter, PREPAID: the 8 options in the business order, labelled, technical values kept', async () => {
    const ctx = bootDashboard({
        // What the backend sends: data values + the 4 absent categories at 0.
        '/dashboard/filter-options': { meters: CHART_METERS, segmentations: ALL_SEGS.concat(PREPAID_VALUES.slice(0, 4).map((value) => ({ value, count: 0 }))) },
        '/dashboard/stats': (url) => statsFor(url),
    });
    await settle();
    const meter = msList(ctx, 'meter').querySelectorAll('input[type=checkbox]').find((c) => c.value === 'PREPAID');
    meter.checked = true; meter.fire('change');
    const html = msList(ctx, 'segmentation').innerHTML;
    const opts = [...html.matchAll(/<input type="checkbox" value="([^"]*)"[^>]*><span>([^<]*)<\/span>/g)].map((m) => [unescapeHtml(m[1]), unescapeHtml(m[2])]);
    assert.deepEqual(opts.map((o) => o[0]), PREPAID_VALUES);
    assert.deepEqual(opts.map((o) => o[1]), PREPAID_ORDER);
});

// ── Segmentation filter, PREPAID: the 8 categories are guaranteed by the
// frontend, even when the backend only lists the values present in the data.

// Figures of the validated customers_list fix (PREPAID, 4 active statuses);
// 4-Suspect Dormant, 5-Dormant and Other are absent from the data.
const CURRENT_PREPAID = { '1-Stable': 252345, '2-InStable': 251888, '3-At Risk': 425372, '6 Old_Dormant': 151490, '7 Never Vending': 11192 };
const CURRENT_POSTPAID = { '1 PERFECT': 28570, '4 At-Risk': 223375 };

async function bootCurrent() {
    const seen = [];
    const ctx = bootDashboard({
        // No completion here: only the values present in the data.
        '/dashboard/filter-options': {
            meters: METER_OPTIONS, regions: [{ value: 'DCUD', count: 1 }],
            segmentations: Object.keys({ ...CURRENT_POSTPAID, ...CURRENT_PREPAID }).map((value) => ({ value, count: 1 })),
        },
        '/dashboard/segmentation-counts': (url) => {
            const q = new URLSearchParams(url.split('?')[1] || '');
            seen.push(q);
            const meters = q.getAll('meter[]');
            const src = !meters.length ? { ...CURRENT_POSTPAID, ...CURRENT_PREPAID }
                : (meters.includes('PREPAID') ? CURRENT_PREPAID : CURRENT_POSTPAID);
            return { counts: Object.entries(src).map(([value, count]) => ({ value, count })) };
        },
        '/dashboard/stats': (url) => statsFor(url),
    }, { segmentationCounts: '/dashboard/segmentation-counts' });
    await settle();
    const tick = (dim, value, on = true) => {
        const cb = msList(ctx, dim).querySelectorAll('input[type=checkbox]').find((c) => c.value === value);
        cb.checked = on; cb.fire('change');
    };
    const read = () => {
        const html = msList(ctx, 'segmentation').innerHTML;
        const opts = [...html.matchAll(/<input type="checkbox" value="([^"]*)"[^>]*><span>([^<]*)<\/span><em>([^<]*)<\/em>/g)]
            .map((m) => ({ value: unescapeHtml(m[1]), label: unescapeHtml(m[2]), count: m[3] }));
        return {
            groups: [...html.matchAll(/<div class="bscd-ms__group-label">([^<]*)<\/div>/g)].map((m) => unescapeHtml(m[1])),
            values: opts.map((o) => o.value), labels: opts.map((o) => o.label), counts: opts.map((o) => o.count),
        };
    };
    const statsParams = () => {
        const urls = ctx.requests.filter((u) => u.startsWith('/dashboard/stats'));
        return new URLSearchParams(urls[urls.length - 1].split('?')[1] || '');
    };
    const apply = async () => { ctx.byId.bscdFilters.fire('submit'); await settle(); };
    return { ctx, seen, tick, read, statsParams, apply };
}

test('PREPAID filter, current data: exactly the 8 categories, business order, labels, real counts and 0 for the absent ones', async () => {
    const r = await bootCurrent();
    r.tick('meter', 'PREPAID');
    await settle();
    const s = r.read();
    assert.deepEqual(s.groups, ['Prepaid']);
    assert.equal(s.values.length, 8);
    assert.deepEqual(s.values, PREPAID_VALUES);
    assert.deepEqual(s.labels, PREPAID_ORDER);
    assert.deepEqual(s.counts, [n(252345), n(251888), n(425372), '0', '0', n(151490), n(11192), '0']);
    // No technical value shown to the user (numeric prefixes, underscores).
    assert.ok(s.labels.every((l) => !/^\d/.test(l) && !l.includes('_')));
});

test('PREPAID filter + a segment ticked: the 8 options stay, the technical value is sent, counts ignore the segmentation filter', async () => {
    const r = await bootCurrent();
    r.tick('meter', 'PREPAID');
    await settle();
    r.tick('segmentation', '1-Stable');
    await r.apply();
    assert.deepEqual(r.statsParams().getAll('segmentation[]'), ['1-Stable']);
    assert.deepEqual(r.statsParams().getAll('meter[]'), ['PREPAID']);
    assert.deepEqual(r.read().values, PREPAID_VALUES);
    assert.ok(r.seen.every((q) => q.getAll('segmentation[]').length === 0));
});

test('PREPAID filter, no segmentation ticked: no segmentation filter sent', async () => {
    const r = await bootCurrent();
    r.tick('meter', 'PREPAID');
    await r.apply();
    assert.deepEqual(r.statsParams().getAll('segmentation[]'), []);
    assert.deepEqual(r.read().values, PREPAID_VALUES);
});

test('Type de compteur POSTPAID -> PREPAID -> POSTPAID: each switch reloads the right list and counts', async () => {
    const r = await bootCurrent();
    r.tick('meter', 'POSTPAID');
    await settle();
    let s = r.read();
    assert.deepEqual(s.values, ['1 PERFECT', '4 At-Risk']);
    assert.deepEqual(s.counts, [n(28570), n(223375)]);

    r.tick('meter', 'POSTPAID', false);
    r.tick('meter', 'PREPAID');
    await settle();
    s = r.read();
    assert.deepEqual(s.values, PREPAID_VALUES);
    assert.deepEqual(s.counts, [n(252345), n(251888), n(425372), '0', '0', n(151490), n(11192), '0']);

    r.tick('meter', 'PREPAID', false);
    r.tick('meter', 'POSTPAID');
    await settle();
    s = r.read();
    assert.deepEqual(s.groups, ['Postpaid']);
    assert.deepEqual(s.values, ['1 PERFECT', '4 At-Risk']);
});

test('PREPAID filter + another filter (region): counts follow the region, the 8 categories stay', async () => {
    const r = await bootCurrent();
    r.tick('region', 'DCUD');
    r.tick('meter', 'PREPAID');
    await settle();
    const last = r.seen[r.seen.length - 1];
    assert.deepEqual(last.getAll('region[]'), ['DCUD']);
    assert.deepEqual(last.getAll('meter[]'), ['PREPAID']);
    assert.deepEqual(r.read().values, PREPAID_VALUES);
});

test('PREPAID filter: a category at 0 can be ticked and unticked like any other', async () => {
    const r = await bootCurrent();
    r.tick('meter', 'PREPAID');
    await settle();
    r.tick('segmentation', '4-Suspect Dormant');
    await r.apply();
    assert.deepEqual(r.statsParams().getAll('segmentation[]'), ['4-Suspect Dormant']);
    r.tick('segmentation', '4-Suspect Dormant', false);
    await r.apply();
    assert.deepEqual(r.statsParams().getAll('segmentation[]'), []);
});

test('segmentation chart, Toutes: POSTPAID order then PREPAID order, every segment, no "Autres" bucket', async () => {
    const r = await applyScope([]);
    assert.deepEqual(r.labels, POSTPAID_ORDER.concat(PREPAID_ORDER));
    assert.equal(r.counts.reduce((a, b) => a + b, 0), ALL_SEGS.reduce((a, p) => a + p.count, 0));
});

test('segmentation chart: a segment empty in the scope keeps its slot, at 0', async () => {
    const r = await applyScope(['POSTPAID'], [], { zero: ['3 Occasionnal'] });
    assert.deepEqual(r.labels, POSTPAID_ORDER);
    assert.equal(r.counts[2], 0);
});

test('segmentation chart, Segmentation = PERFECT: only PERFECT, the other segments are not shown back', async () => {
    const r = await applyScope(['POSTPAID'], ['1 PERFECT']);
    assert.deepEqual(r.labels, ['1 PERFECT']);
    assert.deepEqual(r.counts, [30043]);
});

test('empty scope: every chart shows the empty-state message, no chart and no global fallback', async () => {
    const r = await applyScope(['POSTPAID'], [], { empty: true });
    const msg = 'Aucune donnée disponible pour les filtres sélectionnés.';
    ['bscdChartRegion', 'bscdChartStatus', 'bscdChartSegmentation'].forEach((id) => {
        assert.equal(r.ctx.charts[id], undefined, id);
        assert.equal(r.ctx.byId[id].parentNode.dataset.chartMsg, msg, id);
    });
    assert.match(r.ctx.document.querySelector('[data-metertype="list"]').innerHTML, /Aucune donnée disponible pour les filtres sélectionnés/);
});

test('a slow answer for an older filter set never overwrites the current charts; loading hides the old ones', async () => {
    let releaseOld = null;
    const ctx = bootDashboard({
        '/dashboard/filter-options': { meters: CHART_METERS, segmentations: ALL_SEGS },
        '/dashboard/stats': (url) => (new URLSearchParams(url.split('?')[1] || '').getAll('meter[]').includes('PREPAID')
            ? new Promise((res) => { releaseOld = () => res(statsFor(url)); })
            : statsFor(url)),
    });
    await settle();
    const tick = (value, on) => {
        const cb = msList(ctx, 'meter').querySelectorAll('input[type=checkbox]').find((c) => c.value === value);
        cb.checked = on; cb.fire('change');
    };

    tick('PREPAID', true); ctx.byId.bscdFilters.fire('submit'); // slow answer, still pending
    await settle();
    assert.equal(ctx.charts.bscdChartSegmentation, undefined, 'old chart removed while loading');
    assert.equal(ctx.byId.bscdChartSegmentation.parentNode.dataset.chartMsg, 'Chargement…');

    tick('PREPAID', false); tick('POSTPAID', true); ctx.byId.bscdFilters.fire('submit'); // fast answer
    await settle();
    assert.deepEqual([...ctx.charts.bscdChartSegmentation.data.labels], POSTPAID_ORDER);

    releaseOld(); await settle(); // the old answer lands last
    assert.deepEqual([...ctx.charts.bscdChartSegmentation.data.labels], POSTPAID_ORDER, 'still the POSTPAID scope');
});
