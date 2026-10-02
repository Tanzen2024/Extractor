// Run: node --test tests/js/export-widget.test.js
// Floating export window (public/assets/js/export-widget.js), DOM-free
// controller driven with fake timers / fetch: generation and download are two
// operations, and nothing ever shows 100 % or "terminé" while the browser is
// still downloading.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createExportController, ACTIVE_KEY, DOWNLOADED_KEY } = require('../../public/assets/js/export-widget.js');

const MB = 1048576;
const TOTAL = 3305219;

function memoryStore() {
    const data = {};
    return {
        data,
        get: (k) => (k in data ? JSON.parse(data[k]) : null),
        set: (k, v) => { data[k] = JSON.stringify(v); },
        remove: (k) => { delete data[k]; },
    };
}

/** Harness: manual timers, scripted poll answers, recorded renders/downloads. */
function harness() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();
    const answers = {};          // url → list of answers (last one repeats)
    const requests = [];
    const h = {
        renders: [], downloads: [], requests,
        session: memoryStore(), local: memoryStore(),
        answer(url, ...list) { answers[url] = list; },
        last() { return h.renders[h.renders.length - 1]; },
        pendingTimers() { return timers.size; },
        /** Run every timer due within `ms`, then let the promise callbacks settle. */
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
            requests.push({ url, opts });
            const list = answers[url] || [{ error: 'http', status: 404 }];
            return Promise.resolve(list.length > 1 ? list.shift() : list[0]);
        },
        session: h.session, local: h.local,
        setTimer(fn, ms) { const id = nextId++; timers.set(id, { fn, at: now + ms }); return id; },
        clearTimer(id) { timers.delete(id); },
        now: () => now,
        download(url) { h.downloads.push(url); },
        render(m) { h.renders.push(m); },
        isHidden: () => false,
    });
    return h;
}

const running = (processed, exported = processed) => ({
    status: 'running', format: 'csv', rowCount: 2431711,
    progress: { percent: Math.floor((processed / TOTAL) * 100), processed, total: TOTAL, exported },
    timing: { elapsedSeconds: 30 },
});
const done = {
    status: 'done', format: 'csv', rowCount: 2431711, downloadUrl: '/exports/42/download', fileSize: 606 * MB, fileName: 'x.csv',
    progress: { percent: 100, processed: TOTAL, total: TOTAL, exported: 2431711 }, timing: { elapsedSeconds: 60, final: true },
};

test('generation: determinate bar from the API figures only, "Préparation du fichier…"', async () => {
    const h = harness();
    h.answer('/exports/42', running(2025000, 1500000));
    h.controller.begin({ format: 'csv', count: 2431711 });
    assert.equal(h.last().phase, 'preparing');
    assert.equal(h.last().indeterminate, true, 'POST in flight: nothing measurable yet');
    assert.equal(h.last().percent, null);

    h.controller.attach(42, { format: 'csv', count: 2431711 });
    await h.advance(0);
    const m = h.last();
    assert.equal(m.phase, 'running');
    assert.equal(m.title, 'Export des données');
    assert.equal(m.state, 'Export en cours');
    assert.equal(m.status, 'Préparation du fichier…');
    assert.equal(m.format, 'Format : CSV');
    assert.equal(m.size, '', 'no size before the file exists');
    assert.equal(m.download, null);
    assert.equal(m.percent, 61);
    const fr = (n) => new Intl.NumberFormat('fr-FR').format(n);
    assert.equal(m.counts, fr(2025000) + ' / ' + fr(TOTAL) + ' lignes parcourues');
    assert.match(m.exported, /lignes exportées$/);
    assert.equal(m.canCancel, true);
    assert.equal(m.canClose, false, 'no "Fermer" while the export runs');
});

