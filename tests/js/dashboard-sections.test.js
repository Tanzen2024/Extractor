// Run: node --test tests/js/dashboard-sections.test.js
// "Vue Postpaid" / "Vue Prepaid": the criteria each section requests
// (public/assets/js/dashboard-sections.js) — the page filters with the
// METER filter narrowed to the section's universe, nothing else.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { SECTIONS, universeOf, sectionCriteria, unassigned } = require('../../public/assets/js/dashboard-sections.js');

const ROOT = path.join(__dirname, '../..');

// Same rules as dashboard.js meterUniverse() / segmentationUniverse().
const meterUniverse = (v) => {
    const n = String(v).trim().toUpperCase();
    if (n === 'POSTPAID' || n.includes('COMMUNICANT')) return 'POSTPAID';
    if (n === 'PREPAID') return 'PREPAID';
    return null;
};
const POSTPAID_SEGS = ['1 PERFECT', '8 Autre'];
const PREPAID_SEGS = ['5-Dormant', 'Other'];
const segUniverse = (v) => (POSTPAID_SEGS.includes(v) ? 'POSTPAID' : PREPAID_SEGS.includes(v) ? 'PREPAID' : null);
const OFFERED = ['POSTPAID', 'PREPAID', 'Compteurs Communicants'];
const crit = (applied, key) => sectionCriteria(applied, universeOf(key), OFFERED, meterUniverse, segUniverse);

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
    assert.equal(crit({ segmentation: ['Other'] }, 'postpaid'), null);
    // A value in no known list is left to the server, in both sections.
    assert.deepEqual(crit({ segmentation: ['X'] }, 'prepaid').segmentation, ['X']);
});

test('the snapshot offers no meter of a universe -> that section is null', () => {
    assert.equal(sectionCriteria({}, 'PREPAID', ['POSTPAID'], meterUniverse, segUniverse), null);
});

test('unassigned rows: page count minus both sections, null while a figure is missing, never negative', () => {
    assert.equal(unassigned(100, { postpaid: 60, prepaid: 35 }), 5);
    assert.equal(unassigned(100, { postpaid: 60 }), null);
    assert.equal(unassigned(null, { postpaid: 60, prepaid: 40 }), null);
    assert.equal(unassigned(90, { postpaid: 60, prepaid: 40 }), 0);
});

test('view: one section per universe, quick links, scoped detail table; script before dashboard.js', () => {
    const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
    assert.match(view, /'postpaid' => \['icon' => '[^']+', 'title' => 'Dashboard\.sections\.postpaid\.title'/);
    assert.match(view, /'prepaid'  => \['icon' => '[^']+', 'title' => 'Dashboard\.sections\.prepaid\.title'/);
    assert.ok(view.includes('data-section="<?= $sk ?>"'));
    assert.ok(view.includes('href="#bscdSection-<?= $sk ?>"') && view.includes('href="#bscdSection-detail"'));
    assert.ok(view.includes('data-table-scope="<?= $scope ?>"'));
    assert.ok(view.indexOf("base_url('assets/js/dashboard-sections.js')") < view.indexOf("base_url('assets/js/dashboard.js')"));
});

test('i18n: section titles in French and English', () => {
    const fr = fs.readFileSync(path.join(ROOT, 'app/Language/fr/Dashboard.php'), 'utf8');
    const en = fs.readFileSync(path.join(ROOT, 'app/Language/en/Dashboard.php'), 'utf8');
    for (const [src, a, b] of [[fr, 'Vue Postpaid', 'Rapports et analyses Postpaid'], [fr, 'Vue Prepaid', 'Rapports et analyses Prepaid'],
        [en, 'Postpaid View', 'Postpaid Reports & Analytics'], [en, 'Prepaid View', 'Prepaid Reports & Analytics']]) {
        assert.ok(src.includes("'" + a + "'") && src.includes("'" + b + "'"), a);
    }
});
