/**
 * Data freshness indicator of the "Détail des clients" section.
 *
 *   GET /dashboard/sync-status   state of the active data (no customer query)
 *   GET /dashboard/sync-history  latest runs, loaded when the modal opens
 *
 * The state comes from the server (SyncFreshness): it follows the date the
 * active version was really validated, never a customer date. This file only
 * turns it into text; the pure part is exported for tests/js/sync-status.test.js.
 */
(function (root, factory) {
    var I18N = root && root.bscdI18n ? root.bscdI18n : (typeof require === 'function' ? require('./i18n.js') : null);
    var api = factory(I18N);
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdSyncStatus = api;
        api.start(root.document);
    }
}(typeof self !== 'undefined' ? self : this, function (I18N) {
    'use strict';

    var REFRESH_MS = 5 * 60 * 1000;
    var TIME_ZONE = 'Africa/Douala';
    var STATES = I18N.t('dashboard.sync.state');
    var STATUSES = I18N.t('dashboard.sync.status');
    var TRIGGERS = I18N.t('dashboard.sync.trigger');
    var REASONS = I18N.t('dashboard.sync.reason');

    function dateTime(iso) {
        if (!iso) return null;
        var date = new Date(iso);
        if (isNaN(date.getTime())) return null;
        return new Intl.DateTimeFormat(I18N.locale === 'en' ? 'en-GB' : 'fr-FR', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE,
        }).format(date);
    }

    /** '2026-11-06 04:32:26' is already Douala time, the stamp of the Oracle load. */
    function sourceDate(stamp) {
        var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(stamp || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : null;
    }

    function reasonText(reason) {
        if (!reason) return '';
        return REASONS[reason] || REASONS.other;
    }

    function runStatusText(status) {
        return status ? STATUSES[status] : I18N.t('dashboard.sync.none');
    }

    /** @return {{state: string, label: string, running: boolean, facts: Array<{label: string, value: string}>}} */
    function describe(status) {
        var lastStatus = runStatusText(status.lastStatus);
        var reason = reasonText(status.lastReason);
        var none = I18N.t('dashboard.sync.none');

        return {
            state: status.state,
            label: STATES[status.state],
            running: !!status.running,
            facts: [
                { label: I18N.t('dashboard.sync.lastSuccess'), value: dateTime(status.lastSuccessAt) || none },
                { label: I18N.t('dashboard.sync.lastRun'), value: reason ? lastStatus + ' — ' + reason : lastStatus },
                {
                    label: I18N.t('dashboard.sync.clients'),
                    value: status.clients === null || status.clients === undefined ? none : I18N.number(status.clients),
                },
                { label: I18N.t('dashboard.sync.source'), value: sourceDate(status.sourceUpdatedAt) || none },
                { label: I18N.t('dashboard.sync.nextRun'), value: dateTime(status.nextRunAt) || I18N.t('dashboard.sync.notScheduled') },
            ],
        };
    }

    function count(value) {
        return value === null || value === undefined ? '–' : I18N.number(value);
    }

    function duration(seconds) {
        if (seconds === null || seconds === undefined) return '–';
        var s = Number(seconds);
        return s >= 60 ? Math.floor(s / 60) + ' min ' + (s % 60) + ' s' : s + ' s';
    }

    /** The cells of one history row, in table order. */
    function historyCells(run) {
        return [
            dateTime(String(run.startedAt).replace(' ', 'T') + '+01:00') || '–',
            duration(run.durationSeconds),
            TRIGGERS[run.trigger],
            runStatusText(run.status),
            count(run.rowsRead),
            count(run.rowsAdded),
            count(run.rowsModified),
            count(run.rowsIgnored),
            count(run.rowsRemoved),
            run.errorMessage || reasonText(run.errorReason) || '',
        ];
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            });
    }

    function render(doc, box, view) {
        box.dataset.state = view.state;
        box.querySelector('[data-sync-badge]').textContent = view.label;

        var running = box.querySelector('[data-sync-running]');
        running.hidden = !view.running;
        running.textContent = I18N.t('dashboard.sync.running');

        var facts = box.querySelector('[data-sync-facts]');
        facts.textContent = '';
        view.facts.forEach(function (fact) {
            var item = doc.createElement('div');
            item.className = 'bscd-sync__fact';
            var label = doc.createElement('dt');
            label.textContent = fact.label;
            var value = doc.createElement('dd');
            value.textContent = fact.value;
            item.appendChild(label);
            item.appendChild(value);
            facts.appendChild(item);
        });
    }

    function renderHistory(doc, body, runs) {
        body.textContent = '';
        if (!runs.length) {
            var empty = doc.createElement('td');
            empty.colSpan = 10;
            empty.textContent = I18N.t('dashboard.sync.historyEmpty');
            body.appendChild(doc.createElement('tr')).appendChild(empty);
            return;
        }
        runs.forEach(function (run) {
            var tr = doc.createElement('tr');
            tr.dataset.status = run.status;
            historyCells(run).forEach(function (text) {
                var td = doc.createElement('td');
                td.textContent = text;
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
    }

    function start(doc) {
        var box = doc.getElementById('bscdSyncStatus');
        var data = doc.getElementById('bscd-dashboard-data');
        if (!box || !data) return;

        var endpoints = JSON.parse(data.textContent).endpoints;

        function refresh() {
            getJson(endpoints.syncStatus)
                .then(function (status) { render(doc, box, describe(status)); })
                .catch(function () {
                    box.dataset.state = 'unknown';
                    box.querySelector('[data-sync-badge]').textContent = I18N.t('dashboard.sync.unavailable');
                });
        }

        refresh();
        setInterval(refresh, REFRESH_MS);

        var body = doc.getElementById('bscdSyncHistoryBody');
        doc.querySelector('[data-sync-history]').addEventListener('click', function () {
            getJson(endpoints.syncHistory)
                .then(function (answer) { renderHistory(doc, body, answer.runs); })
                .catch(function () { renderHistory(doc, body, []); });
        });
    }

    return { describe: describe, historyCells: historyCells, sourceDate: sourceDate, reasonText: reasonText, start: start };
}));
