/**
 * Which base the dashboard's figures come from — the DOM-free part, loaded
 * before dashboard.js (window.bscdDashboardSource) and unit-tested with Node
 * (tests/js/dashboard-source.test.js).
 *
 * The row count ("Total", export "Résultats") comes from the active snapshot
 * — the file the exports read — via GET /dashboard/count. The KPI ratios,
 * charts and table still come from live Oracle. These helpers describe the
 * snapshot from its real metadata only, and say when both bases disagree.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdDashboardSource = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function fmt(n) { return (Number(n) || 0).toLocaleString('fr-FR'); }

    /**
     * "2026-09-27 04:32:49" or "2026-09-27T18:38:26+01:00" -> "27/09/2026 04:32"
     * (the wall-clock time as recorded — no timezone conversion). Anything
     * else -> null: a date is never guessed.
     */
    function dateTimeFr(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(typeof value === 'string' ? value.trim() : '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : null;
    }

    /** One-line description of the snapshot in use, from its metadata only. */
    function sourceNote(snapshot) {
        if (!snapshot || typeof snapshot.rows !== 'number') return '';
        var parts = ['Référentiel : ' + fmt(snapshot.rows) + ' lignes'];
        var data = dateTimeFr(snapshot.sourceUpdatedAt);
        var extracted = dateTimeFr(snapshot.generatedAt);
        if (data) parts.push('données Oracle du ' + data);
        if (extracted) parts.push('extraites le ' + extracted);
        return parts.join(' · ');
    }

    /**
     * Text of the warning shown when live Oracle (KPI ratios, charts, table)
     * and the snapshot (total, exports) hold a different number of rows for
     * the same filters; '' when they agree or a figure is missing.
     */
    function divergenceNote(snapshotCount, liveTotal) {
        if (typeof snapshotCount !== 'number' || typeof liveTotal !== 'number' || snapshotCount === liveTotal) return '';
        var diff = Math.abs(liveTotal - snapshotCount);
        return 'La base Oracle en direct (indicateurs détaillés, graphiques, tableau) compte ' + fmt(liveTotal) +
            ' lignes pour ces filtres, le référentiel utilisé pour le total et les exports ' + fmt(snapshotCount) +
            ' (écart ' + fmt(diff) + '). Les exports portent sur le référentiel ; il sera aligné au prochain rafraîchissement.';
    }

    return { dateTimeFr: dateTimeFr, sourceNote: sourceNote, divergenceNote: divergenceNote };
}));
