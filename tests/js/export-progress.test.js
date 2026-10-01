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

test('error answer with its progress + failure payload → still a terminal "error", never "unreachable"', () => {
    // GET /exports/{id} for an error now carries progress, timing and
    // `failure` (never a key named `error`: that is bscdFetch's failed-poll shape).
    const t = createTracker({});
    t.activate(45);
    const step = t.handle(45, {
        status: 'error', reference: 'EXPJOB-20261001-24093',
        progress: { percent: 0, processed: 0, total: null, exported: 0 },
        timing: { waitSeconds: null, elapsedSeconds: 0, final: true },
        failure: { reference: 'EXPJOB-20261001-24093', message: "L'export a échoué côté serveur." }
    });
    assert.equal(step.kind, 'error');
    assert.equal(step.stop, true);
    assert.equal(step.download, null);
});

test('progressView: error → no bar, no counts', () => {
    const view = progressView({ status: 'error', progress: { percent: 28, processed: 950000, total: TOTAL, exported: 950000 } });
    assert.equal(view.state, 'error');
    assert.equal(view.percent, null);
    assert.equal(view.processed, null);
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

test('failed poll (HTTP error, login redirect, empty) → "unreachable": keeps polling, renders a warning, no download', () => {
    const t = createTracker({});
    t.activate(5);
    for (const answer of [
        { error: 'http', status: 200, message: 'Réponse inattendue du serveur (HTTP 200).' }, // /login page
        { error: 'not_found', status: 404 },
        { error: 'http', status: 500 },
        null,
    ]) {
        const step = t.handle(5, answer);
        assert.deepEqual([step.stop, step.render, step.download, step.kind], [false, true, null, 'unreachable']);
    }
    // The next good answer is rendered normally.
    assert.equal(t.handle(5, running(40, 1321136)).view.percent, 40);
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

// ── duration line (server `timing`, animated locally between polls) ──
const { formatDuration, createDurationClock } = require('../../public/assets/js/export-progress.js');
const at = (s) => s * 1000; // local clock in ms

test('formatDuration → HH:MM:SS', () => {
    assert.equal(formatDuration(3), '00:00:03');
    assert.equal(formatDuration(27), '00:00:27');
    assert.equal(formatDuration(102), '00:01:42');
    assert.equal(formatDuration(440), '00:07:20');
    assert.equal(formatDuration(90061), '25:01:01');
    assert.equal(formatDuration(-5), '00:00:00');
    assert.equal(formatDuration(null), '00:00:00');
});

test('pending → "Durée d\'attente" from the server wait time; nothing when unavailable', () => {
    const c = createDurationClock();
    assert.equal(c.display(at(0)), null);
    c.sync({ status: 'pending', timing: { waitSeconds: null, elapsedSeconds: null, final: false } }, at(0));
    assert.equal(c.display(at(1)), null);
    c.sync({ status: 'pending', timing: { waitSeconds: 26, elapsedSeconds: null, final: false } }, at(3));
    assert.equal(c.display(at(3)).text, "Durée d'attente : 00:00:26");
});

test('running → starts from started_at (never the queue time) and ticks every second between polls', () => {
    const c = createDurationClock();
    c.sync({ status: 'pending', timing: { waitSeconds: 40, elapsedSeconds: null, final: false } }, at(0));
    c.sync({ status: 'running', timing: { waitSeconds: null, elapsedSeconds: 30, final: false } }, at(3));
    assert.deepEqual([at(3), at(4), at(5), at(6)].map((t) => c.display(t).text),
        ['Durée écoulée : 00:00:30', 'Durée écoulée : 00:00:31', 'Durée écoulée : 00:00:32', 'Durée écoulée : 00:00:33']);
});

test('running → a new poll never makes the value go back (nor to zero)', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 30 } }, at(0));
    assert.equal(c.display(at(3.9)).text, 'Durée écoulée : 00:00:33');
    // Late answer: the server says 32 s at local 3.9 s.
    c.sync({ status: 'running', timing: { elapsedSeconds: 32 } }, at(3.9));
    assert.equal(c.display(at(4)).text, 'Durée écoulée : 00:00:33');
    assert.equal(c.display(at(5.9)).text, 'Durée écoulée : 00:00:34');
    // Poll without usable timing keeps counting from the last reference.
    c.sync({ status: 'running', timing: { elapsedSeconds: null } }, at(7));
    assert.equal(c.display(at(7)).text, 'Durée écoulée : 00:00:35');
});

