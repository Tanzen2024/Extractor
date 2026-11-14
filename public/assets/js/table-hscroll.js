/**
 * Wide-table helpers: floating horizontal scrollbar + sticky header.
 *
 * 1. Floating scrollbar — a wide table's native horizontal scrollbar sits at the
 *    bottom of its scroll container, so it scrolls out of view as soon as the page
 *    is scrolled up. A second, synchronised scrollbar is pinned to the bottom of
 *    the viewport for as long as that native scrollbar is off-screen.
 *
 * 2. Sticky header (opt-in, `data-sticky-head`) — CSS `position: sticky` on <th>
 *    cannot stick to the WINDOW here: the `overflow-x: auto` container becomes the
 *    sticky reference. So while the real <thead> is scrolled above the viewport, a
 *    clone of it is pinned to the top of the viewport, with column widths copied
 *    from the real header and the horizontal offset following the table. Clicks on
 *    the clone are forwarded to the matching real <th> (sorting keeps working
 *    through the page's own handlers). The clone is rebuilt whenever the real
 *    header is re-rendered (sort arrow, column toggles, new data).
 *
 * Usage: mark the element that scrolls horizontally (overflow-x: auto) with
 * `data-hscroll-sticky` (and `data-sticky-head` for the header); it is attached on
 * DOMContentLoaded. Programmatic use:
 *   var handle = BscdHScroll.attach(el);   // -> { update(), destroy() }
 *
 * The scrollbar is shown only when ALL of these hold:
 *   - the content overflows horizontally (scrollWidth > clientWidth);
 *   - the container is on screen (its top is above the viewport bottom);
 *   - its native scrollbar is NOT visible (container bottom below the viewport).
 * Because of the last rule, the bar disappears just before the native scrollbar,
 * the pagination and the page footer come into view, so it never covers them.
 *
 * The header clone is shown only while the real header is above the top of the
 * viewport (or under a fixed navbar) and some table rows are still on screen.
 *
 * Sync: each side copies its scrollLeft to the other only when the two values
 * differ, so the echo scroll event of the assignment is a no-op (no loop) and
 * sync is immediate (no visible lag). Layout recalculation (width, position,
 * visibility) is coalesced into one requestAnimationFrame per frame.
 */
