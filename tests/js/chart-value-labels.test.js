// Run: node --test tests/js/chart-value-labels.test.js
// Permanent chart value labels (public/assets/js/chart-value-labels.js): the
// DOM-free placement, and how the dashboard wires the plugin in.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { plugin, planBars, planArcs, planPoints, textOn, addPadding } = require('../../public/assets/js/chart-value-labels.js');

const ROOT = path.join(__dirname, '../..');
const view = fs.readFileSync(path.join(ROOT, 'app/Views/dashboard/index.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'public/assets/js/dashboard.js'), 'utf8');

const measure = (text, size) => String(text).length * size * 0.6;
const colors = { text: '#374151', dark: '#1f2937', light: '#ffffff' };
const bounds = { left: 2, top: 2, right: 598, bottom: 238 };
const box = (l) => {
    const w = l.angle ? l.h : l.w, h = l.angle ? l.w : l.h;
    return { left: l.x - w / 2, right: l.x + w / 2, top: l.y - h / 2, bottom: l.y + h / 2 };
};
const overlap = (a, b) => { const r = box(a), s = box(b); return r.left < s.right && s.left < r.right && r.top < s.bottom && s.top < r.bottom; };
const vbar = (x, y, value, bg = '#1f6fb2') => ({ x, y, base: 200, horizontal: false, value, text: value.toLocaleString('fr-FR'), bg });

test('vertical bars, few values: every value above its bar, same upright size, no overlap', () => {
    const labels = planBars([vbar(100, 150, 100), vbar(250, 100, 200), vbar(400, 175, 50)], bounds, measure, colors);
    assert.deepEqual(labels.map((l) => l.lines[0].text), ['100', '200', '50']);
    labels.forEach((l, i) => {
        assert.equal(l.angle, 0);
        assert.equal(l.lines[0].size, 11);
        assert.ok(box(l).bottom <= [150, 100, 175][i], 'label sits above the bar end');
        assert.equal(l.halo, true);
    });
});

test('vertical bars, dense: labels rotate instead of being dropped, and never overlap', () => {
    const bars = Array.from({ length: 16 }, (_, i) => vbar(20 + i * 18, 60 + i * 8, 1453055 - i * 90000));
    const labels = planBars(bars, bounds, measure, colors);
    assert.equal(labels.length, 16);
    labels.forEach((l) => assert.equal(l.angle, -Math.PI / 2));
    for (let i = 1; i < labels.length; i++) assert.ok(!overlap(labels[i - 1], labels[i]));
});

test('bar touching the top edge: label inside the bar, contrasting colour, within bounds', () => {
    const [l] = planBars([vbar(100, 5, 1453055)], bounds, measure, colors);
    assert.equal(l.halo, false);
    assert.equal(l.color, '#ffffff');
    assert.ok(box(l).top >= 5);
    // a light bar gets dark text
    const [d] = planBars([vbar(100, 5, 1453055, '#c7ccd6')], bounds, measure, colors);
    assert.equal(d.color, '#1f2937');
});

test('zero, negative and sub-pixel bars: label on the side of the value sign', () => {
    const [zero, neg, tiny] = planBars([vbar(100, 200, 0), { ...vbar(200, 230, -8.25), text: '-8,25' }, { ...vbar(300, 200.4, 400) }], bounds, measure, colors);
    assert.ok(box(zero).bottom <= 200);
    assert.ok(box(neg).top >= 230 || box(neg).bottom <= bounds.bottom, 'negative bar label below it, inside the canvas');
    assert.ok(box(neg).top > 200);
    assert.ok(box(tiny).bottom <= 200, 'a ~0.4 px positive bar still gets its label above the axis');
});

test('horizontal bars: label past the bar end, inside when the edge is too close', () => {
    const hbar = (y, x, v) => ({ x, y, base: 100, horizontal: true, value: v, text: v.toLocaleString('fr-FR'), bg: '#1f6fb2' });
    const [out, inner] = planBars([hbar(20, 300, 823486), hbar(40, 596, 1453055)], bounds, measure, colors);
    assert.ok(box(out).left >= 300);
    assert.equal(inner.halo, false);
    assert.ok(box(inner).right <= 596);
    assert.ok(out.lines[0].size <= 11 && out.lines[0].size >= 8);
});

test('label sizes follow the chart font (PNG export at 14 px)', () => {
    const [l] = planBars([vbar(100, 150, 100)], bounds, measure, colors, 14 / 12);
    assert.equal(l.lines[0].size, 13);
});

test('doughnut: % and value inside a wide slice, % outside a thin one, outside labels de-overlapped', () => {
    const ring = { x: 300, y: 120, innerRadius: 62, outerRadius: 100, bg: '#1f6fb2' };
    const arcs = [
        { ...ring, startAngle: -Math.PI / 2, endAngle: Math.PI / 2, text: '600', pctText: '60,0 %' },
        { ...ring, startAngle: Math.PI / 2, endAngle: 1.5 * Math.PI - 0.02, text: '398', pctText: '39,8 %' },
        { ...ring, startAngle: 1.5 * Math.PI - 0.02, endAngle: 1.5 * Math.PI - 0.01, text: '1', pctText: '0,1 %' },
        { ...ring, startAngle: 1.5 * Math.PI - 0.01, endAngle: 1.5 * Math.PI, text: '1', pctText: '0,1 %' },
    ];
    const labels = planArcs(arcs, bounds, measure, colors);
    assert.equal(labels.length, 4, 'no slice loses its label');
    assert.deepEqual(labels[0].lines.map((l) => l.text), ['60,0 %', '600']);
    const outside = labels.filter((l) => l.halo);
    assert.equal(outside.length, 2);
    outside.forEach((l) => assert.equal(l.lines[0].text, '0,1 %'));
    assert.ok(!overlap(outside[0], outside[1]));
});

test('line series: labels near their point, the colliding one moved, series colour kept', () => {
    const pts = [
        { x: 100, y: 100, text: '120', color: '#1f6fb2' },
        { x: 100, y: 102, text: '118', color: '#d97706' },
    ];
    const [a, b] = planPoints(pts, bounds, measure, colors);
    assert.ok(!overlap(a, b));
    assert.equal(b.color, '#d97706');
});

test('empty chart: nothing to draw', () => {
    assert.deepEqual(planBars([], bounds, measure, colors), []);
    assert.deepEqual(planArcs([], bounds, measure, colors), []);
    assert.deepEqual(planPoints([], bounds, measure, colors), []);
});

test('helpers: contrast choice and padding merge', () => {
    assert.equal(textOn('#142a4c', '#1f2937', '#ffffff'), '#ffffff');
    assert.equal(textOn('#c7ccd6', '#1f2937', '#ffffff'), '#1f2937');
    assert.deepEqual(addPadding(4, { top: 10, right: 0, bottom: 0, left: 0 }), { top: 14, right: 4, bottom: 4, left: 4 });
    assert.deepEqual(addPadding(undefined, undefined), { top: 0, right: 0, bottom: 0, left: 0 });
});

test('plugin: formatters are not scriptable, labels on by default', () => {
    assert.equal(plugin.id, 'bscdValueLabels');
    assert.equal(plugin.defaults.display, true);
    assert.equal(plugin.descriptors._scriptable, false);
});

test('dashboard: plugin loaded after Chart.js, registered with the app fmt, tooltips kept, counts only', () => {
    const chartjs = view.indexOf('vendor/chartjs/chart.umd.min.js');
    const labels = view.indexOf('assets/js/chart-value-labels.js');
    assert.ok(chartjs !== -1 && labels > chartjs && labels < view.indexOf("assets/js/dashboard.js'"));
    assert.match(js, /Chart\.register\(window\.bscdChartValueLabels\.plugin\)/);
    assert.match(js, /bscdValueLabels\.formatValue = fmt;/);
    assert.doesNotMatch(js, /formatPercent|\bpct\(|\.percent\(/);
    assert.equal((js.match(/tooltip: \{ callbacks:/g) || []).length, 2); // segmentation + Défaillant
});
