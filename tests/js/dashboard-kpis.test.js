// Run: node --test tests/js/dashboard-kpis.test.js
// The dashboard KPI row: the 4 card slots, their order, one equal-width line on desktop.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');

const cards = [.../\$kpiCards = \[([\s\S]*?)\];/.exec(view)[1].matchAll(/\['(\w+)', '([^']+)'/g)].map((m) => [m[1], m[2]]);

test('KPI slots, in order: Total clients (conditional), Clients actifs, NOMBRE DE NUI CORRECTS, Contacts renseignés', () => {
    assert.deepEqual(cards, [
        ['total', 'Total clients'],
        ['actifs', 'Clients actifs'],
        ['nuiCorrects', 'NOMBRE DE NUI CORRECTS'],
        ['contacts', 'Contacts renseignés'],
    ]);
});

test('no other card: Clients avec compteur / Clients inactifs are not displayed', () => {
    assert.ok(!/Clients avec compteur|Clients inactifs/i.test(view));
    assert.ok(!js.includes("'avecCompteur'") && !js.includes("'inactifs'"));
});

test('dashboard.js fills exactly those 4 slots and toggles only "Total clients"', () => {
    const skeleton = JSON.parse(/function setKpiSkeleton\(on\) \{\s*(\[[^\]]*\])/.exec(js)[1].replace(/'/g, '"'));
    assert.deepEqual(skeleton, cards.map((c) => c[0]));
    assert.match(js, /querySelector\('\[data-kpi-col="total"\]'\)\.classList\.toggle\('d-none', !showTotal\)/);
});

test('one line on desktop: equal flex columns (col-lg), no fixed width; total hidden by default', () => {
    assert.match(view, /<div class="col-12 col-sm-6 col-lg mb-3<\?= \$key === 'total' \? ' d-none' : '' \?>" data-kpi-col="<\?= \$key \?>">/);
    assert.ok(!/col-(lg|xl)-3/.test(view.slice(view.indexOf('bscd-kpi-row'), view.indexOf('endforeach'))));
});
