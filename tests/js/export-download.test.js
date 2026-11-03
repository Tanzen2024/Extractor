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
const { createExportController, createStreamDownloader, dispositionFileName, saveBlob, ACTIVE_KEY, DOWNLOADED_KEY } = require('../../public/assets/js/export-widget.js');

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

test('TEST 6 (stream level): abort() cancels the fetch through its AbortController — reading stops, nothing saved', async () => {
    let pulls = 0;
    let signal = null;
    const h = { saved: [], events: [], active: 0 };
    const download = createStreamDownloader({
        fetch: (url, init) => {
            signal = init.signal;
            const body = new ReadableStream({
                start(c) { init.signal.addEventListener('abort', () => c.error(new DOMException('Aborted', 'AbortError'))); },
                pull(c) { pulls++; return new Promise((r) => setTimeout(r, 5)).then(() => { try { c.enqueue(new Uint8Array(MB)); } catch { /* errored */ } }); },
            });
            return Promise.resolve(new Response(body, { headers: { 'Content-Length': String(100 * MB) } }));
        },
        save: (blob, name) => h.saved.push(name),
        frame: (fn) => fn(),
        active: (d) => { h.active += d; },
        AbortController,
    });
    const ended = new Promise((resolve) => {
        const handle = download('/exports/64/download', 'x.csv', {
            progress: (l) => { h.events.push(l); if (l >= 3 * MB) handle.abort(); },
            done: () => { h.events.push('done'); resolve(); },
            error: (e) => { h.events.push(e.kind); resolve(); },
        });
    });
    await ended;
    assert.ok(signal instanceof AbortSignal && signal.aborted, 'fetch got the signal and it was aborted');
    assert.equal(h.events.at(-1), 'aborted');
    assert.ok(!h.events.includes('done'));
    assert.deepEqual(h.saved, []);
    const pullsAtEnd = pulls;
    await new Promise((r) => setTimeout(r, 30));
    assert.equal(pulls, pullsAtEnd, 'stream no longer read');
    assert.ok(pulls < 10, 'stopped long before the 100 Mo');
    assert.equal(h.active, 0, 'leave-page guard released');
});

test('abort() after the last byte but before the save → still nothing saved', async () => {
    let handle;
    const saved = [];
    const events = [];
    handle = createStreamDownloader({
        fetch: () => Promise.resolve(streamResponse([10, 10], { contentLength: 20 })),
        save: (b, n) => saved.push(n),
        frame: (fn) => fn(),
        active() {},
    })('/exports/1/download', 'x.csv', {
        progress: (l) => { if (l === 20) handle.abort(); },
        done: () => events.push('done'),
        error: (e) => events.push(e.kind),
    });
    await new Promise((r) => setTimeout(r, 20));
    assert.deepEqual(events, ['aborted']);
    assert.deepEqual(saved, []);
});

test('TEST 7-8: Content-Length valid / absent / invalid → total or null, never a crash', async () => {
    for (const [cl, total] of [[1000, 1000], [undefined, null], ['abc', null], ['0', null], ['-5', null]]) {
        const seen = [];
        const r = await readDownload(streamResponse([600, 400], { contentLength: cl }), (l, t) => seen.push(t));
        assert.ok(seen.every((t) => t === total), 'Content-Length ' + cl);
        assert.equal(r.loaded, 1000);
    }
});

