// Run: node --test tests/js/dashboard-source.test.js
// Which base the dashboard's figures come from (public/assets/js/dashboard-source.js).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { dateTimeFr, sourceNote, divergenceNote } = require('../../public/assets/js/dashboard-source.js');

const nb = (s) => s.replace(/[  ]/g, ' '); // fr-FR thousands separator -> plain space

test('dates from the snapshot meta are shown as recorded, never guessed', () => {
    assert.equal(dateTimeFr('2026-09-27 04:32:49'), '27/09/2026 04:32');        // manifest source_updated_at
    assert.equal(dateTimeFr('2026-09-27T18:38:26+01:00'), '27/09/2026 18:38');  // generated_at (no TZ shift)
    [null, undefined, '', 'hier', '27/09/2026', 42].forEach((v) => assert.equal(dateTimeFr(v), null, String(v)));
});

test('source note uses the real metadata only', () => {
    const real = { id: '20260927T183827_860bf819', rows: 3302841, generatedAt: '2026-09-27T18:38:26+01:00', sourceUpdatedAt: '2026-09-27 04:32:49' };
    assert.equal(nb(sourceNote(real)), 'Référentiel : 3 302 841 lignes · données Oracle du 27/09/2026 04:32 · extraites le 27/09/2026 18:38');
    assert.equal(nb(sourceNote({ rows: 30, generatedAt: null, sourceUpdatedAt: null })), 'Référentiel : 30 lignes');
    assert.equal(sourceNote(null), '');
    assert.equal(sourceNote({ rows: 'x' }), '');
});

test('divergence note: silent when both bases agree or a figure is missing', () => {
    assert.equal(divergenceNote(3302841, 3302841), '');
    assert.equal(divergenceNote(null, 3303512), '');
    assert.equal(divergenceNote(3302841, null), '');
    assert.equal(divergenceNote(undefined, undefined), '');
});

test('divergence note: states both real figures and the gap (3 303 512 vs 3 302 841)', () => {
    const t = nb(divergenceNote(3302841, 3303512));
    assert.match(t, /3 303 512 lignes pour ces filtres/);
    assert.match(t, /les exports 3 302 841/);
    assert.match(t, /écart 671/);
    assert.match(nb(divergenceNote(905362, 905510)), /écart 148/); // DCUD, observed 2026-09-29
});

test('dashboard wiring: count from the snapshot endpoint, no Oracle stand-in, scripts in order', () => {
    const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js/dashboard.js'), 'utf8');
    assert.ok(js.includes("if (EP.count) loadCount(key);"), 'stats load triggers the snapshot count');
    assert.ok(js.includes('(EP.count ? null : state.table.total)'), 'modal never falls back to the live table count');
    assert.ok(!/state\.filterTotal = stats\.totalRows;\s*\/\/ filter-only/.test(js), 'stats no longer sets the export count');

    const view = fs.readFileSync(path.join(__dirname, '../../app/Views/dashboard/index.php'), 'utf8');
    assert.ok(view.indexOf('assets/js/dashboard-source.js') < view.indexOf("assets/js/dashboard.js')"));
    assert.ok(view.includes('id="bscdSourceNote"') && view.includes('id="bscdSourceWarning"') && view.includes('id="bscdExportSource"'));
});
