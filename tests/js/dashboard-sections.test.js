// Run: node --test tests/js/dashboard-sections.test.js
// "Vue Postpaid" / "Vue Prepaid": the criteria each section requests
// (public/assets/js/dashboard-sections.js) — the page filters with the
// METER filter narrowed to the section's universe, nothing else.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { SECTIONS, universeOf, sectionCriteria, sectionsTotal, failureBreakdown, withFailureFilter, withSegmentation, profileInScope, profileTotals } = require('../../public/assets/js/dashboard-sections.js');

const ROOT = path.join(__dirname, '../..');

// Same rules as dashboard.js meterUniverse() / segmentationUniverse().
const meterUniverse = (v) => {
    const n = String(v).trim().toUpperCase();
    if (n === 'POSTPAID' || n.includes('COMMUNICANT')) return 'POSTPAID';
    if (n === 'PREPAID') return 'PREPAID';
    return null;
};
const POSTPAID_SEGS = ['1 PERFECT', '6 Défaillant'];
const PREPAID_SEGS = ['5-Dormant', '6-Never Vending'];
const segUniverse = (v) => (POSTPAID_SEGS.includes(v) ? 'POSTPAID' : PREPAID_SEGS.includes(v) ? 'PREPAID' : null);
const OFFERED = ['POSTPAID', 'PREPAID', 'Compteurs Communicants'];
const crit = (applied, key) => sectionCriteria(applied, universeOf(key), OFFERED, meterUniverse, segUniverse);

test('Défaillant breakdown: shares of the Défaillant total, 100 % together; no answer = empty', () => {
    const view = failureBreakdown({ total: 8, unassigned: 0, components: [
        { value: 'ALWAYS_LATE', count: 1 }, { value: 'NEVER_PAID', count: 4 }, { value: 'SOMETIME_PAID', count: 3 },
    ] });
    assert.deepEqual(view.rows.map((r) => r.share), [12.5, 50, 37.5]);
    assert.equal(view.rows.reduce((s, r) => s + r.count, 0), view.total);
    assert.equal(view.rows.reduce((s, r) => s + r.share, 0), 100);
    assert.deepEqual(failureBreakdown(undefined), { total: 0, unassigned: 0, rows: [] });
    assert.deepEqual(failureBreakdown({ total: 0, components: [{ value: 'NEVER_PAID', count: 0 }] }).rows[0].share, 0);
});

test('component selected: added to a copy of the criteria; nothing selected or no possible row: unchanged', () => {
    const c = { meter: ['POSTPAID'] };
    assert.deepEqual(withFailureFilter(c, 'NEVER_PAID'), { meter: ['POSTPAID'], failure_subcategory: ['NEVER_PAID'] });
    assert.deepEqual(c, { meter: ['POSTPAID'] }, 'page filters untouched');
    assert.equal(withFailureFilter(c, null), c);
    assert.equal(withFailureFilter(null, 'NEVER_PAID'), null);
});

test('two sections, Postpaid first', () => {
    assert.deepEqual(SECTIONS.map((s) => s.key), ['postpaid', 'prepaid']);
    assert.equal(universeOf('postpaid'), 'POSTPAID');
    assert.equal(universeOf('prepaid'), 'PREPAID');
    assert.equal(universeOf('other'), null);
});

test('no meter filter: Postpaid = POSTPAID + Compteurs communicants, Prepaid = PREPAID; other filters kept as is', () => {
    const applied = { status: ['ACTIVE'], region: ['DCUD'], date_from: '2026-01-01' };
    assert.deepEqual(crit(applied, 'postpaid'), { ...applied, meter: ['POSTPAID', 'Compteurs Communicants'] });
    assert.deepEqual(crit(applied, 'prepaid'), { ...applied, meter: ['PREPAID'] });
    assert.deepEqual(applied, { status: ['ACTIVE'], region: ['DCUD'], date_from: '2026-01-01' }, 'page filters untouched');
});