test('done → "Durée totale" = finished_at - started_at, fixed for good', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 400 } }, at(0));
    c.sync({ status: 'done', timing: { elapsedSeconds: 440, final: true } }, at(3));
    assert.equal(c.isFinal(), true);
    assert.equal(c.display(at(3)).text, 'Durée totale : 00:07:20');
    assert.equal(c.display(at(600)).text, 'Durée totale : 00:07:20');
    c.sync({ status: 'done', timing: { elapsedSeconds: 999, final: true } }, at(700)); // duplicate answer
    assert.equal(c.display(at(700)).text, 'Durée totale : 00:07:20');
});

test('done without a server total → fallback: frozen at the moment "done" is received', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 100 } }, at(0));
    c.sync({ status: 'done' }, at(5));
    assert.equal(c.display(at(5)).text, 'Durée totale : 00:01:45');
    assert.equal(c.display(at(60)).text, 'Durée totale : 00:01:45');
});

test('error → stops with the time actually spent', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 50 } }, at(0));
    c.sync({ status: 'error', timing: { elapsedSeconds: 52, final: true } }, at(3));
    assert.equal(c.isFinal(), true);
    assert.equal(c.display(at(90)).text, 'Durée totale : 00:00:52');
});

test('dashboard: duration line sits right after the exported-rows line', () => {
    const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js/dashboard.js'), 'utf8');
    assert.match(js, /data-progress-exported><\/div>' \+\s*'<div class="text-muted" data-progress-duration><\/div>/);
    assert.doesNotMatch(js, /[Dd]urée du téléchargement/);
});

test('progress bar: filled width = API percent, no minimum width (0 % = empty bar)', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/assets/css/custom.css'), 'utf8');
    const rule = css.match(/\.bscd-export-progress-track \.progress-bar\s*\{([^}]*)\}/);
    assert.ok(rule, 'progress-bar rule present');
    assert.doesNotMatch(rule[1], /min-width|padding/);
    const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js/dashboard.js'), 'utf8');
    assert.match(js, /bar\.style\.width = view\.percent \+ '%'/);
    // The % label lives beside the bar, never inside it.
    assert.match(js, /\[data-progress-percent\]'\)\.textContent = view\.percent \+ ' %'/);
    assert.doesNotMatch(js, /bar\.textContent/);
});

// ── cancellation ────────────────────────────────────────────────────

test('cancelled → terminal, no download, no progress shown', () => {
    const t = createTracker({});
    t.activate(7);
    const step = t.handle(7, { status: 'cancelled', progress: { percent: 61, processed: 2025000, total: TOTAL, exported: 1850000 } });
    assert.equal(step.kind, 'cancelled');
    assert.equal(step.stop, true);
    assert.equal(step.download, null);
    assert.equal(step.view.percent, null);
    assert.equal(step.view.processed, null);
    assert.equal(step.view.title, 'Export annulé.');
});

test('confirmed cancellation → a "done" or "running" answer still in flight is ignored, never downloaded', () => {
    const downloaded = {};
    const t = createTracker(downloaded);
    t.activate(7);
    t.markCancelled(7);
    [{ status: 'done', downloadUrl: '/exports/7/download' }, running(61, 2025000)].forEach((job) => {
        const step = t.handle(7, job);
        assert.equal(step.kind, 'cancelled');
        assert.equal(step.download, null);
        assert.equal(step.render, false);
        assert.equal(step.stop, true);
    });
    assert.deepEqual(downloaded, {});
});

test('cancelling one job does not affect another job', () => {
    const t = createTracker({});
    t.markCancelled(7);
    const step = t.handle(8, { status: 'done', downloadUrl: '/exports/8/download' });
    assert.equal(step.kind, 'done');
    assert.equal(step.download, '/exports/8/download');
});

test('duration clock → cancelled freezes the elapsed time ("Durée écoulée")', () => {
    const clock = createDurationClock();
    clock.sync({ status: 'running', timing: { elapsedSeconds: 250 } }, 0);
    clock.sync({ status: 'cancelled', timing: { elapsedSeconds: 252, final: true } }, 1000);
    assert.equal(clock.isFinal(), true);
    assert.equal(clock.display(60000).text, 'Durée écoulée : 00:04:12');
    assert.equal(clock.display(600000).text, 'Durée écoulée : 00:04:12'); // no longer ticking
});

test('duration clock → cancelled without a server total keeps what was shown', () => {
    const clock = createDurationClock();
    clock.sync({ status: 'running', timing: { elapsedSeconds: 10 } }, 0);
    clock.sync({ status: 'cancelled' }, 5000);
    assert.equal(clock.display(99000).text, 'Durée écoulée : 00:00:15');
});
