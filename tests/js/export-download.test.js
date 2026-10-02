// Run: node --test tests/js/export-download.test.js
// Measured download of a generated export: bytes received / Content-Length
// drive the existing bar of the floating window; 100 % and "Téléchargement
// terminé" only once the stream has ended with every announced byte.
//   readDownload            (export-progress.js)  reads + counts the stream
//   createStreamDownloader  (export-widget.js)    fetch → readDownload → save
//   createExportController  (export-widget.js)    generation → download states
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { readDownload, createExportPhase } = require('../../public/assets/js/export-progress.js');
const { createExportController, createStreamDownloader, dispositionFileName, ACTIVE_KEY, DOWNLOADED_KEY } = require('../../public/assets/js/export-widget.js');

const MB = 1048576;
const FILE = 631988813; // the real 603 Mo CSV of the manual test (Content-Length)

/** A fetch Response whose body emits `chunks` (sizes in bytes), optionally failing after `failAfter` chunks. */
function streamResponse(chunks, { contentLength, failAfter, status = 200, type = 'application/octet-stream', disposition } = {}) {
    let i = 0;
    const body = new ReadableStream({
        pull(controller) {
            if (failAfter !== undefined && i === failAfter) { controller.error(new TypeError('network error')); return; }
            if (i === chunks.length) { controller.close(); return; }
            controller.enqueue(new Uint8Array(chunks[i++]));
        },
    });
    const headers = { 'Content-Type': type };
    if (contentLength !== undefined) headers['Content-Length'] = String(contentLength);
    if (disposition) headers['Content-Disposition'] = disposition;
    return new Response(body, { status, headers });
}

// ── readDownload: the measure itself ─────────────────────────────────

test('readDownload: progress = bytes received / Content-Length, from 0 to the real end', async () => {
    const seen = [];
    const machine = createExportPhase();
    machine.ready();
    machine.downloading(true);
    const result = await readDownload(streamResponse(Array(10).fill(100), { contentLength: 1000 }), (loaded, total) => {
        seen.push([loaded, total]);
        machine.received(loaded, total);
        assert.ok(machine.downloadProgress() < 100, '100 % is never shown while the stream is still open');
    });

    assert.deepEqual(seen[0], [0, 1000], 'starts at 0 %');
    assert.deepEqual(seen.map(([l]) => l), [0, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000]);
    assert.ok(seen.every(([, t]) => t === 1000), 'Content-Length is the total');
    assert.equal(result.loaded, 1000);
    assert.equal(result.total, 1000);
    assert.equal(result.blob.size, 1000);
    assert.equal(machine.downloadProgress(), 99, 'every byte received but not yet confirmed: 99');
    assert.equal(machine.completed(), true);
    assert.equal(machine.downloadProgress(), 100, 'exactly 100 only after the stream has ended');
});

test('readDownload: 10 %, 50 %, 90 % are the real ratios of the bytes received', async () => {
    const percents = [];
    const machine = createExportPhase();
    machine.ready();
    machine.downloading(true);
    await readDownload(streamResponse([100, 400, 400, 100], { contentLength: 1000 }), (loaded, total) => {
        machine.received(loaded, total);
        percents.push(machine.downloadProgress());
    });
    assert.deepEqual(percents, [0, 10, 50, 90, 99]);
});

test('readDownload: no Content-Length → bytes counted, no total (no percentage), still ends normally', async () => {
    const seen = [];
    const result = await readDownload(streamResponse([300, 300, 50]), (loaded, total) => seen.push([loaded, total]));
    assert.ok(seen.every(([, t]) => t === null));
    assert.equal(result.loaded, 650);
    assert.equal(result.total, null);
    assert.equal(result.blob.size, 650);
});

test('readDownload: stream ends before Content-Length → "incomplete", never a finished download', async () => {
    await assert.rejects(readDownload(streamResponse([400, 400], { contentLength: 1000 }), () => {}),
        (e) => e.kind === 'incomplete' && e.loaded === 800 && e.total === 1000);
});

test('readDownload: network error in the middle of the stream → "network" with what had arrived', async () => {
    await assert.rejects(readDownload(streamResponse([250, 250, 250, 250], { contentLength: 1000, failAfter: 2 }), () => {}),
        (e) => e.kind === 'network' && e.loaded === 500 && e.total === 1000);
});