test('meter filter: each section keeps only its own ticked meters; none -> null (no request)', () => {
    assert.deepEqual(crit({ meter: ['Compteurs Communicants'] }, 'postpaid'), { meter: ['Compteurs Communicants'] });
    assert.equal(crit({ meter: ['Compteurs Communicants'] }, 'prepaid'), null);
    assert.equal(crit({ meter: ['PREPAID'] }, 'postpaid'), null);
    assert.deepEqual(crit({ meter: ['PREPAID', 'POSTPAID'] }, 'prepaid'), { meter: ['PREPAID'] });
});

test('segmentation filter: split by universe; ticked segments all of the other universe -> null', () => {
    const applied = { segmentation: ['1 PERFECT', '5-Dormant'] };
    assert.deepEqual(crit(applied, 'postpaid').segmentation, ['1 PERFECT']);
    assert.deepEqual(crit(applied, 'prepaid').segmentation, ['5-Dormant']);
    assert.equal(crit({ segmentation: ['6-Never Vending'] }, 'postpaid'), null);
    // A value in no known list is left to the server, in both sections.
    assert.deepEqual(crit({ segmentation: ['X'] }, 'prepaid').segmentation, ['X']);
});

test('the snapshot offers no meter of a universe -> that section is null', () => {
    assert.equal(sectionCriteria({}, 'PREPAID', ['POSTPAID'], meterUniverse, segUniverse), null);
});

test('view: a badge per view header, the global total in one band above both views; no quick links', () => {
    const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
    ['bscd-section-nav', 'data-section-count', 'data-sections-total', 'data-section-unassigned', 'data-table-scope-link']
        .forEach((s) => assert.ok(!view.includes(s), s));
    const grid = view.indexOf('<div class="bscd-compare">');
    const band = view.indexOf('data-grand-total');
    assert.ok(grid !== -1 && band > grid && band < view.indexOf('<section class="bscd-section bscd-section--<?= $sk ?>"'), 'band first in the grid');
    assert.equal(view.match(/data-grand-total/g).length, 1);
    const header = view.slice(view.indexOf('<header class="bscd-section__header">'), view.indexOf('</header>'));
    assert.ok(header.includes('data-section-total'), 'badge in the view header');
    assert.ok(view.includes('id="bscdSection-detail"'), 'the detail section itself stays');
});

test('sectionsTotal: Postpaid + Prepaid, null until both figures are known, an empty view counts 0', () => {
    assert.equal(sectionsTotal({ postpaid: 1342540, prepaid: 1110571 }), 2453111);
    assert.equal(sectionsTotal({ postpaid: 1342540 }), null);
    assert.equal(sectionsTotal({}), null);
    assert.equal(sectionsTotal({ postpaid: 0, prepaid: 1110571 }), 1110571);
});

