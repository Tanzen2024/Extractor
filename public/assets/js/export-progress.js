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
        var activeJobId = null;

        return {
            activate: function (jobId) { activeJobId = jobId; },
            deactivate: function () { activeJobId = null; },
            isActive: function (jobId) { return activeJobId !== null && activeJobId === jobId; },

            /**
             * Decides what one poll answer means for job `jobId`:
             *   stop      clear this job's polling timer;
             *   render    this job is the one the modal shows — update it;
             *   download  URL to open (at most once per job), else null;
             *   kind      'progress' | 'done' | 'duplicate' | 'error' | 'unknown'.
             */
            handle: function (jobId, job) {
                var render = this.isActive(jobId);
                var status = job && job.status;

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

    return { clampPercent: clampPercent, progressView: progressView, createTracker: createTracker };
}));
