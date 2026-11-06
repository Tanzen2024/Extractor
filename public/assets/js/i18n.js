/**
 * Browser-side translations — the JS counterpart of CodeIgniter's lang().
 *
 *   bscdI18n.locale              'fr' | 'en'
 *   bscdI18n.t(key, params)      'export.running' -> text; {name} placeholders
 *                                replaced from params. A missing key returns the
 *                                key itself (visible, and caught by tests/js/i18n.test.js).
 *   bscdI18n.tn(key, n, params)  plural: key.one / key.other by the locale's rule,
 *                                {count} = n formatted.
 *   bscdI18n.number(n)           integer with the locale's grouping (1 234 / 1,234)
 *   bscdI18n.decimal(n, digits)  fixed decimals with the locale's separator (70,0 / 70.0)
 *
 * Catalogs: public/assets/i18n/{fr,en}.json. In the browser the active one is
 * inlined by the layout (i18n_script_tag(): window.BSCD_I18N); under Node
 * (unit tests) the French catalog — the default language — is required.
 */
(function (root, factory) {
    var payload = root && root.BSCD_I18N;
    if (!payload && typeof require === 'function') {
        payload = { locale: 'fr', messages: require('../i18n/fr.json') };
    }
    var api = factory(payload || { locale: 'fr', messages: {} });
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdI18n = api;
    }
}(typeof self !== 'undefined' ? self : this, function (payload) {
    'use strict';

    var locale = payload.locale || 'fr';
    var messages = payload.messages || {};
    var tag = (messages.meta && messages.meta.numberLocale) || (locale === 'en' ? 'en-GB' : 'fr-FR');

    function lookup(key) {
        var node = messages;
        var parts = String(key).split('.');
        for (var i = 0; i < parts.length; i++) {
            if (node === null || typeof node !== 'object' || !(parts[i] in node)) return undefined;
            node = node[parts[i]];
        }
        return node;
    }

    function interpolate(text, params) {
        if (!params) return text;
        return text.replace(/\{(\w+)\}/g, function (m, name) {
            return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : m;
        });
    }

    function t(key, params) {
        var value = lookup(key);
        if (value !== null && typeof value === 'object') return value; // a list (months) or a group (column labels)
        if (typeof value !== 'string') return key;
        return interpolate(value, params);
    }

    // French: 0 and 1 are singular; English: only 1.
    function isOne(n) { return locale === 'fr' ? Math.abs(n) < 2 : n === 1; }

    function number(n) {
        try { return new Intl.NumberFormat(tag).format(Number(n) || 0); } catch (e) { return String(Number(n) || 0); }
    }

    function decimal(n, digits) {
        var fixed = (Number(n) || 0).toFixed(digits === undefined ? 1 : digits);
        return lookup('meta.decimalSeparator') === ',' ? fixed.replace('.', ',') : fixed;
    }

    function tn(key, n, params) {
        var p = {};
        Object.keys(params || {}).forEach(function (k) { p[k] = params[k]; });
        p.count = number(n);
        return t(key + (isOne(Number(n) || 0) ? '.one' : '.other'), p);
    }


    return { locale: locale, t: t, tn: tn, number: number, decimal: decimal };
}));