(function (window, document) {
    'use strict';

    var instances = [];

    /** Bottom edge of a fixed/sticky top navbar, if any — the header clone sits under it. */
    function topOffset() {
        var nav = document.querySelector('.main-header');
        if (!nav) return 0;
        var pos = window.getComputedStyle(nav).position;
        if (pos !== 'fixed' && pos !== 'sticky') return 0;
        var r = nav.getBoundingClientRect();
        return r.top <= 0 && r.bottom > 0 ? r.bottom : 0;
    }

    function attach(scrollEl) {
        if (!scrollEl || scrollEl.__bscdHScroll) return scrollEl && scrollEl.__bscdHScroll;

        // ── floating scrollbar ───────────────────────────────────────
        var bar = document.createElement('div');
        bar.className = 'bscd-hscroll';
        bar.setAttribute('aria-hidden', 'true'); // duplicate control: keyboard/AT users use the table itself
        bar.tabIndex = -1;
        var inner = document.createElement('div');
        inner.className = 'bscd-hscroll__inner';
        bar.appendChild(inner);
        document.body.appendChild(bar);

        // ── sticky header clone (opt-in) ─────────────────────────────
        var table = scrollEl.querySelector('table');
        var head = null, headTable = null, headCols = null;
        var headVisible = false, headDirty = true, headWidthsKey = '';
        if (scrollEl.hasAttribute('data-sticky-head') && table && table.tHead) {
            head = document.createElement('div');
            head.className = 'bscd-stickyhead';
            head.setAttribute('aria-hidden', 'true'); // visual duplicate of the real header
            headTable = document.createElement('table');
            headTable.className = table.className;
            head.appendChild(headTable);
            document.body.appendChild(head);
            head.addEventListener('click', onHeadClick);
        }

        var frame = 0;
        var visible = false;

        function onTableScroll() {
            if (Math.abs(bar.scrollLeft - scrollEl.scrollLeft) >= 1) bar.scrollLeft = scrollEl.scrollLeft;
            if (headVisible) headTable.style.transform = 'translateX(' + (-scrollEl.scrollLeft) + 'px)';
        }
        function onBarScroll() {
            if (Math.abs(scrollEl.scrollLeft - bar.scrollLeft) >= 1) scrollEl.scrollLeft = bar.scrollLeft;
        }

        /** Click on the clone -> click on the same cell of the real header (sort handlers live there). */
        function onHeadClick(e) {
            var cell = e.target.closest && e.target.closest('th, td');
            if (!cell || !table.tHead) return;
            var row = cell.parentNode;
            var r = Array.prototype.indexOf.call(headTable.tHead.rows, row);
            var real = r >= 0 && table.tHead.rows[r] && table.tHead.rows[r].cells[cell.cellIndex];
            if (real) real.click();
        }

        function rebuildHead() {
            var clone = table.tHead.cloneNode(true);
            clone.removeAttribute('id');
            clone.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
            headCols = document.createElement('colgroup');
            headTable.innerHTML = '';
            headTable.appendChild(headCols);
            headTable.appendChild(clone);
            headDirty = false;
            headWidthsKey = '';
        }

        function setVisible(on) {
            if (on === visible) return;
            visible = on;
            bar.classList.toggle('is-visible', on);
        }
        function setHeadVisible(on) {
            if (on === headVisible) return;
            headVisible = on;
            head.classList.toggle('is-visible', on);
        }

        function layoutHead(rect, left) {
            var thead = table.tHead;
            var topY = topOffset();
            var headRect = thead ? thead.getBoundingClientRect() : null;
            var tableRect = table.getBoundingClientRect();
            var show = !!headRect && rect.width > 0 && headRect.height > 0 &&
                headRect.top < topY &&                              // real header scrolled away
                tableRect.bottom - headRect.height > topY;         // rows still on screen
            if (!show) { setHeadVisible(false); return; }

            if (headDirty) rebuildHead();

            // Reads first (real column widths), then writes — one layout pass.
            var cells = thead.rows[0] ? thead.rows[0].cells : [];
            var widths = [];
            for (var i = 0; i < cells.length; i++) widths.push(cells[i].getBoundingClientRect().width);
            var key = widths.join(',') + '|' + tableRect.width;
            if (key !== headWidthsKey) {
                headWidthsKey = key;
                headCols.innerHTML = widths.map(function (w) { return '<col style="width:' + w + 'px">'; }).join('');
                headTable.style.width = tableRect.width + 'px';
            }
            head.style.top = topY + 'px';
            head.style.left = Math.round(left) + 'px';
            head.style.width = scrollEl.clientWidth + 'px';
            headTable.style.transform = 'translateX(' + (-scrollEl.scrollLeft) + 'px)';
            setHeadVisible(true);
        }

        function layout() {
            frame = 0;
            if (!scrollEl.isConnected) { destroy(); return; }

            var rect = scrollEl.getBoundingClientRect();
            var cs = window.getComputedStyle(scrollEl);
            var left = rect.left + (parseFloat(cs.borderLeftWidth) || 0);
            if (head) layoutHead(rect, left);

            var overflow = scrollEl.scrollWidth - scrollEl.clientWidth > 1;
            var vh = window.innerHeight || document.documentElement.clientHeight;
            var shown = rect.width > 0 && rect.height > 0;          // not display:none
            // Native scrollbar sits in the bottom (offsetHeight - clientHeight) px of the container.
            var nativeBarH = scrollEl.offsetHeight - scrollEl.clientHeight;
            var nativeVisible = rect.bottom - nativeBarH <= vh;
            var onScreen = rect.top < vh - 40;                      // at least a sliver of the table is visible

            if (!(overflow && shown && onScreen && !nativeVisible)) { setVisible(false); return; }

            // Match the container's inner box exactly so both scroll ranges are identical.
            bar.style.left = Math.round(left) + 'px';
            bar.style.width = scrollEl.clientWidth + 'px';
            inner.style.width = scrollEl.scrollWidth + 'px';
            setVisible(true);
            // After a width change the browser may clamp scrollLeft: re-align.
            if (Math.abs(bar.scrollLeft - scrollEl.scrollLeft) >= 1) bar.scrollLeft = scrollEl.scrollLeft;
        }

        function schedule() {
            if (!frame) frame = window.requestAnimationFrame(layout);
        }

        scrollEl.addEventListener('scroll', onTableScroll, { passive: true });
        bar.addEventListener('scroll', onBarScroll, { passive: true });
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        // Sidebar collapse (AdminLTE) shifts the container without resizing the window.
        document.addEventListener('collapsed.lte.pushmenu', schedule);
        document.addEventListener('shown.lte.pushmenu', schedule);
        document.addEventListener('transitionend', schedule);

        var ro = null;
        if (typeof window.ResizeObserver === 'function') {
            // Container: viewport/sidebar width changes. Table: new rows/columns (pagination,
            // filters, column toggles, loading) change its scroll width without resizing the container.
            ro = new window.ResizeObserver(schedule);
            ro.observe(scrollEl);
            if (scrollEl.firstElementChild) ro.observe(scrollEl.firstElementChild);
        }
        var mo = null;
        if (typeof window.MutationObserver === 'function') {
            // Content re-rendered (rows, header labels, sort arrow): re-measure, and re-clone the
            // header when it changed.
            mo = new window.MutationObserver(function (records) {
                if (head && !headDirty) {
                    for (var i = 0; i < records.length; i++) {
                        var t = records[i].target;
                        if (table.tHead && (table.tHead === t || table.tHead.contains(t))) { headDirty = true; break; }
                    }
                }
                schedule();
            });
            mo.observe(scrollEl, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style'] });
        }

        function destroy() {
            if (frame) window.cancelAnimationFrame(frame);
            frame = 0;
            scrollEl.removeEventListener('scroll', onTableScroll);
            bar.removeEventListener('scroll', onBarScroll);
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            document.removeEventListener('collapsed.lte.pushmenu', schedule);
            document.removeEventListener('shown.lte.pushmenu', schedule);
            document.removeEventListener('transitionend', schedule);
            if (ro) ro.disconnect();
            if (mo) mo.disconnect();
            if (bar.parentNode) bar.parentNode.removeChild(bar);
            if (head) {
                head.removeEventListener('click', onHeadClick);
                if (head.parentNode) head.parentNode.removeChild(head);
            }
            delete scrollEl.__bscdHScroll;
            var i = instances.indexOf(handle);
            if (i !== -1) instances.splice(i, 1);
        }

        var handle = { update: schedule, destroy: destroy, element: scrollEl, bar: bar, head: head };
        scrollEl.__bscdHScroll = handle;
        instances.push(handle);
        schedule();
        return handle;
    }

    function attachAll(root) {
        (root || document).querySelectorAll('[data-hscroll-sticky]').forEach(function (el) { attach(el); });
    }

    window.BscdHScroll = {
        attach: attach,
        attachAll: attachAll,
        destroyAll: function () { instances.slice().forEach(function (h) { h.destroy(); }); },
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { attachAll(); });
    else attachAll();
})(window, document);