test('done → "✓ Fichier généré" keeps the generation figures, adds size + "Téléchargement lancé"; no bar, never 100 %, download once', async () => {
    const h = harness();
    h.answer('/exports/42', running(TOTAL), done); // scan over, file still being finalised
    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, { format: 'csv' });
    await h.advance(0);
    assert.equal(h.last().phase, 'finalizing');
    assert.ok(h.last().percent <= 99);

    await h.advance(3000);
    const m = h.last();
    assert.equal(m.phase, 'downloading');
    const fr = (n) => new Intl.NumberFormat('fr-FR').format(n);
    assert.equal(m.title, 'Export des données');
    assert.equal(m.state, '✓ Fichier généré');
    // Generation figures are kept, not wiped by the end of the job.
    assert.equal(m.counts, fr(TOTAL) + ' / ' + fr(TOTAL) + ' lignes parcourues');
    assert.equal(m.exported, fr(2431711) + ' lignes exportées');
    assert.equal(m.format, 'Format : CSV');
    assert.equal(m.size, 'Taille : 606 Mo');
    assert.equal(m.duration, 'Durée de génération : 00:01:00');
    // Download: a note, never a bar or a figure.
    assert.equal(m.percent, null, 'generated ≠ downloaded: no percentage');
    assert.equal(m.indeterminate, false, 'no endless animation: it would still say "en cours" once the browser has finished');
    assert.equal(m.download.title, 'Téléchargement lancé');
    assert.match(m.download.text, /^Géré par votre navigateur\./);
    assert.match(m.download.text, /continue dans votre navigateur/);
    assert.deepEqual(h.downloads, ['/exports/42/download']);
    assert.deepEqual(h.local.get(DOWNLOADED_KEY), [42]);

    // The only action left: closing the window, which never stops the browser download.
    assert.equal(m.canClose, true);
    assert.equal(m.closeLabel, 'Fermer la fenêtre');
    assert.equal(m.canCancel, false);

    for (const r of h.renders) {
        assert.notEqual(r && r.percent, 100, 'no render ever shows 100 %');
        const text = r ? [r.title, r.state, r.status, r.download && r.download.title, r.download && r.download.text].join(' ') : '';
        assert.doesNotMatch(text, /Téléchargement terminé|téléchargé/i);
        if (r && r.phase === 'downloading') assert.doesNotMatch(text, /en cours/i);
    }
});

test('downloading: no timer closes the window, polling stopped, nothing left to resume', async () => {
    const h = harness();
    h.answer('/exports/42', done);
    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, {});
    await h.advance(0);
    assert.equal(h.last().phase, 'downloading');
    const polls = h.requests.length;

    await h.advance(10 * 60 * 1000); // ten minutes later
    assert.equal(h.last().phase, 'downloading', 'still honest: the download may still be running');
    assert.equal(h.requests.length, polls, 'no polling after the server job ended');
    assert.equal(h.pendingTimers(), 0);
    assert.equal(h.session.get(ACTIVE_KEY), null);

    assert.equal(h.controller.close(), true);
    assert.equal(h.last(), null, 'closed by the user');
    assert.deepEqual(h.downloads, ['/exports/42/download'], 'still exactly one download');
});

test('a second "done" (reload / duplicated tab) never downloads again', async () => {
    const h = harness();
    h.local.set(DOWNLOADED_KEY, [42]);
    h.answer('/exports/42', done);
    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, {});
    await h.advance(0);
    assert.equal(h.last().phase, 'downloading');
    assert.deepEqual(h.downloads, []);
});

test('one polling loop: never two status requests in flight, no second loop on attach', async () => {
    const h = harness();
    h.answer('/exports/42', running(100000), running(200000), running(300000));
    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, {});
    assert.equal(h.controller.attach(43, {}), false, 'one export followed at a time');
    await h.advance(0);
    await h.advance(9000);
    const statusCalls = h.requests.filter((r) => r.url === '/exports/42').length;
    assert.equal(statusCalls, 4); // t=0, 3 s, 6 s, 9 s
    assert.equal(h.requests.filter((r) => r.url === '/exports/43').length, 0);
});