test('TEST 10: saveBlob → object URL of the Blob, <a download> clicked once, URL revoked later (not before the save)', () => {
    const calls = [];
    const timers = [];
    const a = { style: {}, click() { calls.push(['click', a.href, a.download]); }, remove() { calls.push(['remove']); } };
    const win = {
        document: { createElement: (tag) => { calls.push(['create', tag]); return a; }, body: { appendChild: (n) => calls.push(['append', n === a]) } },
        URL: {
            createObjectURL: (b) => { calls.push(['url', b.size, b.type]); return 'blob:http://10.250.90.169:8083/uuid'; },
            revokeObjectURL: (u) => calls.push(['revoke', u]),
        },
        setTimeout: (fn, ms) => timers.push({ fn, ms }),
    };
    saveBlob(win, new Blob(['a;b\n1;2\n'], { type: 'application/octet-stream' }), 'customer_list_64.csv');
    assert.deepEqual(calls, [
        ['url', 8, 'application/octet-stream'], ['create', 'a'], ['append', true],
        ['click', 'blob:http://10.250.90.169:8083/uuid', 'customer_list_64.csv'], ['remove'],
    ]);
    assert.equal(timers.length, 1);
    assert.ok(timers[0].ms >= 60000, 'revoked only after a delay');
    timers[0].fn();
    assert.deepEqual(calls.at(-1), ['revoke', 'blob:http://10.250.90.169:8083/uuid']);
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
        downloader(url, fileName, on) {
            const d = { url, fileName, on, aborted: 0 };
            h.downloads.push(d);
            return { abort() { d.aborted++; } };
        },
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
    assert.equal(m.state, '↓ Téléchargement en cours', 'the bar is labelled: download, not generation');
    assert.equal(m.status, '✓ Fichier généré');
    assert.equal(m.percent, 0);
    assert.equal(m.download.title, '0 o / 603 Mo reçus');
    assert.match(m.counts, /lignes parcourues$/);
    assert.equal(m.size, 'Taille : 603 Mo');
    assert.equal(m.duration, 'Durée de génération : 00:06:12');

    const polls = h.requests.length;
    await h.advance(60000);
    assert.equal(h.requests.length, polls, 'no polling once the file is ready');
    assert.equal(h.pendingTimers(), 1, 'only the download-duration ticker');
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
    assert.equal(shown[3].download.title, '301 Mo / 603 Mo reçus');
    assert.ok(shown.every((m) => m.state === '↓ Téléchargement en cours' && m.canCancel && !m.canClose));
    for (const m of h.renders) {
        assert.notEqual(m && m.percent, 100);
        assert.doesNotMatch(JSON.stringify(m), /terminé/i);
    }

    on.done({ loaded: FILE, total: FILE });
    const end = h.last();
    assert.equal(end.percent, 100);
    assert.equal(end.state, '✓ Téléchargement terminé');
    assert.equal(end.download.title, '603 Mo / 603 Mo reçus');
    assert.match(end.download.text, /entièrement téléchargé/);
    assert.equal(end.canCancel, false);
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
    assert.equal(h.last().download.title, '5,0 Mo reçus');
    assert.equal(h.last().canCancel, true);
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

test('job done ≠ download done: no "Fermer la fenêtre" and close() refused while the stream is read', async () => {
    const h = harness();
    await toDownload(h);
    h.dl().on.progress(100 * MB, FILE);
    assert.equal(h.last().canClose, false);
    assert.equal(h.controller.close(), false);
    assert.equal(h.last().jobId, 63, 'window still shown');
    h.dl().on.done({ loaded: FILE, total: FILE });
    assert.equal(h.last().canClose, true);
    assert.deepEqual(h.local.get(DOWNLOADED_KEY), [63]);
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

    assert.equal(h.controller.close(), false, 'the running download keeps its window');
    assert.equal(h.last().jobId, 63);
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
    assert.equal(h.last().state, '↓ Téléchargement en cours');
    h.dl(1).on.error({ kind: 'network' });
    assert.equal(h.controller.isBusy(), false, 'a failed download frees the slot too');
    assert.deepEqual(h.local.get(DOWNLOADED_KEY), [63]);
});

// ── download: "Annuler", its own clock, "Fermer" only at the end ────

test('TEST 1-2: "Annuler" from the first byte to the last, never "Fermer la fenêtre" meanwhile', async () => {
    const h = harness();
    await toDownload(h);
    assert.equal(h.last().canCancel, true, 'download started: Annuler');
    assert.equal(h.last().canClose, false);
    for (const loaded of [1, 90 * MB, FILE - 1, FILE]) {
        h.dl().on.progress(loaded, FILE);
        assert.equal(h.last().canCancel, true, loaded + ' bytes: still Annuler');
        assert.equal(h.last().canClose, false);
    }
});

test('TEST 3: "Durée de téléchargement" shown from the start and ticking every second, chunks or not', async () => {
    const h = harness();
    await toDownload(h); // job done at t=3000: the download clock starts here
    assert.equal(h.last().downloadDuration, 'Durée de téléchargement : 00:00:00');
    await h.advance(5000); // no chunk at all: the ticker still redraws
    assert.equal(h.last().downloadDuration, 'Durée de téléchargement : 00:00:05');
    h.dl().on.progress(90 * MB, FILE);
    await h.advance(7000);
    assert.equal(h.last().downloadDuration, 'Durée de téléchargement : 00:00:12');
});

test('TEST 4: done === true → 100 %, "terminé", Fermer visible, Annuler hidden, both clocks frozen', async () => {
    const h = harness();
    await toDownload(h);
    await h.advance(18000);
    h.dl().on.done({ loaded: FILE, total: FILE });
    const end = h.last();
    assert.equal(end.state, '✓ Téléchargement terminé');
    assert.equal(end.percent, 100);
    assert.equal(end.canClose, true);
    assert.equal(end.canCancel, false);
    assert.equal(end.downloadDuration, 'Durée de téléchargement : 00:00:18');
    await h.advance(60000);
    assert.equal(h.pendingTimers(), 0, 'ticker stopped');
    assert.equal(h.controller.model().downloadDuration, 'Durée de téléchargement : 00:00:18');
    assert.equal(h.controller.model().duration, 'Durée de génération : 00:06:12');
});

test('TEST 5: "Durée de génération" stays the server figure, independent of the download time', async () => {
    const h = harness();
    await toDownload(h);
    await h.advance(42000);
    const m = h.last();
    assert.equal(m.duration, 'Durée de génération : 00:06:12', 'not a total, not moving');
    assert.equal(m.downloadDuration, 'Durée de téléchargement : 00:00:42');
});

test('TEST 6: Annuler → confirmation → abort(): stream stopped, no "terminé", nothing saved/recorded, Fermer offered', async () => {
    const h = harness();
    await toDownload(h);
    h.dl().on.progress(90 * MB, 149 * MB);
    await h.advance(4000);
    h.controller.cancel();
    assert.equal(h.last().confirming, true);
    assert.equal(h.last().confirmText, 'Voulez-vous vraiment annuler ce téléchargement ?');
    assert.equal(h.dl().aborted, 0, 'nothing aborted before the confirmation');
    h.controller.confirmCancel();
    assert.equal(h.dl().aborted, 1, 'the downloader\'s AbortController is used');
    assert.equal(h.requests.filter((r) => r.url.endsWith('/cancel')).length, 0, 'the server job is not touched');

    const m = h.last();
    assert.equal(m.state, 'Téléchargement annulé');
    assert.equal(m.canCancel, false);
    assert.equal(m.canClose, true);
    assert.equal(m.percent, null);
    assert.equal(m.download.title, "90 Mo / 149 Mo reçus avant l'annulation");
    assert.equal(m.downloadDuration, 'Durée de téléchargement : 00:00:04');
    // Late callbacks of the aborted stream change nothing.
    h.dl().on.progress(100 * MB, 149 * MB);
    h.dl().on.error({ kind: 'aborted' });
    h.dl().on.done({ loaded: 149 * MB, total: 149 * MB });
    assert.equal(h.last().state, 'Téléchargement annulé');
    for (const r of h.renders) assert.doesNotMatch(JSON.stringify(r), /terminé/);
    await h.advance(10000);
    assert.equal(h.controller.model().downloadDuration, 'Durée de téléchargement : 00:00:04', 'clock stopped');
    assert.equal(h.local.get(DOWNLOADED_KEY), null, 'not recorded as downloaded');
    assert.equal(h.session.get(ACTIVE_KEY), null, 'not restarted by the next page');
    assert.equal(h.controller.isBusy(), false, 'a new export may start');
    assert.equal(h.controller.close(), true);
});

test('"Continuer" in the confirmation keeps the download running', async () => {
    const h = harness();
    await toDownload(h);
    h.controller.cancel();
    h.controller.abortCancel();
    assert.equal(h.dl().aborted, 0);
    assert.equal(h.last().canCancel, true);
    h.dl().on.done({ loaded: FILE, total: FILE });
    assert.equal(h.last().state, '✓ Téléchargement terminé');
});

test('server-side cancellation during the generation is unchanged (POST /cancel, export wording)', async () => {
    const h = harness();
    h.answer('/exports/70', running(1000));
    h.answer('/exports/70/cancel', { success: true, timing: { elapsedSeconds: 31 } });
    h.controller.begin({ format: 'csv' });
    h.controller.attach(70, { format: 'csv' });
    await h.advance(0);
    h.controller.cancel();
    assert.equal(h.last().confirmText, 'Voulez-vous vraiment annuler cet export ?');
    h.controller.confirmCancel();
    await h.advance(0);
    assert.equal(h.requests.filter((r) => r.url === '/exports/70/cancel').length, 1);
    assert.equal(h.last().state, 'Export annulé');
    assert.equal(h.last().downloadDuration, '');
});

test('TEST 9: network error during the download → no Annuler, Fermer, download clock frozen', async () => {
    const h = harness();
    await toDownload(h);
    await h.advance(3000);
    h.dl().on.error({ kind: 'network' });
    const m = h.last();
    assert.equal(m.state, 'Échec du téléchargement');
    assert.equal(m.canCancel, false);
    assert.equal(m.canClose, true);
    assert.equal(m.downloadDuration, 'Durée de téléchargement : 00:00:03');
});

test('sync export (no job id): the download can be cancelled too', () => {
    const h = harness();
    h.controller.begin({ format: 'csv', count: 1200 });
    h.controller.syncDone({ format: 'csv', count: 1200, rows: 1200, downloadUrl: '/dashboard/export/download?f=x', fileSize: 2 * MB, generationSeconds: 4 });
    assert.equal(h.last().canCancel, true);
    assert.equal(h.last().duration, 'Durée de génération : 00:00:04');
    h.controller.cancel();
    h.controller.confirmCancel();
    assert.equal(h.dl().aborted, 1);
    assert.equal(h.last().state, 'Téléchargement annulé');
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
