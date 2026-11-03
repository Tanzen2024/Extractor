/**
 * Light / dark theme — the navbar switch ([data-theme-toggle], layout/main.php).
 *
 * Dark = AdminLTE 3.2's own body.dark-mode (navbar, sidebar, cards, forms,
 * dropdowns, modals) + the app's overrides at the end of custom.css. The
 * choice is kept in localStorage ('bscd_theme'); with no choice the system
 * preference applies and is followed live. layout/main.php applies it
 * before the first paint (inline script), so a page never flashes white.
 *
 * Every switch fires `bscd:themechange` on document (detail.theme =
 * 'light' | 'dark') — dashboard.js re-draws its charts in the new colours.
 */
(function () {
    'use strict';

    var KEY = 'bscd_theme';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function saved() {
        try { var v = localStorage.getItem(KEY); return v === 'dark' || v === 'light' ? v : null; } catch (e) { return null; }
    }
    function isDark() { return document.body.classList.contains('dark-mode'); }

    function syncButtons() {
        var dark = isDark();
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
            var label = dark ? b.getAttribute('data-label-light') : b.getAttribute('data-label-dark');
            b.setAttribute('aria-pressed', dark ? 'true' : 'false');
            b.setAttribute('aria-label', label);
            b.title = label;
            var icon = b.querySelector('i');
            if (icon) icon.className = 'fas ' + (dark ? 'fa-sun' : 'fa-moon');
        });
    }

    function apply(theme, persist) {
        var dark = theme === 'dark';
        var changed = dark !== isDark();
        document.body.classList.toggle('dark-mode', dark);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        if (persist) { try { localStorage.setItem(KEY, theme); } catch (e) { /* private mode: this page only */ } }
        syncButtons();
        if (changed) {
            var ev;
            try { ev = new CustomEvent('bscd:themechange', { detail: { theme: theme } }); } catch (e) {
                ev = document.createEvent('CustomEvent'); ev.initCustomEvent('bscd:themechange', false, false, { theme: theme });
            }
            document.dispatchEvent(ev);
        }
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
        b.addEventListener('click', function () { apply(isDark() ? 'light' : 'dark', true); });
    });
    if (media) {
        var follow = function (e) { if (!saved()) apply(e.matches ? 'dark' : 'light', false); };
        if (media.addEventListener) media.addEventListener('change', follow); else if (media.addListener) media.addListener(follow);
    }
    syncButtons();

    window.bscdTheme = { isDark: isDark, set: function (theme) { apply(theme, true); } };
}());
