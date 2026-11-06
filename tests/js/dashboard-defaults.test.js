// Run: node --test tests/js/dashboard-defaults.test.js
// What dashboard.js shows and sends — first load included — driven through a
// minimal fake DOM. The STATUT filter only narrows inside the active-customers
// perimeter (App\Services\CustomersList\ActiveCustomers): no status is
// pre-checked, no "Contrats inactifs" group exists any more.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '../..');
const ACTIVE = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)'];
// Real STATUT options of the active snapshot (20261003T092809), backend count
// order: the server offers the 4 active statuses only.
const STATUS_OPTIONS = [
    { value: 'ACTIVE', count: 1448462 }, { value: 'ACTIVE (PENDING BILLING)', count: 721420 },
    { value: 'SUSPENDED (DELINQUENT ACCOUNT)', count: 260252 }, { value: 'INACTIVATION IN PROCESS.', count: 3152 },
];

test('the perimeter is server-side only: ActiveCustomers lists the 4 statuses, dashboard.js hardcodes none', () => {
    const php = fs.readFileSync(path.join(ROOT, 'app/Services/CustomersList/ActiveCustomers.php'), 'utf8');
    const block = /const STATUSES = \[([\s\S]*?)\];/.exec(php)[1];
    assert.deepEqual([...block.matchAll(/'([^']+)'/g)].map((m) => m[1]).sort(), [...ACTIVE].sort());
    const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');
    assert.ok(!js.includes('ACTIVE_STATUSES') && !js.includes('bscdDashboardDefaults'));
    ACTIVE.concat(['INACTIVE.', 'INACTIVE WITH BALANCE.']).forEach((s) => assert.ok(!js.includes("'" + s + "'"), s));
    assert.ok(!fs.existsSync(path.join(ROOT, 'public/assets/js/dashboard-defaults.js')));
});

test('view: no Référentiel line, no Oracle/snapshot banner, NOMBRE DE NUI CORRECTS card', () => {
    const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
    const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');
    for (const src of [view, js]) {
        assert.ok(!src.includes('Référentiel :'));
        assert.ok(!src.includes('bscdSourceNote') && !src.includes('bscdSourceWarning') && !src.includes('bscdExportSource'));
        assert.ok(!src.includes('La base Oracle en direct'));
    }
    // Labels are lang() keys; their French text lives in app/Language/fr/Dashboard.php.
    const frDashboard = fs.readFileSync(path.join(ROOT, 'app/Language/fr/Dashboard.php'), 'utf8');
    assert.ok(view.includes("'Dashboard.kpi.nuiCorrects'") && frDashboard.includes("'NOMBRE DE NUI CORRECTS'"));
    // No "Total clients" card any more: it would always equal "Clients actifs".
    assert.ok(!view.includes("'Dashboard.kpi.total'") && !view.includes('dashboard-defaults.js'));
    assert.ok(!/'total'\s*=>\s*'Total clients'/.test(frDashboard));
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

// `parked` = a sessionStorage restore payload (filters parked before a reload).
function bootDashboard(overrides = {}, extraEndpoints = {}, parked = null) {
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
        '/dashboard/filter-options': { regions: [], statuses: STATUS_OPTIONS, segmentations: [], segmentsTresor: [], meters: [{ value: 'POSTPAID', count: 6 }, { value: 'PREPAID', count: 4 }], voltages: [], niuQualities: [{ value: 'NUI CORRECT', count: 2624178 }], geoTree: {}, dateBounds: {} },
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
        sessionStorage: { getItem: () => parked, setItem() {}, removeItem() { parked = null; } },
        Chart: function (ctx, config) { charts[ctx.canvas] = config; this.destroy = () => { if (charts[ctx.canvas] === config) delete charts[ctx.canvas]; }; },
        $: () => ({ modal() {} }),
        frdate: { clear() {}, setBounds() {} },
        bscdExportProgress: require('../../public/assets/js/export-progress.js'),
        bscdI18n: require('../../public/assets/js/i18n.js'), // French catalog, the default language
        bscdDashboardSections: require('../../public/assets/js/dashboard-sections.js'),
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
// KPI cards and charts live in the "Vue Postpaid" / "Vue Prepaid" sections.
const inSection = (document, key, sel) => document.querySelector('[data-section="' + key + '"]').querySelector(sel);
const statusesOf = (url) => new URLSearchParams(url.split('?')[1] || '').getAll('status[]');

test('first load: STATUT offers the 4 active statuses, none pre-checked, no "Contrats inactifs" group', async () => {
    const { statusList } = bootDashboard();
    await settle();
    const boxes = statusList.querySelectorAll('input[type=checkbox]');
    assert.deepEqual(boxes.map((cb) => cb.value), STATUS_OPTIONS.map((o) => o.value));
    boxes.forEach((cb) => assert.equal(cb.checked, false, cb.value));
    assert.ok(!/Contrats inactifs|Contrats actifs/.test(statusList.innerHTML));
});

test('Défaillant breakdown: Postpaid only, doughnut and cards from one answer, a component opens its customers', async () => {
    const failureBreakdown = { total: 8, unassigned: 0, components: [
        { value: 'ALWAYS_LATE', count: 2 }, { value: 'SOMETIME_PAID', count: 2 }, { value: 'NEVER_PAID', count: 4 }, { value: 'OTHER', count: 0 },
    ] };
    const ctx = bootDashboard({
        '/dashboard/stats': { totalRows: 10, kpis: { total: 10 }, charts: { region: [], status: [], segmentation: [], meterType: [], failureBreakdown } },
    });
    await settle();
    const id = 'bscdChart_postpaid_failure';
    const chart = ctx.charts[id];
    assert.equal(chart.type, 'doughnut');
    const plain = (v) => JSON.parse(JSON.stringify(v)); // built in the page's own realm
    assert.deepEqual(plain(chart.data.datasets[0].data), [2, 2, 4, 0]);
    assert.deepEqual(plain(chart.data.labels), ['Always Late', 'Sometime Paid', 'Never Paid', 'Other']);
    assert.deepEqual(plain(chart.options.plugins.bscdValueLabels.center), { label: 'TOTAL DÉFAILLANT', value: '8' });
    assert.equal(ctx.charts.bscdChart_prepaid_failure, undefined);

    const html = inSection(ctx.document, 'postpaid', '[data-failure-stats]').innerHTML;
    ['Always Late', 'Sometime Paid', 'Never Paid', 'Other'].forEach((name) => assert.ok(html.includes(name), name));
    assert.ok(html.includes('>4<') && html.includes('>2<'), 'cards carry the counts');
    assert.doesNotMatch(html, /%|50,0|25,0/);
    assert.equal(chart.options.plugins.tooltip.callbacks.label({ label: 'Never Paid', parsed: 1234, dataIndex: 2 }), 'Never Paid: ' + (1234).toLocaleString('fr-FR'));

    const before = ctx.requests.length;
    chart.options.onClick(null, [{ index: 2 }]);
    await settle();
    const added = ctx.requests.slice(before);
    assert.equal(added.length, 1, 'only the table reloads');
    const q = new URLSearchParams(added[0].split('?')[1]);
    assert.ok(added[0].startsWith('/dashboard/rows'));
    assert.deepEqual(q.getAll('failure_subcategory[]'), ['NEVER_PAID']);
    assert.deepEqual(q.getAll('segmentation[]'), [], 'no official filter involved');
    assert.equal(q.get('page'), '1');
    assert.ok(q.getAll('meter[]').length > 0, 'Postpaid scope of the table');

    chart.options.onClick(null, [{ index: 2 }]); // same component again: back to the whole scope
    await settle();
    assert.deepEqual(new URLSearchParams(ctx.requests[ctx.requests.length - 1].split('?')[1]).getAll('failure_subcategory[]'), []);
});

test('first load: stats (one per section), count and rows carry no STATUT filter (= the whole active perimeter, server-side)', async () => {
    const { requests } = bootDashboard();
    await settle();
    ['/dashboard/count', '/dashboard/rows'].forEach((ep) => {
        const calls = requests.filter((u) => u.startsWith(ep));
        assert.equal(calls.length, 1, ep);
        assert.deepEqual(statusesOf(calls[0]), [], ep);
        assert.deepEqual(new URLSearchParams(calls[0].split('?')[1]).getAll('meter[]'), [], ep + ': page filters, no meter added');
    });
    const stats = requests.filter((u) => u.startsWith('/dashboard/stats'));
    assert.equal(stats.length, 2);
    stats.forEach((u) => assert.deepEqual(statusesOf(u), []));
    assert.deepEqual(stats.map((u) => new URLSearchParams(u.split('?')[1]).getAll('meter[]')), [['POSTPAID'], ['PREPAID']]);
});

test('Total clients: sum of the two section answers, no extra request, never a partial sum', async () => {
    const { document, requests } = bootDashboard();
    await settle();
    assert.equal(document.querySelector('[data-sections-total]').textContent, '20'); // 10 + 10
    assert.equal(requests.filter((u) => u.startsWith('/dashboard/stats')).length, 2);

    let release = null;
    const slow = bootDashboard({
        '/dashboard/stats': (url) => (url.includes('PREPAID')
            ? new Promise((res) => { release = () => res({ totalRows: 5, kpis: { total: 5 }, charts: { region: [], status: [], segmentation: [], meterType: [] } }); })
            : { totalRows: 7, kpis: { total: 7 }, charts: { region: [], status: [], segmentation: [], meterType: [] } }),
    });
    await settle();
    assert.equal(slow.document.querySelector('[data-section-count="postpaid"]').textContent, '7 clients');
    assert.equal(slow.document.querySelector('[data-sections-total]').textContent, '', 'waits for Prepaid');
    release(); await settle();
    assert.equal(slow.document.querySelector('[data-sections-total]').textContent, '12');
});

test('first load: the NUI card shows the stats figure', async () => {
    const { document } = bootDashboard();
    await settle();
    assert.equal(inSection(document, 'postpaid', '[data-kpi="nuiCorrects"]').textContent, '7');
    assert.doesNotMatch(String(inSection(document, 'postpaid', '[data-kpi-sub="nuiCorrects"]').textContent), /%/); // NUI corrects / all NUI (backend pct)
});

test('the user can narrow STATUT inside the perimeter: the next apply sends exactly the new selection', async () => {
    const { statusList, requests, byId } = bootDashboard();
    await settle();
    ['ACTIVE', 'SUSPENDED (DELINQUENT ACCOUNT)'].forEach((v) => {
        const cb = statusList.querySelectorAll('input[type=checkbox]').find((c) => c.value === v);
        cb.checked = true; cb.fire('change');
    });
    requests.length = 0;
    byId.bscdFilters.fire('submit');
    await settle();
    const expected = ['ACTIVE', 'SUSPENDED (DELINQUENT ACCOUNT)'].sort();
    ['/dashboard/stats', '/dashboard/count', '/dashboard/rows'].forEach((ep) => {
        const call = requests.find((u) => u.startsWith(ep));
        assert.deepEqual([...statusesOf(call)].sort(), expected, ep);
    });
});

// ── KPI row: "Clients actifs" is the section's total (no separate "Total clients") ──

test('KPI row: "Clients actifs" shows the stats figure, no percentage', async () => {
    const { document } = bootDashboard();
    await settle();
    assert.equal(inSection(document, 'postpaid', '[data-kpi="actifs"]').textContent, '10');
    assert.doesNotMatch(String(inSection(document, 'postpaid', '[data-kpi-sub="actifs"]').textContent), /%/); // backend actifs.pct
});

test('a parked STATUT value that is no longer offered (an excluded status) is dropped, never sent', async () => {
    const saved = JSON.stringify({ applied: { status: ['INACTIVE.', 'ACTIVE'] }, table: {} });
    const { requests } = bootDashboard({}, {}, saved);
    await settle();
    requests.filter((u) => /\/dashboard\/(stats|count|rows)/.test(u)).forEach((u) => assert.deepEqual(statusesOf(u), ['ACTIVE'], u));
});

// ── Segmentation groups: Type de compteur -> group label (display only) ──

const METER_OPTIONS = [{ value: 'POSTPAID', count: 2 }, { value: 'PREPAID', count: 2 }, { value: 'Compteurs Communicants', count: 1 }];
const SEG_OPTIONS = [
    { value: '1 PERFECT', count: 5 }, { value: '2 RELIABLE', count: 4 }, { value: '6 Défaillant', count: 3 },
    { value: '5-Dormant', count: 2 }, { value: '6-Never Vending', count: 1 },
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

// The 6 PREPAID categories, business order — always offered by the dropdown.
const PREPAID_6 = ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6-Never Vending'];

test('segmentation group, Type de compteur = Toutes: "Postpaid | Compteurs communicants", Prepaid, every option + the 6 PREPAID', async () => {
    const r = await bootWithMeters([]);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants', 'Prepaid']);
    const expected = new Set(SEG_OPTIONS.map((o) => o.value).concat(PREPAID_6));
    assert.deepEqual([...r.options].sort(), [...expected].sort());
});

test('segmentation group, PREPAID only: "Prepaid", the 6 PREPAID categories in business order (backend sent 2)', async () => {
    const r = await bootWithMeters(['PREPAID']);
    assert.deepEqual(r.groups, ['Prepaid']);
    assert.deepEqual(r.options, PREPAID_6);
});

test('segmentation group, POSTPAID only: "Postpaid", POSTPAID segmentations only', async () => {
    const r = await bootWithMeters(['POSTPAID']);
    assert.deepEqual(r.groups, ['Postpaid']);
    assert.deepEqual(r.options, ['1 PERFECT', '2 RELIABLE', '6 Défaillant']);
});

test('segmentation group, communicants only: "Compteurs communicants", still the POSTPAID segmentation', async () => {
    const r = await bootWithMeters(['Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Compteurs communicants']);
    assert.deepEqual(r.options, ['1 PERFECT', '2 RELIABLE', '6 Défaillant']);
});

test('segmentation group, POSTPAID + communicants: "Postpaid | Compteurs communicants"', async () => {
    const r = await bootWithMeters(['POSTPAID', 'Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants']);
    assert.deepEqual(r.options, ['1 PERFECT', '2 RELIABLE', '6 Défaillant']);
});

test('segmentation group, PREPAID + communicants: Prepaid group untouched, communicants label on the POSTPAID group', async () => {
    const r = await bootWithMeters(['PREPAID', 'Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Compteurs communicants', 'Prepaid']);
});

// ── Segmentation option counts: scoped to the filters in the form ──
// Real Oracle proportions (30/09): PERFECT = 28 795 POSTPAID + 1 248 communicants.

const SCOPED = {
    '':                                                    { '1 PERFECT': 30043, '2 RELIABLE': 159220, '6 Défaillant': 817910, '5-Dormant': 1091001, '6-Never Vending': 3890 },
    'POSTPAID':                                            { '1 PERFECT': 28795, '2 RELIABLE': 152163, '6 Défaillant': 816038 },
    'Compteurs Communicants':                              { '1 PERFECT': 1248, '2 RELIABLE': 7057, '6 Défaillant': 1872 },
    'POSTPAID|Compteurs Communicants':                     { '1 PERFECT': 30043, '2 RELIABLE': 159220, '6 Défaillant': 817910 },
    'PREPAID':                                             { '5-Dormant': 1091001, '6-Never Vending': 3890 },
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
    assert.deepEqual(r.counts, { '1 PERFECT': n(28795), '2 RELIABLE': n(152163), '6 Défaillant': n(816038) });
});

test('segment counts, Type = Compteurs communicants: smart-meter numbers under the POSTPAID segmentation', async () => {
    const r = await bootScoped(['Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Compteurs communicants']);
    assert.deepEqual(r.counts, { '1 PERFECT': n(1248), '2 RELIABLE': n(7057), '6 Défaillant': n(1872) });
});

test('segment counts, POSTPAID + communicants: the combined meter scope (same rule as the filter)', async () => {
    const r = await bootScoped(['POSTPAID', 'Compteurs Communicants']);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants']);
    assert.deepEqual(r.counts, { '1 PERFECT': n(30043), '2 RELIABLE': n(159220), '6 Défaillant': n(817910) });
});

test('segment counts, Type = Toutes: scope of every meter, group "Postpaid | Compteurs communicants"', async () => {
    const r = await bootScoped([]);
    assert.deepEqual(r.groups, ['Postpaid | Compteurs communicants', 'Prepaid']);
    assert.equal(r.counts['1 PERFECT'], n(30043));
    assert.equal(r.counts['5-Dormant'], n(1091001));
});

test('segment counts, PREPAID: real numbers for the returned values, 0 for the 4 others, all 6 listed', async () => {
    const r = await bootScoped(['PREPAID']);
    assert.deepEqual(r.counts, {
        '1-Stable': '0', '2-InStable': '0', '3-At Risk': '0', '4-Suspect Dormant': '0',
        '5-Dormant': n(1091001), '6-Never Vending': n(3890),
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
    ['5-Dormant', 1091001], ['6 Défaillant', 817910], ['4 At-Risk', 234045], ['5 Delinquant', 229204], ['3 Occasionnal', 206345],
    ['2 RELIABLE', 159220], ['1 PERFECT', 30043], ['6-Never Vending', 9888],
].map(([value, count]) => ({ value, count }));
// The 6 PREPAID segments (App\Services\CustomersList\PrepaidSegmentations),
// business order.
const PREPAID_VALUES = ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6-Never Vending'];
const POSTPAID_ORDER = ['1 PERFECT', '2 RELIABLE', '3 Occasionnal', '4 At-Risk', '5 Delinquant', '6 Défaillant'];
// Chart labels of the PREPAID bars: the official segments, exactly.
const PREPAID_ORDER = PREPAID_VALUES;

// stats answer for a query: segmentation = the values of the requested
// meters / segmentations, in VOLUME order (what the API really returns).
function statsFor(url, { zero = [], empty = false, extra = [] } = {}) {
    const q = new URLSearchParams(url.split('?')[1] || '');
    const meters = q.getAll('meter[]');
    const segs = q.getAll('segmentation[]');
    const withPrepaid = !meters.length || meters.includes('PREPAID');
    const withPostpaid = !meters.length || meters.some((m) => m !== 'PREPAID');
    const seg = empty ? [] : ALL_SEGS
        .filter((p) => (PREPAID_VALUES.includes(p.value) ? withPrepaid : withPostpaid))
        .filter((p) => !segs.length || segs.includes(p.value))
        .filter((p) => !zero.includes(p.value))
        .concat(extra);
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
    const sk = meters.length && meters.every((m) => m === 'PREPAID') ? 'prepaid' : 'postpaid';
    const ctx = bootDashboard({
        '/dashboard/filter-options': { meters: CHART_METERS, segmentations: ALL_SEGS },
        '/dashboard/stats': (url) => statsFor(url, statsOpts),
    });
    await settle();
    msList(ctx, 'meter').querySelectorAll('input[type=checkbox]').forEach((cb) => { if (meters.includes(cb.value)) { cb.checked = true; cb.fire('change'); } });
    msList(ctx, 'segmentation').querySelectorAll('input[type=checkbox]').forEach((cb) => { if (segs.includes(cb.value)) { cb.checked = true; cb.fire('change'); } });
    ctx.byId.bscdFilters.fire('submit');
    await settle();
    const chart = ctx.charts['bscdChart_' + sk + '_segmentation'];
    return {
        ctx,
        labels: chart ? [...chart.data.labels] : null,
        counts: chart ? [...chart.data.datasets[0].data] : null,
        message: ctx.byId['bscdChart_' + sk + '_segmentation'].parentNode.dataset.chartMsg,
    };
}

test('segmentation chart, POSTPAID: the 6 profiles in the business order, not by volume', async () => {
    const r = await applyScope(['POSTPAID']);
    assert.deepEqual(r.labels, POSTPAID_ORDER);
    assert.notDeepEqual(r.labels.slice(0, 3), ['6 Défaillant', '4 At-Risk', '5 Delinquant']); // volume order is forbidden
    assert.equal(r.counts[0], 30043);
    assert.equal(r.message, undefined);
});

test('segmentation chart: unclassified customers (blank SEGMENTATION) get their own last bar, in no profile', async () => {
    const r = await applyScope(['POSTPAID'], [], { extra: [{ value: 'Non renseigné', count: 42 }] });
    assert.deepEqual(r.labels, [...POSTPAID_ORDER, 'Non classé']);
    assert.equal(r.counts.at(-1), 42);
    assert.equal(r.counts.reduce((a, n) => a + n, 0), 30043 + 159220 + 206345 + 234045 + 229204 + 817910 + 42);
});

test('chart tooltips and the meter type list give the count only, never a percentage', async () => {
    const r = await applyScope(['POSTPAID']);
    const config = r.ctx.charts.bscdChart_postpaid_segmentation;
    const label = config.options.plugins.tooltip.callbacks.label({ label: 'A', parsed: { x: 1234, y: 1234 }, dataIndex: 0 });
    assert.equal(label, (1234).toLocaleString('fr-FR'));
    assert.doesNotMatch(inSection(r.ctx.document, 'postpaid', '[data-metertype="list"]').innerHTML, /%|metertype__pct/);
});

test('segmentation chart, Compteurs communicants: the POSTPAID segmentation and order', async () => {
    const r = await applyScope(['COMPTEURS COMMUNICANTS']);
    assert.deepEqual(r.labels, POSTPAID_ORDER);
});

test('segmentation chart, PREPAID: the 6 segments, business order, display labels, absent ones at 0', async () => {
    const r = await applyScope(['PREPAID']);
    assert.deepEqual(r.labels, PREPAID_ORDER);
    assert.deepEqual(r.counts, [0, 0, 0, 0, 1091001, 9888]); // never by volume
});

test('segmentation chart, PREPAID, Dormant + Never Vending ticked: only those two, in order', async () => {
    const r = await applyScope(['PREPAID'], ['6-Never Vending', '5-Dormant']);
    assert.deepEqual(r.labels, ['5-Dormant', '6-Never Vending']);
    assert.deepEqual(r.counts, [1091001, 9888]);
});

test('PREPAID values match PrepaidSegmentations.php, in the same order', () => {
    const php = fs.readFileSync(path.join(ROOT, 'app/Services/CustomersList/PrepaidSegmentations.php'), 'utf8');
    const block = /const VALUES = \[([\s\S]*?)\];/.exec(php)[1];
    assert.deepEqual([...block.matchAll(/'([^']+)'/g)].map((m) => m[1]), PREPAID_VALUES);
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

// ── Segmentation filter, PREPAID: the 6 segments are guaranteed by the
// frontend, even when the backend only lists the values present in the data.

// Figures of the validated customers_list fix (PREPAID, 4 active statuses);
// 4-Suspect Dormant is absent from the data.
const CURRENT_PREPAID = { '1-Stable': 252345, '2-InStable': 251888, '3-At Risk': 425372, '5-Dormant': 151490, '6-Never Vending': 11192 };
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

test('PREPAID filter, current data: exactly the 6 segments, business order, labels, real counts and 0 for the absent ones', async () => {
    const r = await bootCurrent();
    r.tick('meter', 'PREPAID');
    await settle();
    const s = r.read();
    assert.deepEqual(s.groups, ['Prepaid']);
    assert.equal(s.values.length, 6);
    assert.deepEqual(s.values, PREPAID_VALUES);
    assert.deepEqual(s.labels, PREPAID_ORDER);
    assert.deepEqual(s.counts, [n(252345), n(251888), n(425372), '0', n(151490), n(11192)]);
});

test('PREPAID filter + a segment ticked: the 6 options stay, the technical value is sent, counts ignore the segmentation filter', async () => {
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
    assert.deepEqual(s.counts, [n(252345), n(251888), n(425372), '0', n(151490), n(11192)]);

    r.tick('meter', 'PREPAID', false);
    r.tick('meter', 'POSTPAID');
    await settle();
    s = r.read();
    assert.deepEqual(s.groups, ['Postpaid']);
    assert.deepEqual(s.values, ['1 PERFECT', '4 At-Risk']);
});

test('PREPAID filter + another filter (region): counts follow the region, the 6 segments stay', async () => {
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

test('segmentation charts, Toutes: Vue Postpaid = POSTPAID order, Vue Prepaid = PREPAID order, together every segment once', async () => {
    const r = await applyScope([]);
    assert.deepEqual(r.labels, POSTPAID_ORDER);
    const pre = r.ctx.charts.bscdChart_prepaid_segmentation;
    assert.deepEqual([...pre.data.labels], PREPAID_ORDER);
    const both = r.counts.concat(pre.data.datasets[0].data);
    assert.equal(both.reduce((a, b) => a + b, 0), ALL_SEGS.reduce((a, p) => a + p.count, 0));
});

test('sections: Type de compteur = PREPAID only -> Vue Postpaid empty without any request, Vue Prepaid filled', async () => {
    const r = await applyScope(['PREPAID']);
    const stats = r.ctx.requests.filter((u) => u.startsWith('/dashboard/stats'));
    assert.deepEqual(new URLSearchParams(stats[stats.length - 1].split('?')[1]).getAll('meter[]'), ['PREPAID']);
    assert.equal(r.ctx.charts.bscdChart_postpaid_segmentation, undefined);
    assert.equal(r.ctx.byId.bscdChart_postpaid_segmentation.parentNode.dataset.chartMsg, 'Aucune donnée disponible pour les filtres sélectionnés.');
    assert.equal(inSection(r.ctx.document, 'postpaid', '[data-kpi="actifs"]').textContent, '0');
    assert.deepEqual(r.labels, PREPAID_ORDER);
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
    ['bscdChart_postpaid_region', 'bscdChart_postpaid_status', 'bscdChart_postpaid_segmentation'].forEach((id) => {
        assert.equal(r.ctx.charts[id], undefined, id);
        assert.equal(r.ctx.byId[id].parentNode.dataset.chartMsg, msg, id);
    });
    assert.match(inSection(r.ctx.document, 'postpaid', '[data-metertype="list"]').innerHTML, /Aucune donnée disponible pour les filtres sélectionnés/);
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
    assert.equal(ctx.charts.bscdChart_prepaid_segmentation, undefined, 'old chart removed while loading');
    assert.equal(ctx.byId.bscdChart_prepaid_segmentation.parentNode.dataset.chartMsg, 'Chargement…');

    tick('PREPAID', false); tick('POSTPAID', true); ctx.byId.bscdFilters.fire('submit'); // fast answer
    await settle();
    assert.deepEqual([...ctx.charts.bscdChart_postpaid_segmentation.data.labels], POSTPAID_ORDER);

    releaseOld(); await settle(); // the old answer lands last
    assert.deepEqual([...ctx.charts.bscdChart_postpaid_segmentation.data.labels], POSTPAID_ORDER, 'still the POSTPAID scope');
    assert.equal(ctx.charts.bscdChart_prepaid_segmentation, undefined, 'the old PREPAID answer is dropped');
});