test('readDownload: large file — chunks packed into bounded Blob segments, never the whole file pending in the page', async () => {
    const parts = [];
    let maxPending = 0;
    class CountingBlob extends Blob {
        constructor(items, opts) {
            super(items, opts);
            if (!opts) { // a segment (the final Blob gets a type)
                parts.push(this.size);
                maxPending = Math.max(maxPending, this.size);
            }
        }
    }
    const result = await readDownload(streamResponse(Array(64).fill(MB), { contentLength: 64 * MB }), () => {},
        { segmentBytes: 8 * MB, Blob: CountingBlob });
    assert.equal(result.loaded, 64 * MB);
    assert.equal(parts.length, 8, '8 segments of 8 Mo');
    assert.ok(maxPending <= 8 * MB, 'at most one segment of raw chunks held at a time');
    assert.equal(result.blob.size, 64 * MB);
});

// ── createStreamDownloader: fetch → measure → automatic save ─────────

function downloaderHarness(response) {
    const h = { saved: [], active: 0, events: [] };
    h.download = createStreamDownloader({
        fetch: (url, init) => { h.request = { url, init }; return typeof response === 'function' ? response() : Promise.resolve(response); },
        save: (blob, name) => h.saved.push({ size: blob.size, name }),
        frame: (fn) => fn(),
        active: (d) => { h.active += d; },
    });
    h.run = (url, name) => new Promise((resolve) => {
        h.download(url, name, {
            progress: (l, t) => h.events.push(['progress', l, t]),
            done: (r) => { h.events.push(['done', r.loaded, r.total]); resolve(); },
            error: (e) => { h.events.push(['error', e]); resolve(); },
        });
    });
    return h;
}

test('downloader: same-origin GET of /exports/{id}/download, measured, then saved automatically once', async () => {
    const h = downloaderHarness(streamResponse([500, 500], { contentLength: 1000,
        disposition: 'attachment; filename="customer_list_x.csv"; filename*=UTF-8\'\'customer_list_x.csv' }));
    await h.run('/exports/63/download', null);
    assert.equal(h.request.url, '/exports/63/download');
    assert.equal(h.request.init.credentials, 'same-origin');
    assert.deepEqual(h.events.at(-1), ['done', 1000, 1000]);
    assert.deepEqual(h.saved, [{ size: 1000, name: 'customer_list_x.csv' }], 'saved by the browser, no click asked');
    await new Promise((r) => setImmediate(r));
    assert.equal(h.active, 0, 'leave-page guard released');
});

test('downloader: HTTP error (410 expired) → error with the server message, nothing saved', async () => {
    const h = downloaderHarness(new Response(JSON.stringify({ error: 'expired', message: "Le fichier de cet export n'est plus disponible." }),
        { status: 410, headers: { 'Content-Type': 'application/json' } }));
    await h.run('/exports/63/download', 'x.csv');
    const [kind, err] = h.events.at(-1);
    assert.equal(kind, 'error');
    assert.equal(err.kind, 'http');
    assert.equal(err.status, 410);
    assert.match(err.message, /plus disponible/);
    assert.deepEqual(h.saved, []);
    assert.equal(h.active, 0);
});

test('downloader: answered by an HTML page (session expired → /login) → error, never saved as the CSV', async () => {
    const h = downloaderHarness(new Response('<html>login</html>', { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }));
    await h.run('/exports/63/download', 'x.csv');
    assert.equal(h.events.at(-1)[1].status, 401);
    assert.deepEqual(h.saved, []);
});

test('downloader: network error during the download → error, nothing saved', async () => {
    const h = downloaderHarness(streamResponse([300, 300, 300], { contentLength: 900, failAfter: 1 }));
    await h.run('/exports/63/download', 'x.csv');
    assert.equal(h.events.at(-1)[1].kind, 'network');
    assert.deepEqual(h.saved, []);
    const h2 = downloaderHarness(() => Promise.reject(new TypeError('Failed to fetch')));
    await h2.run('/exports/63/download', 'x.csv');
    assert.equal(h2.events.at(-1)[1].kind, 'network');
});