test('view: one section per universe in one comparative grid, scoped detail table; script before dashboard.js', () => {
    const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
    assert.match(view, /'postpaid' => \['icon' => '[^']+', 'title' => 'Dashboard\.sections\.postpaid\.title'/);
    assert.match(view, /'prepaid'  => \['icon' => '[^']+', 'title' => 'Dashboard\.sections\.prepaid\.title'/);
    assert.ok(view.includes('data-section="<?= $sk ?>"'));
    const grid = view.indexOf('<div class="bscd-compare">');
    assert.ok(grid !== -1 && grid < view.indexOf('<?php foreach ($sections as $sk => $sec): ?>\n<section'), 'both views inside the grid');
    assert.ok(view.indexOf("'postpaid' =>") < view.indexOf("'prepaid'  =>"), 'Postpaid first (left column, first when stacked)');
    assert.ok(view.includes('data-table-scope="<?= $scope ?>"'));
    assert.ok(view.indexOf("base_url('assets/js/dashboard-sections.js')") < view.indexOf("base_url('assets/js/dashboard.js')"));
});

test('i18n: section titles in French and English', () => {
    const fr = fs.readFileSync(path.join(ROOT, 'app/Language/fr/Dashboard.php'), 'utf8');
    const en = fs.readFileSync(path.join(ROOT, 'app/Language/en/Dashboard.php'), 'utf8');
    for (const [src, a, b] of [[fr, 'Vue Postpaid', 'Segmentation des profils payeurs Postpaid'], [fr, 'Vue Prepaid', 'Segmentation des profils payeurs Prepaid'],
        [en, 'Postpaid View', 'Postpaid payer-profile segmentation'], [en, 'Prepaid View', 'Prepaid payer-profile segmentation']]) {
        assert.ok(src.includes("'" + a + "'") && src.includes("'" + b + "'"), a);
    }
});

// ── Segmentation views: a clicked profile narrows the table through the existing Segmentation filter ──

test('withSegmentation: the section criteria with Segmentation = the clicked profile, nothing else changed', () => {
    const c = { meter: ['POSTPAID'], region: ['DCUD'], segmentation: ['1 PERFECT', '6 Défaillant'] };
    assert.deepEqual(withSegmentation(c, '6 Défaillant'), { meter: ['POSTPAID'], region: ['DCUD'], segmentation: ['6 Défaillant'] });
    assert.deepEqual(c.segmentation, ['1 PERFECT', '6 Défaillant'], 'page criteria untouched');
    assert.equal(withSegmentation(null, '1 PERFECT'), null, 'no possible row stays none');
    assert.equal(withSegmentation(c, null), c);
});

test('profileInScope: a profile survives new filters only inside its section and its Segmentation filter', () => {
    assert.equal(profileInScope({ meter: ['POSTPAID'] }, '1 PERFECT'), true);
    assert.equal(profileInScope({ meter: ['POSTPAID'], segmentation: ['1 PERFECT'] }, '1 PERFECT'), true);
    assert.equal(profileInScope({ meter: ['POSTPAID'], segmentation: ['2 RELIABLE'] }, '1 PERFECT'), false);
    assert.equal(profileInScope(null, '1 PERFECT'), false);
});

test('profileTotals: the six official profiles apart from the rest', () => {
    const profiles = ['1 PERFECT', '2 RELIABLE', '3 Occasionnal', '4 At-Risk', '5 Delinquant', '6 Défaillant'];
    const pairs = profiles.map((value, i) => ({ value, count: (i + 1) * 10 })).concat([{ value: 'Non renseigné', count: 7 }]);
    assert.deepEqual(profileTotals(pairs, profiles), { classified: 210, unclassified: 7 });
    assert.deepEqual(profileTotals([], profiles), { classified: 0, unclassified: 0 });
});

test('view: Postpaid = segmentation then Défaillant breakdown, Prepaid = segmentation only; nothing else drawn', () => {
    const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
    const start = view.indexOf('<?php foreach ($sections as $sk => $sec): ?>\n<section');
    const body = view.slice(start, view.indexOf('</section>', start));
    const seg = body.indexOf('bscdChart_<?= $sk ?>_segmentation');
    const fail = body.indexOf('bscdChart_postpaid_failure');
    assert.ok(seg !== -1 && fail > seg, 'segmentation before the Défaillant breakdown');
    assert.ok(body.indexOf("<?php if ($sk === 'postpaid'): ?>") < fail, 'Défaillant in Postpaid only');
    ['data-kpi', 'bscd-kpi', 'byRegion', 'byStatus', 'meterType', 'data-metertype'].forEach((s) => assert.ok(!body.includes(s), s));
    ['data-profile-total', 'data-profile-stats', 'data-profile-note', 'data-failure-total', 'data-failure-stats', 'data-failure-note',
        'bscd-viz__side', 'bscd-viz__total', 'classifiedTotal'].forEach((s) => assert.ok(!body.includes(s), s));
    assert.equal((body.match(/<canvas /g) || []).length, 2, 'two charts in the loop body: segmentation + (Postpaid) Défaillant');
    assert.equal((body.match(/class="bscd-chart-card /g) || []).length, 2);
});
