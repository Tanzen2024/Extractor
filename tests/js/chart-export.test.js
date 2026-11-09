// Run: node --test tests/js/chart-export.test.js
// Per-chart export (public/assets/js/chart-export.js): the files hold exactly
// the plotted dataset, and the dashboard wires every chart into it.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const cx = require('../../public/assets/js/chart-export.js');

const ROOT = path.join(__dirname, '../..');
const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');

// A Chart.js-like instance: what the chart actually plots.
const chart = (labels, data) => ({ data: { labels, datasets: [{ data }] } });

test('rows come from the chart itself: plotted labels, plotted values, plotted total', () => {
    const t = cx.tableFromSpec({ chart: chart(['LITTORAL', 'CENTRE', 'Autres'], [600, 300, 100]) });
    assert.equal(t.total, 1000);
    assert.deepEqual(t.rows.map((r) => [r.label, r.count]), [['LITTORAL', 600], ['CENTRE', 300], ['Autres', 100]]);
    assert.equal(t.withValue, false);
});

test('a display label differing from its technical value adds a "Valeur source" column', () => {
    const t = cx.tableFromSpec({ chart: chart(['Suspect Dormant', '1 Gros payeur'], [5, 7]), values: ['4-Suspect Dormant', '1 Gros payeur'] });
    assert.equal(t.withValue, true);
    const csv = cx.toCsv(t, 'fr').split('\r\n');
    assert.equal(csv[0], 'Catégorie;Valeur source;Nombre de clients');
    assert.equal(csv[1], 'Suspect Dormant;4-Suspect Dormant;5');
});

test('CSV fr = ";", en = ","; translated headers; counts only', () => {
    const t = cx.tableFromSpec({ chart: chart(['A', 'B'], [1, 2]) });
    assert.equal(cx.toCsv(t, 'fr'), 'Catégorie;Nombre de clients\r\nA;1\r\nB;2\r\n');
    assert.equal(cx.toCsv(t, 'en'), 'Category,Number of customers\r\nA,1\r\nB,2\r\n');
});

test('CSV quoting and formula neutralisation', () => {
    const t = cx.tableFromSpec({ rows: [{ label: 'X;"Y"', count: 1 }, { label: '=HYPERLINK("x")', count: 1 }] });
    const lines = cx.toCsv(t, 'fr').split('\r\n');
    assert.equal(lines[1], '"X;""Y""";1');
    assert.equal(lines[2], '"\'=HYPERLINK(""x"")";1');
});

test('empty dataset = no rows (the UI refuses to build a file)', () => {
    assert.equal(cx.tableFromSpec({ chart: chart([], []) }).rows.length, 0);
    assert.equal(cx.tableFromSpec({ rows: [] }).rows.length, 0);
});

test('file names: slug of the title + ISO date, accents/specials removed', () => {
    const d = new Date(2026, 9, 23);
    assert.equal(cx.fileName('Répartition par région', 'csv', d), 'repartition_par_region_2026-10-23.csv');
    assert.equal(cx.fileName('Segmentation — Profil Payeur / 2026', 'png', d), 'segmentation_profil_payeur_2026_2026-10-23.png');
    assert.equal(cx.fileName('***', 'xlsx', d), 'graphique_2026-10-23.xlsx');
});

// Minimal ZIP reader: checks the archive structure and every CRC.
function unzip(buf) {
    const dv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
    const eocd = buf.length - 22;
    assert.equal(dv.getUint32(eocd, true), 0x06054b50);
    const count = dv.getUint16(eocd + 10, true);
    let p = dv.getUint32(eocd + 16, true);
    const files = {};
    for (let i = 0; i < count; i++) {
        assert.equal(dv.getUint32(p, true), 0x02014b50);
        const crc = dv.getUint32(p + 16, true);
        const size = dv.getUint32(p + 24, true);
        const nameLen = dv.getUint16(p + 28, true);
        const off = dv.getUint32(p + 42, true);
        const name = Buffer.from(buf.subarray(p + 46, p + 46 + nameLen)).toString('utf8');
        assert.equal(dv.getUint32(off, true), 0x04034b50);
        const start = off + 30 + dv.getUint16(off + 26, true);
        const data = buf.subarray(start, start + size);
        assert.equal(cx.crc32(data), crc, name + ' CRC');
        files[name] = Buffer.from(data).toString('utf8');
        p += 46 + nameLen;
    }
    return files;
}