test('dispositionFileName: RFC 5987 filename* first, then filename', () => {
    assert.equal(dispositionFileName('attachment; filename="a.csv"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.csv'), 'résumé.csv');
    assert.equal(dispositionFileName('attachment; filename="customer_list_1.csv"'), 'customer_list_1.csv');
    assert.equal(dispositionFileName(null), null);
});

// ── controller: generation → download, in the existing window ────────

function memoryStore() {
    const data = {};
    return { data, get: (k) => (k in data ? JSON.parse(data[k]) : null), set: (k, v) => { data[k] = JSON.stringify(v); }, remove: (k) => { delete data[k]; } };
}

/** Same harness as export-widget.test.js, plus a scripted measured downloader. */
function harness() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();
    const answers = {};
    const h = {
        renders: [], requests: [], fallback: [], downloads: [],
        session: memoryStore(), local: memoryStore(),
        answer(url, ...list) { answers[url] = list; },
        last() { return h.renders[h.renders.length - 1]; },
        dl(i = h.downloads.length - 1) { return h.downloads[i]; },
        pendingTimers() { return timers.size; },
        async advance(ms) {
            const until = now + ms;
            for (;;) {
                const due = [...timers.entries()].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) break;
                timers.delete(due[0]);
                now = due[1].at;
                due[1].fn();
                await new Promise((r) => setImmediate(r));
            }
            now = until;
            await new Promise((r) => setImmediate(r));
        },
    };
    h.controller = createExportController({
        endpoint: '/exports',
        fetchJson(url, opts) {
            h.requests.push({ url, opts });
            const list = answers[url] || [{ error: 'http', status: 404 }];
            return Promise.resolve(list.length > 1 ? list.shift() : list[0]);
        },
        session: h.session, local: h.local,
        setTimer(fn, ms) { const id = nextId++; timers.set(id, { fn, at: now + ms }); return id; },
        clearTimer(id) { timers.delete(id); },
        now: () => now,
        download(url) { h.fallback.push(url); },
        downloader(url, fileName, on) { h.downloads.push({ url, fileName, on }); },
        render(m) { h.renders.push(m); },
        isHidden: () => false,
    });
    return h;
}

const TOTAL = 3302841;
const running = (processed) => ({
    status: 'running', format: 'csv', rowCount: 2429653,
    progress: { percent: Math.floor((processed / TOTAL) * 100), processed, total: TOTAL, exported: processed },
    timing: { elapsedSeconds: 30 },
});
const done = (id = 63) => ({
    status: 'done', format: 'csv', rowCount: 2429653, downloadUrl: '/exports/' + id + '/download', fileSize: FILE,
    fileName: 'customer_list_' + id + '.csv',
    progress: { percent: 100, processed: TOTAL, total: TOTAL, exported: 2429653 }, timing: { elapsedSeconds: 372, final: true },
});

async function toDownload(h, id = 63) {
    h.answer('/exports/' + id, running(1000000), done(id));
    h.controller.begin({ format: 'csv' });
    h.controller.attach(id, { format: 'csv' });
    await h.advance(0);
    await h.advance(3000);
}

test('file ready → the download starts at once (automatic), at 0 %, polling stopped, generation figures kept', async () => {
    const h = harness();
    await toDownload(h);
    assert.equal(h.downloads.length, 1, 'one measured download, no click');
    assert.equal(h.dl().url, '/exports/63/download');
    assert.equal(h.dl().fileName, 'customer_list_63.csv');
    assert.deepEqual(h.fallback, [], 'window.location not used');

    const m = h.last();
    assert.equal(m.phase, 'downloading');
    assert.equal(m.state, '✓ Fichier généré');
    assert.equal(m.status, 'Téléchargement du fichier', 'the bar is labelled: download, not generation');
    assert.equal(m.percent, 0);
    assert.equal(m.download.title, 'Téléchargement en cours');
    assert.match(m.counts, /lignes parcourues$/);
    assert.equal(m.size, 'Taille : 603 Mo');
    assert.equal(m.duration, 'Durée de génération : 00:06:12');

    const polls = h.requests.length;
    await h.advance(60000);
    assert.equal(h.requests.length, polls, 'no polling once the file is ready');
    assert.equal(h.pendingTimers(), 0);
});

