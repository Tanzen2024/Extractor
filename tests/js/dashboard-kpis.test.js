// Run: node --test tests/js/dashboard-kpis.test.js
// The Vue Postpaid / Vue Prepaid show the payer-profile segmentation only:
// the former KPI row (Clients actifs, NOMBRE DE NUI CORRECTS, Contacts
// renseignés) is no longer displayed. /dashboard/stats still computes the
// KPIs (DashboardService, unchanged) — only the display was removed.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');

test('no KPI row in the views: no card, no slot, no lang key', () => {
    assert.ok(!view.includes('$kpiCards') && !view.includes('data-kpi') && !view.includes('bscd-kpi'));
    assert.ok(!view.includes("'Dashboard.kpi."));
    const fr = fs.readFileSync(path.join(ROOT, 'app/Language/fr/Dashboard.php'), 'utf8');
    assert.ok(!/'kpi' => \[/.test(fr));
});

test('dashboard.js renders no KPI (and never reintroduces Clients avec compteur / inactifs)', () => {
    assert.ok(!/renderKpis|setKpiSkeleton|data-kpi/.test(js));
    assert.ok(!js.includes("'avecCompteur'") && !js.includes("'inactifs'"));
});

test('the KPIs are still computed server-side (display removed, not the feature)', () => {
    const svc = fs.readFileSync(path.join(ROOT, 'app/Services/CustomersList/DashboardService.php'), 'utf8');
    assert.match(svc, /kpis/);
});
