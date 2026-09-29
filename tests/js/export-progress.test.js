// Run: node --test tests/js/export-progress.test.js
// Pure logic of the asynchronous export progress (public/assets/js/export-progress.js).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { clampPercent, progressView, createTracker } = require('../../public/assets/js/export-progress.js');

const TOTAL = 3302841;
const running = (percent, processed, total = TOTAL) =>
    ({ status: 'running', progress: { percent, processed, total, exported: processed } });

test('pending → "Export en attente…" at 0 %, no line counts', () => {
    const v = progressView({ status: 'pending', progress: { percent: 0, processed: 0, total: null, exported: 0 } });
    assert.equal(v.state, 'pending');
    assert.equal(v.percent, 0);
    assert.equal(v.title, 'Export en attente…');
    assert.equal(v.processed, null);
    assert.equal(v.exported, null);
});

test('running → the API percentage and counts, untouched', () => {
    const v = progressView({ status: 'running', progress: { percent: 42, processed: 1400000, total: TOTAL, exported: 1400000 } });
    assert.deepEqual(v, { state: 'running', title: 'Export en cours…', percent: 42, processed: 1400000, total: TOTAL, exported: 1400000 });
});

test('running → filtered export: scan progress and exported rows differ', () => {
    const v = progressView({ status: 'running', progress: { percent: 50, processed: 1651421, total: TOTAL, exported: 100000 } });
    assert.equal(v.percent, 50);
    assert.equal(v.processed, 1651421);
    assert.equal(v.exported, 100000);
});

test('running → 99 % stays 99, and never 100 while running', () => {
    assert.equal(progressView(running(99, 3300000)).percent, 99);
    assert.equal(progressView(running(100, TOTAL)).percent, 99);
    assert.equal(progressView(running(250, 5000000)).percent, 99);
});

test('done → 100 %', () => {
    const v = progressView({ status: 'done', progress: { percent: 100, processed: TOTAL, total: TOTAL, exported: TOTAL } });
    assert.equal(v.percent, 100);
    assert.equal(v.title, 'Fichier prêt — téléchargement…');
    assert.equal(v.exported, TOTAL);
    assert.equal(progressView({ status: 'done' }).percent, 100); // job finished before progress existed
});

test('running with progress absent / null / undefined / total 0 → "Export en cours…" with NO percentage', () => {
    [
        { status: 'running' },
        { status: 'running', progress: null },
        { status: 'running', progress: undefined },
        { status: 'running', progress: { percent: null, processed: null, total: null, exported: null } },
        { status: 'running', progress: { percent: 0, processed: 0, total: 0, exported: 0 } },
        { status: 'running', progress: { percent: 'abc', processed: 10, total: TOTAL, exported: 10 } },
    ].forEach((job) => {
        const v = progressView(job);
        assert.equal(v.title, 'Export en cours…');
        assert.equal(v.percent, null, JSON.stringify(job));
        assert.equal(v.processed, null);
    });
    assert.equal(progressView(null), null);
    assert.equal(progressView({ status: 'weird' }), null);
});

test('the value shown is always an integer within 0..100', () => {
    assert.equal(progressView(running(-20, 0)).percent, 0);
    assert.equal(progressView(running(47.9, 1)).percent, 47);
    assert.equal(progressView(running(50, 9999999)).processed, TOTAL); // processed never shown above total
    [undefined, null, NaN, Infinity, -Infinity, '12', 101, -1].forEach((x) => {
        const p = clampPercent(x);
        assert.ok(Number.isInteger(p) && p >= 0 && p <= 100, String(x));
    });
});

test('error → no download, polling stops', () => {
    const t = createTracker({});
    t.activate(7);
    const step = t.handle(7, { status: 'error', reference: 'EXPJOB-1' });
    assert.equal(step.kind, 'error');
    assert.equal(step.download, null);
    assert.equal(step.stop, true);
    assert.equal(step.render, true);
});

test('done → automatic download triggered exactly once, even with several "done" answers', () => {
    const downloadedJobs = {};
    const t = createTracker(downloadedJobs);
    t.activate(25);
    const done = { status: 'done', downloadUrl: '/exports/25/download', progress: { percent: 100 } };

    const first = t.handle(25, done);
    assert.equal(first.kind, 'done');
    assert.equal(first.download, '/exports/25/download');
    assert.equal(first.stop, true);
    assert.equal(first.view.percent, 100);
    assert.equal(downloadedJobs[25], true); // same downloadedJobs map as dashboard.js

    for (let i = 0; i < 3; i++) {
        const again = t.handle(25, done);
        assert.equal(again.kind, 'duplicate');
        assert.equal(again.download, null);
        assert.equal(again.render, false);
    }
});

test('pending/running keep polling and never download', () => {
    const t = createTracker({});
    t.activate(3);
    for (const job of [{ status: 'pending' }, running(10, 330000), running(99, 3300000)]) {
        const step = t.handle(3, job);
        assert.equal(step.stop, false);
        assert.equal(step.download, null);
        assert.equal(step.render, true);
    }
});

test('several simultaneous jobs: only the active one renders, each downloads once', () => {
    const t = createTracker({});
    t.activate(1);
    t.deactivate();          // modal reopened
    t.activate(2);           // second export launched

    const oldRunning = t.handle(1, running(80, 2600000));
    assert.equal(oldRunning.render, false); // job 1 can no longer touch the status area

    const oldDone = t.handle(1, { status: 'done', downloadUrl: '/exports/1/download' });
    assert.equal(oldDone.render, false);
    assert.equal(oldDone.download, '/exports/1/download'); // but its file is still downloaded, once
    assert.equal(t.handle(1, { status: 'done', downloadUrl: '/exports/1/download' }).kind, 'duplicate');

    const current = t.handle(2, running(30, 990000));
    assert.equal(current.render, true);
    const currentDone = t.handle(2, { status: 'done', downloadUrl: '/exports/2/download' });
    assert.equal(currentDone.render, true);
    assert.equal(currentDone.download, '/exports/2/download');
});

test('unknown status is ignored (keeps polling, no render, no download)', () => {
    const t = createTracker({});
    t.activate(9);
    const step = t.handle(9, { status: 'weird' });
    assert.deepEqual([step.stop, step.render, step.download], [false, false, null]);
});

test('dashboard: Export Excel button still hidden, no "Télécharger" button, script order', () => {
    const view = fs.readFileSync(path.join(__dirname, '../../app/Views/dashboard/index.php'), 'utf8');
    const rendered = view.replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '');
    assert.ok(rendered.includes('data-export="csv"'));
    assert.ok(!rendered.includes('data-export="xlsx"'));
    assert.ok(rendered.indexOf('assets/js/export-progress.js') < rendered.indexOf('assets/js/dashboard.js'));

    const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js/dashboard.js'), 'utf8');
    assert.ok(!js.includes('>Télécharger<'), 'no manual download button');
    assert.ok(js.includes('var downloadedJobs = {}'), 'downloadedJobs kept');
});
