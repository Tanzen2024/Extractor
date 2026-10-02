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

test('pending → "Export en cours" / "En attente du traitement…" at 0 %, no line counts', () => {
    const v = progressView({ status: 'pending', progress: { percent: 0, processed: 0, total: null, exported: 0 } });
    assert.equal(v.state, 'pending');
    assert.equal(v.phase, 'generating');
    assert.equal(v.percent, 0);
    assert.equal(v.heading, 'Export en cours');
    assert.equal(v.title, 'En attente du traitement…');
    assert.equal(v.processed, null);
    assert.equal(v.exported, null);
});

test('running → the API percentage and counts, untouched', () => {
    const v = progressView({ status: 'running', progress: { percent: 42, processed: 1400000, total: TOTAL, exported: 1400000 } });
    assert.deepEqual(v, {
        state: 'running', phase: 'generating', heading: 'Export en cours', title: 'Préparation du fichier…',
        percent: 42, indeterminate: false, processed: 1400000, total: TOTAL, exported: 1400000, fileSize: null
    });
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

test('done → "Fichier prêt / Téléchargement lancé", no bar, NEVER 100 % (the download has only started)', () => {
    const v = progressView({ status: 'done', fileSize: 635437056, progress: { percent: 100, processed: 3305219, total: 3305219, exported: 2431711 } });
    assert.equal(v.phase, 'downloading');
    assert.equal(v.heading, 'Fichier prêt');
    assert.equal(v.title, 'Téléchargement lancé');
    assert.equal(v.percent, null);
    assert.equal(v.indeterminate, false);
    assert.equal(v.exported, 2431711);
    assert.equal(v.fileSize, 635437056);
    assert.equal(progressView({ status: 'done' }).percent, null); // job finished before progress existed
});

test('running with progress absent / null / undefined / total 0 → "Préparation du fichier…" with NO percentage', () => {
    [
        { status: 'running' },
        { status: 'running', progress: null },
        { status: 'running', progress: undefined },
        { status: 'running', progress: { percent: null, processed: null, total: null, exported: null } },
        { status: 'running', progress: { percent: 0, processed: 0, total: 0, exported: 0 } },
        { status: 'running', progress: { percent: 'abc', processed: 10, total: TOTAL, exported: 10 } },
    ].forEach((job) => {
        const v = progressView(job);
        assert.equal(v.heading, 'Export en cours');
        assert.equal(v.title, 'Préparation du fichier…');
        assert.equal(v.percent, null, JSON.stringify(job));
        assert.equal(v.indeterminate, false); // generation without figures: no bar at all
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
    assert.equal(first.view.percent, null); // generated ≠ downloaded
    assert.equal(first.view.indeterminate, false);
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
    const rendered = view.replace(/<\?php[\s\S]*?\?>/g, '');
    assert.ok(rendered.includes('data-export="csv"'));
    assert.ok(!rendered.includes('data-export="xlsx"'));

    // export-progress.js → export-widget.js → page scripts (dashboard.js reads window.bscdExportWidget at start).
    const layout = fs.readFileSync(path.join(__dirname, '../../app/Views/layout/main.php'), 'utf8');
    const progressAt = layout.indexOf('assets/js/export-progress.js');
    const widgetAt = layout.indexOf('assets/js/export-widget.js');
    assert.ok(layout.indexOf('assets/js/app.js') < progressAt && progressAt < widgetAt && widgetAt < layout.indexOf("renderSection('scripts')"));
    assert.ok(layout.includes('id="bscdExportWidget"') && layout.includes("site_url('exports')"));
    assert.ok(!rendered.includes('assets/js/export-progress.js'), 'loaded once, by the layout');

    for (const file of ['dashboard.js', 'export-widget.js']) {
        const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js', file), 'utf8');
        assert.ok(!js.includes('>Télécharger<'), 'no manual download button in ' + file);
    }
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

test('done → "Durée de génération" = finished_at - started_at (not the download), fixed for good', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 400 } }, at(0));
    c.sync({ status: 'done', timing: { elapsedSeconds: 440, final: true } }, at(3));
    assert.equal(c.isFinal(), true);
    assert.equal(c.display(at(3)).text, 'Durée de génération : 00:07:20');
    assert.equal(c.display(at(600)).text, 'Durée de génération : 00:07:20');
    c.sync({ status: 'done', timing: { elapsedSeconds: 999, final: true } }, at(700)); // duplicate answer
    assert.equal(c.display(at(700)).text, 'Durée de génération : 00:07:20');
});

test('done without a server total → fallback: frozen at the moment "done" is received', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 100 } }, at(0));
    c.sync({ status: 'done' }, at(5));
    assert.equal(c.display(at(5)).text, 'Durée de génération : 00:01:45');
    assert.equal(c.display(at(60)).text, 'Durée de génération : 00:01:45');
});