test('XLSX: valid package, numeric counts only, Contexte sheet with filters', () => {
    const t = cx.tableFromSpec({ chart: chart(['LITTORAL', 'Autres'], [3, 1]) });
    const bytes = cx.toXlsx(t, {
        title: 'Répartition par région', filters: [{ label: 'Région', text: 'LITTORAL & <SUD>' }],
        source: 'Extractor', date: new Date(2026, 9, 23, 14, 5)
    }, 'fr');
    const f = unzip(bytes);
    assert.deepEqual(Object.keys(f).sort(), ['[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels',
        'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml']);
    assert.match(f['xl/workbook.xml'], /<sheet name="Données" sheetId="1" r:id="rId1"\/><sheet name="Contexte"/);
    assert.match(f['xl/worksheets/sheet1.xml'], /<c r="B2" s="2"><v>3<\/v><\/c><\/row>/);
    assert.match(f['xl/worksheets/sheet2.xml'], /LITTORAL &amp; &lt;SUD&gt;/);
    assert.match(f['xl/worksheets/sheet2.xml'], /23\/10\/2026 14:05/);
    assert.doesNotMatch(f['xl/styles.xml'], /%/);
});

test('XLSX in English: English sheet names and headers', () => {
    const t = cx.tableFromSpec({ chart: chart(['A'], [1]) });
    const f = unzip(cx.toXlsx(t, { title: 'T', filters: [], date: new Date(2026, 0, 1) }, 'en'));
    assert.match(f['xl/workbook.xml'], /name="Data".*name="Context"/);
    assert.match(f['xl/worksheets/sheet1.xml'], />Category<.*>Number of customers</);
    assert.match(f['xl/worksheets/sheet2.xml'], /None \(whole base\)/);
});

test('every UI string exists in fr and en', () => {
    assert.deepEqual(Object.keys(cx.messages.fr).sort(), Object.keys(cx.messages.en).sort());
    assert.equal(cx.messages.fr.dataHeader, 'Exporter les données');
    assert.equal(cx.messages.en.png, 'Download PNG');
});

test('dashboard: one export menu per chart card, script loaded before dashboard.js', () => {
    // Keys are "{section}-{chart}", built by the PHP loop of the sections.
    // Only the segmentation of each section + the Défaillant breakdown (Postpaid) remain.
    assert.ok(view.includes('data-chart-export="<?= $sk ?>-segmentation"') && view.includes('data-chart-export="postpaid-failure"'));
    assert.ok(view.includes('data-chart-wrap="segmentation"') && view.includes('data-chart-wrap="failure"'));
    assert.ok(!view.includes('data-chart-wrap="meterType"'), 'Type de compteurs no longer displayed');
    assert.match(view, /data-export-label="[^"]*" data-export-prefix="<\?= \$sk \?>"/);
    assert.ok(view.indexOf("base_url('assets/js/chart-export.js')") < view.indexOf("base_url('assets/js/dashboard.js')"));
    assert.ok(view.includes('assets/css/chart-export.css'));
});

test('dashboard.js registers the plotted charts after rendering and clears them on reload/empty', () => {
    const render = /function renderCharts\(key, stats, criteria\) \{([\s\S]*?)\n    \}/.exec(js)[1];
    assert.match(render, /exportFilters\[key\] = Object\.keys\(criteria/);
    assert.match(render, /chart: state\.charts\[id\]/);
    assert.match(js, /function destroyChart\(id\) \{[\s\S]*?chartExport\(chartKey\(id\), null\);/);
    assert.doesNotMatch(js, /-meterType'/);
    assert.equal((js.match(/chartPairs\[canvasId\] = pairs;/g) || []).length, 1); // segmentation (the failure doughnut maps its rows)
});