test('two exports in a row: the window is reused, each job polled and downloaded once, the first one never again', async () => {
    const h = harness();
    h.answer('/exports/42', running(100000), done);
    h.answer('/exports/43', running(50000), { ...done, downloadUrl: '/exports/43/download' });

    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, {});
    await h.advance(0);
    await h.advance(3000);
    assert.equal(h.last().phase, 'downloading');
    assert.equal(h.pendingTimers(), 0, 'first job: polling over');

    // Second launch while the first window still shows "Téléchargement lancé".
    assert.equal(h.controller.begin({ format: 'csv' }), true);
    assert.equal(h.last().phase, 'preparing', 'same window, new export');
    h.controller.attach(43, {});
    await h.advance(0);
    await h.advance(3000);
    await h.advance(30000);

    assert.equal(h.last().jobId, 43);
    assert.equal(h.last().phase, 'downloading');
    assert.deepEqual(h.downloads, ['/exports/42/download', '/exports/43/download']);
    assert.equal(h.requests.filter((r) => r.url === '/exports/42').length, 2, 'job 42 no longer polled once done');
    assert.equal(h.pendingTimers(), 0);
});

test('sync export (generated by the POST) → same downloading state, one download', () => {
    const h = harness();
    h.controller.begin({ format: 'csv', count: 1200 });
    h.controller.syncDone({ format: 'csv', count: 1200, rows: 1200, downloadUrl: '/dashboard/export/download?file=x', fileSize: 7.5 * MB, generationSeconds: 4 });
    const m = h.last();
    assert.equal(m.phase, 'downloading');
    assert.equal(m.state, '✓ Fichier généré');
    assert.equal(m.percent, null);
    assert.equal(m.indeterminate, false);
    assert.match(m.exported, /1\D?200 lignes exportées/);
    assert.equal(m.size, 'Taille : 7,5 Mo');
    assert.equal(m.duration, 'Durée de génération : 00:00:04');
    assert.equal(m.download.title, 'Téléchargement lancé');
    assert.deepEqual(h.downloads, ['/dashboard/export/download?file=x']);
});

test('sync export without a download URL → error, no download', () => {
    const h = harness();
    h.controller.begin({ format: 'csv' });
    h.controller.syncDone({ format: 'csv', count: 3 });
    assert.equal(h.last().phase, 'error');
    assert.deepEqual(h.downloads, []);
});

test('cancel during generation: confirmation, real POST, then cancelled (auto-closes) and never downloaded', async () => {
    const h = harness();
    h.answer('/exports/42', running(500000), done);
    h.answer('/exports/42/cancel', { success: true, status: 'cancelled', timing: { elapsedSeconds: 12 } });
    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, {});
    await h.advance(0);

    h.controller.cancel();
    assert.equal(h.last().confirming, true);
    h.controller.confirmCancel();
    await h.advance(0);
    assert.equal(h.last().phase, 'cancelled');
    assert.equal(h.requests.find((r) => r.url === '/exports/42/cancel').opts.method, 'POST');

    await h.advance(5000);
    assert.equal(h.last(), null, 'a cancelled export closes by itself');
    assert.deepEqual(h.downloads, []);
});

test('server error → error phase with its reference, no download, closable', async () => {
    const h = harness();
    h.answer('/exports/42', { status: 'error', reference: 'EXPJOB-1', failure: { reference: 'EXPJOB-1', message: 'Échec.' } });
    h.controller.begin({ format: 'csv' });
    h.controller.attach(42, {});
    await h.advance(0);
    const m = h.last();
    assert.equal(m.phase, 'error');
    assert.equal(m.reference, 'EXPJOB-1');
    assert.equal(m.percent, null);
    assert.equal(m.canClose, true);
    assert.equal(m.state, "Échec de l'export");
    assert.deepEqual(h.downloads, []);
});

test('resume on the next page of the tab, then done → downloading (still once)', async () => {
    const h = harness();
    h.session.set(ACTIVE_KEY, { jobId: 42, format: 'csv', count: 10 });
    h.answer('/exports/42', done);
    assert.equal(h.controller.resume(), true);
    await h.advance(0);
    assert.equal(h.last().phase, 'downloading');
    assert.deepEqual(h.downloads, ['/exports/42/download']);
});