test('error → stops with the time actually spent', () => {
    const c = createDurationClock();
    c.sync({ status: 'running', timing: { elapsedSeconds: 50 } }, at(0));
    c.sync({ status: 'error', timing: { elapsedSeconds: 52, final: true } }, at(3));
    assert.equal(c.isFinal(), true);
    assert.equal(c.display(at(90)).text, 'Durée totale : 00:00:52');
});

test('widget: line stats, then file info (format, size, duration), no invented download duration', () => {
    const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js/export-widget.js'), 'utf8');
    assert.match(js, /data-xw-counts><\/div>' \+\s*'<div class="bscd-xw__meta" data-xw-exported><\/div>/);
    assert.match(js, /data-xw-format><\/div>' \+\s*'<div class="bscd-xw__meta" data-xw-size><\/div>' \+\s*'<div class="bscd-xw__meta" data-xw-duration><\/div>/);
    assert.doesNotMatch(js, /[Dd]urée du téléchargement/);
});

test('progress bar: filled width = API percent, no minimum width (0 % = empty bar)', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/assets/css/custom.css'), 'utf8');
    const rule = css.match(/\.bscd-xw__track \.progress-bar\s*\{([^}]*)\}/);
    assert.ok(rule, 'progress-bar rule present');
    assert.doesNotMatch(rule[1], /min-width|padding/);
    const js = fs.readFileSync(path.join(__dirname, '../../public/assets/js/export-widget.js'), 'utf8');
    assert.match(js, /bar\.style\.width = m\.percent \+ '%'/);
    // The % label lives beside the bar, never inside it.
    assert.match(js, /\[data-xw-pct\]'\)\.textContent = m\.percent \+ ' %'/);
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

// ── generation vs download: state machine, no fake 100 % ─────────────
const { createExportPhase, formatSize, PHASE } = require('../../public/assets/js/export-progress.js');

test('phase machine: GENERATING → READY → DOWNLOADING, server and download progress kept apart', () => {
    const m = createExportPhase();
    assert.equal(m.phase(), PHASE.GENERATING);
    assert.equal(m.server(progressView(running(61, 2025000))), true);
    assert.equal(m.serverProgress(), 61);
    assert.equal(m.downloadProgress(), null);

    assert.equal(m.ready(), true);
    assert.equal(m.serverProgress(), 100); // generation really complete
    assert.equal(m.downloadProgress(), null); // ...download not even started

    assert.equal(m.downloading(false), true);
    assert.equal(m.phase(), PHASE.DOWNLOADING);
    assert.equal(m.downloadProgress(), null); // browser-managed: unknown, indeterminate
});

test('browser-managed download (window.location): never COMPLETED, never 100 %', () => {
    const m = createExportPhase();
    m.ready();
    m.downloading(false);
    assert.equal(m.received(7.5 * 1048576, 606 * 1048576), false); // the page has no byte count
    assert.equal(m.completed(), false);
    assert.equal(m.phase(), PHASE.DOWNLOADING);
    assert.equal(m.downloadProgress(), null);
});

test('measured download: percent = bytes received / Content-Length, capped at 99 until really finished', () => {
    const MB = 1048576;
    const m = createExportPhase();
    m.ready();
    m.downloading(true);
    assert.equal(m.downloadProgress(), 0);
    m.received(7.5 * MB, 606 * MB); assert.equal(m.downloadProgress(), 1);
    m.received(300 * MB, 606 * MB); assert.equal(m.downloadProgress(), 49);
    m.received(600 * MB, 606 * MB); assert.equal(m.downloadProgress(), 99);
    m.received(606 * MB, 606 * MB); assert.equal(m.downloadProgress(), 99); // all bytes in, stream not closed yet
    assert.equal(m.completed(), true);
    assert.equal(m.phase(), PHASE.COMPLETED);
    assert.equal(m.downloadProgress(), 100);
});

test('phase machine: illegal moves are refused', () => {
    const m = createExportPhase();
    assert.equal(m.downloading(false), false, 'no download before the file is ready');
    assert.equal(m.completed(), false);
    m.ready();
    assert.equal(m.server(progressView(running(80, 2600000))), false, 'late "running" answer after done is dropped');
    assert.equal(m.cancelled(), false, 'a generated file can no longer be cancelled server-side');
    m.downloading(false);
    assert.equal(m.ready(), false);
    assert.equal(m.failed(), true); // DOWNLOADING → ERROR allowed

    const c = createExportPhase();
    assert.equal(c.cancelled(), true);
    assert.equal(c.ready(), false, 'cancelled is terminal');
    assert.equal(c.failed(), false);

    const e = createExportPhase();
    assert.equal(e.failed(), true);
    assert.equal(e.ready(), false, 'error is terminal');
});

test('formatSize → French units with decimal comma', () => {
    assert.equal(formatSize(7.5 * 1048576), '7,5 Mo');
    assert.equal(formatSize(606 * 1048576), '606 Mo');
    assert.equal(formatSize(635437056), '606 Mo');
    assert.equal(formatSize(1.25 * 1073741824), '1,3 Go');
    assert.equal(formatSize(512), '512 o');
    assert.equal(formatSize(-1), '');
    assert.equal(formatSize('x'), '');
});

test('dashboard + widget: no fake 100 %, no blob, no timer-driven end of a download', () => {
    const dash = fs.readFileSync(path.join(__dirname, '../../public/assets/js/dashboard.js'), 'utf8');
    const exportPart = dash.slice(dash.indexOf('// ── export'), dash.indexOf('// ── wiring'));
    const widget = fs.readFileSync(path.join(__dirname, '../../public/assets/js/export-widget.js'), 'utf8');
    for (const [name, js] of [['dashboard.js export section', exportPart], ['export-widget.js', widget]]) {
        assert.doesNotMatch(js, /percent\s*=\s*100|percent:\s*100|'100 ?%'/, name + ': nothing sets 100 % by hand');
        assert.doesNotMatch(js, /\.blob\(\)/, name + ': never loads the file in memory');
        assert.doesNotMatch(js, /\.completed\(\)/, name + ': COMPLETED is unreachable with a browser-managed download');
    }
    assert.doesNotMatch(exportPart, /window\.location/, 'the dashboard hands the download to the widget');
    assert.equal((widget.match(/win\.location = url/g) || []).length, 1, 'one download primitive');
    assert.doesNotMatch(widget, /doneCloseMs/, 'a generated file never closes the window on a timer');
});

test('css: indeterminate bar is a sliding segment, never a full-width bar', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/assets/css/custom.css'), 'utf8');
    const rule = css.match(/\.bscd-xw__progress\.is-indeterminate \.progress-bar\s*\{([^}]*)\}/);
    assert.ok(rule, 'indeterminate rule present');
    assert.match(rule[1], /width:\s*30%/);
    assert.match(rule[1], /animation:/);
    assert.match(css, /prefers-reduced-motion/);
    assert.doesNotMatch(css, /\.bscd-export-progress/, 'old modal progress styles removed');
});