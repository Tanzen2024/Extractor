/**
 * Asynchronous export progress — the DOM-free part of the dashboard's job
 * polling, loaded before dashboard.js (window.bscdExportProgress) and
 * unit-tested with Node (tests/js/export-progress.test.js).
 *
 *   progressView(job)       what to show for one GET /exports/{id} answer.
 *                           Only the API's own figures are used — nothing is
 *                           estimated or animated: no usable progress while
 *                           running = no percentage at all.
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

        if (status === 'pending') {
            return { state: 'pending', title: 'Export en attente…', percent: 0, processed: null, total: null, exported: null };
        }
        if (status === 'running') {
            if (!p || total === 0 || !isNumber(p.percent)) {
                return { state: 'running', title: 'Export en cours…', percent: null, processed: null, total: null, exported: null };
            }
            // Still running = never 100 %: the file is finalised after the scan.
            return {
                state: 'running', title: 'Export en cours…', percent: clampPercent(p.percent, 99),
                processed: counts.processed, total: counts.total, exported: counts.exported
            };
        }
        if (status === 'cancelled') {
            // No progress shown for a cancelled export (the API's frozen
            // counters are history, not something still moving).
            return { state: 'cancelled', title: 'Export annulé.', percent: null, processed: null, total: null, exported: null };
        }
        if (status === 'error') {
            // Like cancelled: frozen counters are not shown as a moving bar.
            return { state: 'error', title: "Échec de l'export.", percent: null, processed: null, total: null, exported: null };
        }
        if (status === 'done') {
            return {
                state: 'done', title: 'Fichier prêt — téléchargement…', percent: 100,
                processed: counts.processed, total: counts.total, exported: counts.exported
            };
        }
        return null;
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
                    finalLabel = status === 'cancelled' ? 'Durée écoulée' : 'Durée totale';
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
        clampPercent: clampPercent, progressView: progressView, createTracker: createTracker,
        formatDuration: formatDuration, createDurationClock: createDurationClock
    };
}));
