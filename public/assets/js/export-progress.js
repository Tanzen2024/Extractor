/**
 * Asynchronous export progress — the DOM-free part of the dashboard's job
 * polling, loaded before dashboard.js (window.bscdExportProgress) and
 * unit-tested with Node (tests/js/export-progress.test.js).
 *
 *   progressView(job)       what to show for one GET /exports/{id} answer.
 *                           Only the API's own figures are used — nothing is
 *                           estimated or animated: no usable progress while
 *                           running = no percentage at all. Its percent is
 *                           the GENERATION of the file; 'done' = file ready,
 *                           download in progress (indeterminate, never 100 %).
 *   createExportPhase()     GENERATING → READY → DOWNLOADING → COMPLETED state
 *                           machine (serverProgress / downloadProgress kept apart).
 *   createTracker(done)     per-page state: which job the modal currently
 *                           shows (only that one may touch it) and which jobs
 *                           were already downloaded (`done` = downloadedJobs —
 *                           each job's file is downloaded once, however many
 *                           "done" answers arrive).
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdExportProgress = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function isNumber(value) {
        return typeof value === 'number' && isFinite(value);
    }

    /** Integer within [min, max] (default 0..100); non-numbers give min. */
    function clampPercent(value, max) {
        var hi = max === undefined ? 100 : max;
        var n = Number(value);
        if (value === null || value === undefined || value === '' || !isFinite(n)) return 0;
        return Math.max(0, Math.min(hi, Math.floor(n)));
    }

    function count(value) {
        return isNumber(value) && value > 0 ? Math.floor(value) : 0;
    }

    /**
     * @return {{state: string, title: string, percent: number|null,
     *           processed: number|null, total: number|null, exported: number|null}|null}
     *     percent null = no bar (progress unknown); processed/total/exported
     *     null = no line counts to show. null for an unusable answer.
     */
    function progressView(job) {
        var status = job && job.status;
        var p = job && job.progress;
        var total = p ? count(p.total) : 0;
        var counts = total > 0
            ? { processed: Math.min(count(p.processed), total), total: total, exported: count(p.exported) }
            : { processed: null, total: null, exported: null };

        // percent = progress of the SERVER-SIDE GENERATION only (rows scanned).
        // It never stands for the download: see 'done' below.
        if (status === 'pending') {
            return {
                state: 'pending', phase: PHASE.GENERATING, heading: 'Export en cours', title: 'En attente du traitement…',
                percent: 0, indeterminate: false, processed: null, total: null, exported: null, fileSize: null
            };
        }
        if (status === 'running') {
            if (!p || total === 0 || !isNumber(p.percent)) {
                return {
                    state: 'running', phase: PHASE.GENERATING, heading: 'Export en cours', title: 'Préparation du fichier…',
                    percent: null, indeterminate: false, processed: null, total: null, exported: null, fileSize: null
                };
            }
            // Still running = never 100 %: the file is finalised after the scan.
            return {
                state: 'running', phase: PHASE.GENERATING, heading: 'Export en cours', title: 'Préparation du fichier…',
                percent: clampPercent(p.percent, 99), indeterminate: false,
                processed: counts.processed, total: counts.total, exported: counts.exported, fileSize: null
            };
        }
        if (status === 'cancelled') {
            // No progress shown for a cancelled export (the API's frozen
            // counters are history, not something still moving).
            return { state: 'cancelled', phase: PHASE.CANCELLED, heading: 'Export annulé', title: 'Export annulé.', percent: null, indeterminate: false, processed: null, total: null, exported: null, fileSize: null };
        }
        if (status === 'error') {
            // Like cancelled: frozen counters are not shown as a moving bar.
            return { state: 'error', phase: PHASE.ERROR, heading: "Échec de l'export", title: "Échec de l'export.", percent: null, indeterminate: false, processed: null, total: null, exported: null, fileSize: null };
        }
        if (status === 'done') {
            // The file is generated, NOT downloaded: the browser downloads it
            // on its own (window.location → Content-Disposition: attachment)
            // and the page sees neither the bytes received nor the end. So no
            // percentage, no bar, and no "en cours" either (false as soon as
            // the browser has finished): only what stays true — launched.
            return {
                state: 'done', phase: PHASE.DOWNLOADING, heading: 'Fichier prêt', title: 'Téléchargement lancé',
                percent: null, indeterminate: false,
                processed: counts.processed, total: counts.total, exported: counts.exported,
                fileSize: job && isNumber(job.fileSize) && job.fileSize > 0 ? job.fileSize : null
            };
        }
        return null;
    }

    /** Bytes → "7,5 Mo" / "606 Mo" / "1,2 Go" (French decimal comma). */
    function formatSize(bytes) {
        var n = Number(bytes);
        if (!isFinite(n) || n < 0) return '';
        var units = ['o', 'Ko', 'Mo', 'Go'];
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        var digits = i === 0 || n >= 10 ? 0 : 1;
        return n.toFixed(digits).replace('.', ',') + ' ' + units[i];
    }

    /**
     * The modal's state machine — two separate measures, never mixed:
     *   serverProgress    generation of the file by the worker (0..99 while
     *                     running, 100 once generated);
     *   downloadProgress  bytes received by the browser, 0..100 — only for a
     *                     download the page itself measures; null = unknown
     *                     (browser-managed download: indeterminate bar).
     *
     *   GENERATING → READY → DOWNLOADING → COMPLETED
     *   GENERATING → CANCELLED | ERROR,  READY / DOWNLOADING → ERROR,
 *   DOWNLOADING → CANCELLED (download aborted by the user)
     *
     * Any other move is refused (returns false): e.g. a late "running" poll
     * answer can never redraw the generation bar over the download phase,
     * and an unmeasured download can never become COMPLETED / 100 %.
     */
    var PHASE = {
        GENERATING: 'generating', READY: 'ready', DOWNLOADING: 'downloading',
        COMPLETED: 'completed', CANCELLED: 'cancelled', ERROR: 'error'
    };
    var TRANSITIONS = {
        generating: [PHASE.READY, PHASE.CANCELLED, PHASE.ERROR],
        ready: [PHASE.DOWNLOADING, PHASE.ERROR],
        downloading: [PHASE.COMPLETED, PHASE.CANCELLED, PHASE.ERROR]
    };

    function createExportPhase() {
        var phase = PHASE.GENERATING;
        var serverProgress = null;
        var downloadProgress = null;
        var measured = false;

        function go(next) {
            if ((TRANSITIONS[phase] || []).indexOf(next) === -1) return false;
            phase = next;
            return true;
        }

        return {
            phase: function () { return phase; },
            serverProgress: function () { return serverProgress; },
            downloadProgress: function () { return downloadProgress; },
            /** A generation answer (progressView); accepted only while generating. */
            server: function (view) {
                if (phase !== PHASE.GENERATING) return false;
                serverProgress = view ? view.percent : null;
                return true;
            },
            /** Job done: generation complete (serverProgress = 100, a real figure). */
            ready: function () {
                if (!go(PHASE.READY)) return false;
                serverProgress = 100;
                return true;
            },
            /**
             * Download handed over. isMeasured = the page reads the bytes
             * itself; false for window.location (browser-managed): progress
             * stays null and the phase can never be confirmed complete.
             */
            downloading: function (isMeasured) {
                if (!go(PHASE.DOWNLOADING)) return false;
                measured = isMeasured === true;
                downloadProgress = measured ? 0 : null;
                return true;
            },
            /** Bytes really received (measured download only); capped at 99 until completed(). */
            received: function (loaded, total) {
                if (phase !== PHASE.DOWNLOADING || !measured || !(total > 0)) return false;
                downloadProgress = clampPercent((loaded / total) * 100, 99);
                return true;
            },
            /** End of a measured download — the only way to reach 100 %. */
            completed: function () {
                if (!measured || !go(PHASE.COMPLETED)) return false;
                downloadProgress = 100;
                return true;
            },
            cancelled: function () { return go(PHASE.CANCELLED); },
            failed: function () { return go(PHASE.ERROR); }
        };
    }

    function createTracker(downloadedJobs) {
        var downloaded = downloadedJobs || {};
        var cancelled = {};     // job ids whose cancellation the server confirmed
        var activeJobId = null;

        return {
            activate: function (jobId) { activeJobId = jobId; },
            deactivate: function () { activeJobId = null; },
            isActive: function (jobId) { return activeJobId !== null && activeJobId === jobId; },
            /** POST /exports/{id}/cancel succeeded: from now on this job is never downloaded or redrawn. */
            markCancelled: function (jobId) { cancelled[jobId] = true; },

            /**
             * Decides what one poll answer means for job `jobId`:
             *   stop      clear this job's polling timer;
             *   render    this job is the one the modal shows — update it;
             *   download  URL to open (at most once per job), else null;
             *   kind      'progress' | 'done' | 'duplicate' | 'error' | 'cancelled' | 'unreachable' | 'unknown'.
             *
             * 'cancelled' (answer or local confirmation) is terminal and never
             * downloads — not even a poll answer that was in flight when the
             * cancellation was confirmed.
             *
             * 'unreachable' = the poll itself failed (bscdFetch's {error} for a
             * 404, a 500, a redirect to /login after the session expired, ...).
             * Polling goes on — the worker keeps running server-side — but the
             * modal must say so instead of staying frozen on the last state.
             */
            handle: function (jobId, job) {
                var render = this.isActive(jobId);
                var status = job && job.status;

                if (cancelled[jobId]) {
                    return { stop: true, render: false, download: null, kind: 'cancelled', view: null };
                }
                if (!job || job.error) {
                    return { stop: false, render: render, download: null, kind: 'unreachable', view: null };
                }
                if (status === 'cancelled') {
                    cancelled[jobId] = true;
                    return { stop: true, render: render, download: null, kind: 'cancelled', view: progressView(job) };
                }

                if (status === 'done') {
                    if (downloaded[jobId]) {
                        return { stop: true, render: false, download: null, kind: 'duplicate', view: null };
                    }
                    downloaded[jobId] = true;
                    return { stop: true, render: render, download: job.downloadUrl || null, kind: 'done', view: progressView(job) };
                }
                if (status === 'error') {
                    return { stop: true, render: render, download: null, kind: 'error', view: null };
                }
                if (status === 'pending' || status === 'running') {
                    return { stop: false, render: render, download: null, kind: 'progress', view: progressView(job) };
                }
                return { stop: false, render: false, download: null, kind: 'unknown', view: null };
            }
        };
    }

    /**
     * Reads a download response (fetch) chunk by chunk and counts the bytes
     * really received — the figure behind the download bar:
     *   onProgress(loaded, total)   total = Content-Length, null when absent.
     *
     * Large files (600 Mo+): the raw chunks are packed into Blob parts of
     * ~segmentBytes as they arrive, so the page never holds more than one
     * segment of chunks; the browser keeps the parts in its blob storage
     * (on disk for large blobs in Chromium) — the whole file is never read
     * into the page at once (no Response blob / arrayBuffer of the body).
     *
     * @return Promise<{blob, loaded, total}> once the stream has ENDED, and
     *   only if every announced byte arrived. Rejects with {kind, loaded,
     *   total}: 'unsupported' (no ReadableStream), 'network' (stream broken),
     *   'incomplete' (ended before Content-Length).
     */
    function readDownload(response, onProgress, options) {
        var opts = options || {};
        var BlobCtor = opts.Blob || Blob;
        var segmentBytes = opts.segmentBytes || 8 * 1048576;
        var header = response.headers && response.headers.get('Content-Length');
        var announced = header === null || header === undefined || header === '' ? NaN : Number(header);
        var total = isFinite(announced) && announced > 0 ? announced : null;
        var type = (response.headers && response.headers.get('Content-Type')) || 'application/octet-stream';

        if (!response.body || typeof response.body.getReader !== 'function') {
            return Promise.reject({ kind: 'unsupported', loaded: 0, total: total });
        }

        var reader = response.body.getReader();
        var parts = [];
        var pending = [];
        var pendingBytes = 0;
        var loaded = 0;

        function flush() {
            if (pending.length === 0) return;
            parts.push(new BlobCtor(pending));
            pending = [];
            pendingBytes = 0;
        }

        onProgress(0, total);

        function pump() {
            return reader.read().then(function (step) {
                if (step.done) {
                    flush();
                    if (total !== null && loaded !== total) {
                        throw { kind: 'incomplete', loaded: loaded, total: total };
                    }
                    return { blob: new BlobCtor(parts, { type: type }), loaded: loaded, total: total };
                }
                var chunk = step.value;
                pending.push(chunk);
                pendingBytes += chunk.byteLength;
                loaded += chunk.byteLength;
                if (pendingBytes >= segmentBytes) flush();
                onProgress(loaded, total);
                return pump();
            }, function (cause) {
                throw { kind: 'network', loaded: loaded, total: total, cause: cause };
            });
        }

        return pump();
    }

    /** Whole seconds → "HH:MM:SS" (hours not capped at 24). */
    function formatDuration(seconds) {
        var s = Math.max(0, Math.floor(Number(seconds) || 0));
        var pad = function (n) { return n < 10 ? '0' + n : String(n); };
        return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor(s / 60) % 60) + ':' + pad(s % 60);
    }

    /**
     * Duration line of one job. The reference always comes from the server
     * (`timing` of GET /exports/{id}, in seconds); between two polls the
     * value only advances with the local clock (`nowMs`, e.g. Date.now()).
     *
     *   sync(job, nowMs)   feed one poll answer;
     *   display(nowMs)     {label, text} to show, or null (nothing reliable);
     *   isFinal()          true once the total is fixed (stop ticking).
     *
     * Within one phase (attente / traitement) the shown value never goes
     * back, so a poll arriving a bit late never makes the counter jump down.
     */
    function createDurationClock() {
        var phase = null;       // 'wait' | 'run' | 'final'
        var finalLabel = 'Durée totale';
        var base = null;        // server seconds at `baseAt`
        var baseAt = 0;         // local ms when `base` was received
        var shown = 0;          // highest value displayed in this phase

        function current(nowMs) {
            if (base === null) return null;
            var value = phase === 'final' ? base : base + Math.max(0, Math.floor((nowMs - baseAt) / 1000));
            if (phase !== 'final') shown = Math.max(shown, value);
            return phase === 'final' ? value : shown;
        }

        function enter(next) {
            // A new phase never inherits the previous one's value (the queue
            // time is not processing time).
            if (phase !== next) { phase = next; base = null; shown = 0; }
        }

        return {
            sync: function (job, nowMs) {
                if (phase === 'final') return;                 // fixed for good
                var t = job && job.timing;
                var status = job && job.status;
                if (status === 'done' || status === 'error' || status === 'cancelled') {
                    // Cancelled: time spent until the cancellation, then frozen.
                    // Done: the server's started_at → finished_at, i.e. the
                    // GENERATION only — the download is still going on.
                    finalLabel = status === 'cancelled' ? 'Durée écoulée' : (status === 'done' ? 'Durée de génération' : 'Durée totale');
                    // Fallback: no server total → freeze what is shown now.
                    var total = t && isNumber(t.elapsedSeconds) ? t.elapsedSeconds : (phase === 'run' ? current(nowMs) : null);
                    phase = 'final'; base = total; baseAt = nowMs;
                    return;
                }
                if (status === 'running') {
                    enter('run');
                    if (t && isNumber(t.elapsedSeconds)) { base = t.elapsedSeconds; baseAt = nowMs; }
                    return;
                }
                if (status === 'pending') {
                    enter('wait');
                    if (t && isNumber(t.waitSeconds)) { base = t.waitSeconds; baseAt = nowMs; }
                }
            },
            display: function (nowMs) {
                var value = current(nowMs);
                if (value === null) return null;
                var label = phase === 'final' ? finalLabel : (phase === 'run' ? 'Durée écoulée' : "Durée d'attente");
                return { label: label, text: label + ' : ' + formatDuration(value) };
            },
            isFinal: function () { return phase === 'final'; }
        };
    }

    return {
        PHASE: PHASE, clampPercent: clampPercent, progressView: progressView, createTracker: createTracker,
        createExportPhase: createExportPhase, formatSize: formatSize, readDownload: readDownload,
        formatDuration: formatDuration, createDurationClock: createDurationClock
    };
}));
