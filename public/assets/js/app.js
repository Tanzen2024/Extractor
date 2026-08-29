/**
 * MARKETING (MI) - shared front-end helpers.
 *
 * bscdFetch wraps fetch() and:
 *  - attaches the CSRF token CodeIgniter expects on every state-changing request;
 *  - reads the fresh CSRF token back off every response (X-CSRF-TOKEN header,
 *    set by App\Filters\CsrfTokenHeaderFilter) and updates the page's <meta>,
 *    so several POSTs from one page keep working even though
 *    Config\Security::$regenerate rotates the token after each one.
 */
function bscdFetch(url, options = {}) {
    const meta = document.querySelector('meta[name="csrf-token-value"]');
    const tokenValue = meta ? meta.content : null;

    const opts = { ...options };
    opts.headers = { 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers || {}) };

    if (tokenValue && opts.method && opts.method.toUpperCase() !== 'GET') {
        // Matches Config\Security::$headerName ('X-CSRF-TOKEN').
        opts.headers['X-CSRF-TOKEN'] = tokenValue;
    }

    return fetch(url, opts).then((response) => {
        const fresh = response.headers.get('X-CSRF-TOKEN');
        if (fresh && meta) {
            meta.content = fresh;
        }
        return response.json();
    });
}

/**
 * Minimal AdminLTE-compatible sidebar behaviour (pushmenu collapse + treeview
 * submenus). Implemented directly instead of pulling the full adminlte.js
 * bundle, since only these two interactions are used in this layout.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-widget="pushmenu"]').forEach((el) => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            document.body.classList.toggle('sidebar-collapse');
        });
    });

    document.querySelectorAll('[data-widget="treeview"] > .nav-item > a.nav-link').forEach((link) => {
        const parentItem = link.closest('.nav-item');

        if (!parentItem || !parentItem.querySelector('.nav-treeview')) {
            return;
        }

        link.addEventListener('click', function (e) {
            e.preventDefault();
            parentItem.classList.toggle('menu-open');
        });
    });
});
