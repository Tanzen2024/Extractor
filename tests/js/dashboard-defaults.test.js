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

function bootDashboard(overrides = {}) {
    const byId = {};
    const bySel = {};
    const requests = [];
    const boot = {
        endpoints: { stats: '/dashboard/stats', count: '/dashboard/count', rows: '/dashboard/rows', filterOptions: '/dashboard/filter-options', export: '/dashboard/export', jobStatus: '/exports' },
        sortable: ['CONTRACT'], tableColumns: ['CONTRACT', 'STATUS'], defaultVisibleColumns: ['CONTRACT', 'STATUS'], perPageOptions: [20, 50, 100, 200],
    };
    const document = {
        getElementById(id) {
            if (!byId[id]) { byId[id] = makeEl(); if (id === 'bscd-dashboard-data') byId[id].textContent = JSON.stringify(boot); }
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
    Object.keys(overrides).forEach((k) => { responses[k] = { ...responses[k], ...overrides[k] }; });
    const sandbox = {
        document, JSON, Math, Date, Object, Array, String, Number, Promise, URLSearchParams, console,
        setTimeout, clearTimeout, setInterval: () => 0, clearInterval() {},
        localStorage: { getItem: () => null, setItem() {} },
        Chart: function () { this.destroy = () => {}; },
        $: () => ({ modal() {} }),
        frdate: { clear() {}, setBounds() {} },
        bscdExportProgress: require('../../public/assets/js/export-progress.js'),
        bscdDashboardDefaults: require('../../public/assets/js/dashboard-defaults.js'),
        bscdFetch(url) {
            requests.push(url);
            const key = Object.keys(responses).find((k) => url.startsWith(k));
            return Promise.resolve(JSON.parse(JSON.stringify(responses[key])));
        },
    };
    sandbox.window = sandbox;
    sandbox.addEventListener = () => {};
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8'), sandbox);
    const statusList = document.querySelector('.bscd-ms[data-dim="status"]').querySelector('.bscd-ms__list');
    return { document, requests, statusList, byId };
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

test('segmentation group, neither POSTPAID nor communicants: unchanged (Postpaid, Prepaid, every option)', async () => {
    const r = await bootWithMeters([]);
    assert.deepEqual(r.groups, ['Postpaid', 'Prepaid']);
    assert.deepEqual([...r.options].sort(), SEG_OPTIONS.map((o) => o.value).sort());
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
