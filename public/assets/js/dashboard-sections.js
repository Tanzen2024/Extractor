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
     * "Total clients actifs" above the two views: the sum of the section
     * totals already received (a row has one METER, so the sections never
     * overlap). null while a figure is missing: a partial sum is never shown.
     */
    function sectionsTotal(sectionTotals) {
        var sum = 0;
        for (var i = 0; i < SECTIONS.length; i++) {
            var n = sectionTotals[SECTIONS[i].key];
            if (typeof n !== 'number') return null;
            sum += n;
        }
        return sum;
    }

    /**
     * The /dashboard/stats breakdown of the 6 Défaillant profile, each
     * component with its share of the Défaillant total.
     *
     * @param {?{total:number, components:Array<{value:string,count:number}>, unassigned:number}} data
     * @return {{total:number, unassigned:number, rows:Array<{value:string,count:number,share:number}>}}
     */
    function failureBreakdown(data) {
        var total = (data && Number(data.total)) || 0;
        return {
            total: total,
            unassigned: (data && Number(data.unassigned)) || 0,
            rows: ((data && data.components) || []).map(function (c) {
                var count = Number(c.count) || 0;
                return { value: c.value, count: count, share: total ? count / total * 100 : 0 };
            })
        };
    }

    /**
     * Criteria of the detail table with one component of the breakdown
     * selected: an extra narrowing of the table and its export, kept out of
     * the page filters. criteria null (no possible row) stays null.
     */
    function withFailureFilter(criteria, value) {
        if (!criteria || !value) return criteria;
        var c = {};
        Object.keys(criteria).forEach(function (k) { c[k] = criteria[k]; });
        c.failure_subcategory = [value];
        return c;
    }

    /**
     * Criteria of the detail table with one profile of a section's
     * "Répartition par segmentation" selected: the section's criteria with the
     * existing Segmentation filter set to that profile — validated and applied
     * by the server exactly like a ticked segment (no second rule), and kept
     * out of the page filters. criteria null (no possible row) stays null.
     */
    function withSegmentation(criteria, value) {
        if (!criteria || !value) return criteria;
        var c = {};
        Object.keys(criteria).forEach(function (k) { c[k] = criteria[k]; });
        c.segmentation = [value];
        return c;
    }

    /**
     * Whether a selected profile still lies inside its section after new page
     * filters: the section has rows and, when the Segmentation filter holds
     * values, this profile is one of them. Otherwise the selection is dropped
     * (never a table wider than the page filters).
     */
    function profileInScope(criteria, value) {
        if (!criteria || !value) return false;
        var segs = criteria.segmentation || [];
        return !segs.length || segs.indexOf(value) !== -1;
    }

    /**
     * Plotted segmentation bars of a section split between its official
     * profiles and the rest (customers the rule
     * leaves unclassified, or a value in no official list).
     *
     * @param {Array<{value:string,count:number}>} pairs
     * @param {string[]} profiles the section's official profiles
     * @return {{classified:number, unclassified:number}}
     */
    function profileTotals(pairs, profiles) {
        var out = { classified: 0, unclassified: 0 };
        (pairs || []).forEach(function (p) {
            var n = Number(p.count) || 0;
            if (profiles.indexOf(p.value) !== -1) out.classified += n; else out.unclassified += n;
        });
        return out;
    }

    return {
        SECTIONS: SECTIONS, universeOf: universeOf, sectionCriteria: sectionCriteria, sectionsTotal: sectionsTotal,
        failureBreakdown: failureBreakdown, withFailureFilter: withFailureFilter,
        withSegmentation: withSegmentation, profileInScope: profileInScope, profileTotals: profileTotals
    };
}));