test('603 Mo download: 0 → 10 → 25 → 50 → 75 → 90 → 99 % with Mo received, 100 % + "terminé" only at the end of the stream', async () => {
    const h = harness();
    await toDownload(h);
    const on = h.dl().on;
    const shown = [h.last()]; // 0 %, before any byte
    for (const ratio of [0.1, 0.25, 0.5, 0.75, 0.9, 0.995, 1]) {
        on.progress(Math.ceil(FILE * ratio), FILE);
        shown.push(h.last());
    }
    assert.deepEqual(shown.map((m) => m.percent), [0, 10, 25, 50, 75, 90, 99, 99], 'every byte in but stream not ended: still 99');
    assert.match(shown[3].download.text, /^301 Mo \/ 603 Mo reçus\. /);
    assert.ok(shown.every((m) => m.state === '✓ Fichier généré' && m.download.title === 'Téléchargement en cours'));
    for (const m of h.renders) {
        assert.notEqual(m && m.percent, 100);
        assert.doesNotMatch(JSON.stringify(m), /terminé/i);
    }

    on.done({ loaded: FILE, total: FILE });
    const end = h.last();
    assert.equal(end.percent, 100);
    assert.equal(end.state, '✓ Téléchargement terminé');
    assert.equal(end.download.title, 'Le fichier a été entièrement téléchargé.');
    assert.match(end.download.text, /^603 Mo \/ 603 Mo reçus — /);
    // Generation information still there.
    assert.match(end.counts, /lignes parcourues$/);
    assert.match(end.exported, /lignes exportées$/);
    assert.equal(end.format, 'Format : CSV');
    assert.equal(end.size, 'Taille : 603 Mo');
    assert.equal(end.duration, 'Durée de génération : 00:06:12');
    assert.equal(end.canClose, true);
    assert.equal(end.closeLabel, 'Fermer la fenêtre');
});

test('no Content-Length → bytes received, indeterminate bar, no percentage, then 100 % at the end of the stream', async () => {
    const h = harness();
    await toDownload(h);
    h.dl().on.progress(5 * MB, null);
    assert.equal(h.last().percent, null);
    assert.equal(h.last().indeterminate, true);
    assert.match(h.last().download.text, /^5,0 Mo reçus\. /);
    h.dl().on.done({ loaded: 7 * MB, total: null });
    assert.equal(h.last().percent, 100);
    assert.equal(h.last().state, '✓ Téléchargement terminé');
});

test('download error (HTTP / network / incomplete) → "Échec du téléchargement", never 100 %, generation figures kept', async () => {
    for (const [err, text] of [
        [{ kind: 'http', status: 410, message: "Le fichier de cet export n'est plus disponible." }, /plus disponible/],
        [{ kind: 'network' }, /interrompu \(erreur réseau\)/],
        [{ kind: 'incomplete', loaded: 300 * MB, total: FILE }, /incomplet : 300 Mo reçus sur 603 Mo/],
    ]) {
        const h = harness();
        await toDownload(h);
        h.dl().on.progress(300 * MB, FILE);
        h.dl().on.error(err);
        const m = h.last();
        assert.equal(m.phase, 'error');
        assert.equal(m.state, 'Échec du téléchargement');
        assert.match(m.message, text);
        assert.equal(m.percent, null);
        assert.match(m.counts, /lignes parcourues$/);
        assert.equal(m.canClose, true);
        assert.deepEqual(h.local.get(DOWNLOADED_KEY), null, 'a failed download is not "already downloaded"');
    }
});

test('one download per job: marked downloaded only once complete; a later "done" (reload) never downloads again', async () => {
    const h = harness();
    await toDownload(h);
    assert.equal(h.local.get(DOWNLOADED_KEY), null, 'not yet: the bytes are still arriving');
    assert.equal(h.session.get(ACTIVE_KEY).jobId, 63, 'a reload during the download restarts it');
    h.dl().on.done({ loaded: FILE, total: FILE });
    assert.deepEqual(h.local.get(DOWNLOADED_KEY), [63]);
    assert.equal(h.session.get(ACTIVE_KEY), null);

    // Same job seen done again (another page of the tab, a duplicated tab).
    const h2 = harness();
    h2.local.set(DOWNLOADED_KEY, [63]);
    await toDownload(h2);
    assert.equal(h2.downloads.length, 0);
    assert.deepEqual(h2.fallback, []);
});

