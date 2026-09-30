/**
 * Initial filter selection of the dashboard — the DOM-free part, loaded
 * before dashboard.js (window.bscdDashboardDefaults) and unit-tested with
 * Node (tests/js/dashboard-defaults.test.js).
 *
 * On first load the STATUT filter starts with the "Contrats actifs" group
 * checked; the user can then uncheck/recheck freely.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdDashboardDefaults = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * The active statuses to pre-check, restricted to values the filter
     * actually offers (the backend rejects any other value with a 422).
     *
     * @param {Array<{value:string}>} statusOptions options of the STATUT filter
     * @param {string[]} activeStatuses the "Contrats actifs" group
     * @return {string[]}
     */
    function initialStatuses(statusOptions, activeStatuses) {
        var offered = (statusOptions || []).map(function (o) { return o.value; });
        return (activeStatuses || []).filter(function (s) { return offered.indexOf(s) !== -1; });
    }

    /**
     * True when the applied STATUT selection is exactly the "Contrats actifs"
     * group — all of it and nothing else, in any order. The KPI row then
     * hides "Total clients" (it would repeat "Clients actifs").
     *
     * @param {string[]|undefined} selected applied STATUT values
     * @param {string[]} activeStatuses the "Contrats actifs" group
     * @return {boolean}
     */
    function isExactlyActiveStatuses(selected, activeStatuses) {
        var sel = selected || [];
        var active = activeStatuses || [];
        if (active.length === 0 || sel.length !== active.length) return false;
        return active.every(function (s) { return sel.indexOf(s) !== -1; });
    }

    return { initialStatuses: initialStatuses, isExactlyActiveStatuses: isExactlyActiveStatuses };
}));
