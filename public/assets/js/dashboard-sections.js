/**
 * "Vue Postpaid" / "Vue Prepaid" — which rows each dashboard section shows,
 * the DOM-free part, loaded before dashboard.js (window.bscdDashboardSections)
 * and unit-tested with Node (tests/js/dashboard-sections.test.js).
 *
 * No second business rule: a section is the user's applied filters with the
 * existing "Type de compteur" (METER) filter narrowed to the section's
 * universe — the same universes the segmentation dropdown already uses
 * (dashboard.js meterUniverse(): POSTPAID + Compteurs Communicants ->
 * POSTPAID, PREPAID -> PREPAID). The server validates and applies it exactly
 * like a meter the user ticked (FilterCriteria / QueryBuilder), so KPIs,
 * charts, table and export of a section all come from the usual endpoints.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdDashboardSections = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Section key -> METER universe, in display order.
    var SECTIONS = [
        { key: 'postpaid', universe: 'POSTPAID' },
        { key: 'prepaid', universe: 'PREPAID' }
    ];

    function universeOf(key) {
        for (var i = 0; i < SECTIONS.length; i++) if (SECTIONS[i].key === key) return SECTIONS[i].universe;
        return null;
    }

    /**
     * The criteria of one section, or null when that section has no possible
     * row under the applied filters (the user's meters / segmentations all
     * belong to the other universe, or the snapshot offers no such meter) —
     * then nothing is requested and the section says "no data".
     *
     * @param {Object} applied          criteria sent for the whole page (state.applied)
     * @param {string} universe         'POSTPAID' | 'PREPAID'
     * @param {string[]} offeredMeters  METER values the filter offers (options.meters)
     * @param {function(string):?string} meterUniverse        dashboard.js rule
     * @param {function(string):?string} segmentationUniverse dashboard.js rule
     * @return {?Object}
     */
    function sectionCriteria(applied, universe, offeredMeters, meterUniverse, segmentationUniverse) {
        applied = applied || {};
        var picked = applied.meter && applied.meter.length ? applied.meter : (offeredMeters || []);
        var meters = picked.filter(function (m) { return meterUniverse(m) === universe; });
        if (!meters.length) return null;

        var c = {};
        Object.keys(applied).forEach(function (k) { c[k] = Array.isArray(applied[k]) ? applied[k].slice() : applied[k]; });
        c.meter = meters;

        if (applied.segmentation && applied.segmentation.length) {
            // A segment of the other universe can only match nothing here; one
            // in no known list is kept (the server decides, as today).
            var segs = applied.segmentation.filter(function (s) {
                var u = segmentationUniverse(s);
                return u === universe || u === null;
            });
            if (!segs.length) return null;
            c.segmentation = segs;
        }
        return c;
    }

    /**
     * Rows of the page filters that belong to no section (blank METER — no
     * option can select it): page count minus both section totals. null while
     * a figure is missing; never negative.
     */
    function unassigned(pageTotal, sectionTotals) {
        if (typeof pageTotal !== 'number') return null;
        var sum = 0;
        for (var i = 0; i < SECTIONS.length; i++) {
            var n = sectionTotals[SECTIONS[i].key];
            if (typeof n !== 'number') return null;
            sum += n;
        }
        return Math.max(0, pageTotal - sum);
    }

    return { SECTIONS: SECTIONS, universeOf: universeOf, sectionCriteria: sectionCriteria, unassigned: unassigned };
}));