test('"Fermer la fenêtre" during the download: window hidden, download goes on and completes without error', async () => {
    const h = harness();
    await toDownload(h);
    h.dl().on.progress(100 * MB, FILE);
    h.controller.close();
    assert.equal(h.last(), null);
    const renders = h.renders.length;
    assert.doesNotThrow(() => { h.dl().on.progress(500 * MB, FILE); h.dl().on.done({ loaded: FILE, total: FILE }); });
    assert.equal(h.renders.length, renders, 'nothing redrawn once closed');
    assert.deepEqual(h.local.get(DOWNLOADED_KEY), [63], 'still recorded as downloaded');
});

test('closing after "Téléchargement terminé" hides the window, no error', async () => {
    const h = harness();
    await toDownload(h);
    h.dl().on.done({ loaded: FILE, total: FILE });
    assert.equal(h.controller.close(), true);
    assert.equal(h.last(), null);
});

test('never two downloads at once: no new export while a download receives bytes, even with its window closed', async () => {
    const h = harness();
    await toDownload(h, 63);
    h.dl(0).on.progress(100 * MB, FILE);

    assert.equal(h.controller.isBusy(), true);
    assert.equal(h.controller.begin({ format: 'csv' }), false, 'second export refused during the download');

    h.controller.close();
    assert.equal(h.last(), null);
    assert.equal(h.controller.begin({ format: 'csv' }), false, 'still refused once the window is closed…');
    assert.equal(h.last().jobId, 63, '…and the running download is shown again');
    assert.equal(h.last().percent, 16);
    assert.equal(h.downloads.length, 1);
});

test('two exports in a row: the second starts once the first download has ended, one download each', async () => {
    const h = harness();
    await toDownload(h, 63);
    h.dl(0).on.done({ loaded: FILE, total: FILE });
    assert.equal(h.controller.isBusy(), false);

    await toDownload(h, 64);
    assert.equal(h.downloads.length, 2);
    assert.equal(h.dl(1).url, '/exports/64/download');
    assert.equal(h.last().jobId, 64);
    assert.equal(h.last().percent, 0);
    assert.equal(h.last().state, '✓ Fichier généré');
    h.dl(1).on.error({ kind: 'network' });
    assert.equal(h.controller.isBusy(), false, 'a failed download frees the slot too');
    assert.deepEqual(h.local.get(DOWNLOADED_KEY), [63]);
});

test('cancellation exists only during the generation: no "Annuler" once the download has started', async () => {
    const h = harness();
    await toDownload(h);
    assert.equal(h.last().canCancel, false);
    h.controller.cancel();
    assert.equal(h.last().confirming, false);
    assert.equal(h.requests.filter((r) => r.url.endsWith('/cancel')).length, 0);
});

test('sync export (file generated by the POST) uses the same measured download', () => {
    const h = harness();
    h.controller.begin({ format: 'csv', count: 1200 });
    h.controller.syncDone({ format: 'csv', count: 1200, rows: 1200, downloadUrl: '/dashboard/export/download?f=x', fileSize: 2 * MB, generationSeconds: 3 });
    assert.equal(h.downloads.length, 1);
    assert.equal(h.dl().url, '/dashboard/export/download?f=x');
    h.dl().on.progress(MB, 2 * MB);
    assert.equal(h.last().percent, 50);
    h.dl().on.done({ loaded: 2 * MB, total: 2 * MB });
    assert.equal(h.last().percent, 100);
});

test('no streaming in this browser (no downloader) → previous behaviour: window.location, "Téléchargement lancé", no bar', async () => {
    const h2 = harness(); // only its stores / records: the controller below has no downloader
    const deps = {
        endpoint: '/exports',
        fetchJson: (url) => Promise.resolve(url === '/exports/63' ? done(63) : { error: 'http', status: 404 }),
        session: h2.session, local: h2.local,
        setTimer: () => 1, clearTimer() {}, now: () => 0,
        download: (url) => h2.fallback.push(url),
        render: (m) => h2.renders.push(m), isHidden: () => false,
    };
    const c = createExportController(deps);
    c.begin({ format: 'csv' });
    c.attach(63, {});
    await new Promise((r) => setImmediate(r));
    assert.deepEqual(h2.fallback, ['/exports/63/download']);
    assert.equal(h2.last().download.title, 'Téléchargement lancé');
    assert.equal(h2.last().percent, null);
});
