// Run: node --test tests/js/dashboard-kpis.test.js
// The dashboard KPI row: the 3 card slots, their order, one equal-width line on desktop.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');

// The view lists lang() keys ('Dashboard.kpi.total'); the French text is in app/Language/fr/Dashboard.php.
const frDashboard = fs.readFileSync(path.join(ROOT, 'app/Language/fr/Dashboard.php'), 'utf8');
const kpiFr = /'kpi' => \[([\s\S]*?)\]/.exec(frDashboard)[1];
const frLabel = (key) => new RegExp("'" + key.split('.').pop() + "'\\s*=>\\s*'([^']+)'").exec(kpiFr)[1];
const cards = [.../\$kpiCards = \[([\s\S]*?)\];/.exec(view)[1].matchAll(/\['(\w+)', '([^']+)'/g)].map((m) => [m[1], frLabel(m[2])]);

test('KPI slots, in order: Clients actifs, NOMBRE DE NUI CORRECTS, Contacts renseignés (no "Total clients": always = Clients actifs)', () => {
    assert.deepEqual(cards, [
        ['actifs', 'Clients actifs'],
        ['nuiCorrects', 'NOMBRE DE NUI CORRECTS'],
        ['contacts', 'Contacts renseignés'],
    ]);
});

test('no other card: Clients avec compteur / Clients inactifs are not displayed', () => {
    assert.ok(!/Clients avec compteur|Clients inactifs/i.test(view));
    assert.ok(!js.includes("'avecCompteur'") && !js.includes("'inactifs'"));
});

test('dashboard.js fills exactly those 3 slots, never a "total" one', () => {
    const skeleton = JSON.parse(/function setKpiSkeleton\(on\) \{\s*(\[[^\]]*\])/.exec(js)[1].replace(/'/g, '"'));
    assert.deepEqual(skeleton, cards.map((c) => c[0]));
    assert.ok(!js.includes('data-kpi="total"') && !js.includes('data-kpi-col="total"'));
});

test('one line on desktop: equal flex columns (col-lg), no fixed width, nothing hidden', () => {
    assert.match(view, /<div class="col-12 col-sm-6 col-lg mb-3" data-kpi-col="<\?= \$key \?>">/);
    assert.ok(!/col-(lg|xl)-3/.test(view.slice(view.indexOf('bscd-kpi-row'), view.indexOf('endforeach'))));
});
