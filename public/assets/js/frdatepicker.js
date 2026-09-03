/**
 * frdatepicker — locale-independent French date field.
 *
 * Replaces <input type="date"> (whose visible format follows the OS/browser
 * locale — en-US shows MM/DD/YYYY on a US Windows). Here the presentation is
 * owned by the app:
 *
 *   visible  <input type="text">   ->  always dd/mm/yyyy   (what the user sees / types)
 *   hidden   <input type="hidden"> ->  always yyyy-mm-dd    (what the form submits — unchanged wire format)
 *
 * The calendar popup has three views — days / months / years — reached from
 * the header "[ Mois ] [ Année ]" buttons, plus an "aller à l'année" number
 * input in the years view. A far-off date (15/01/2015 while we are in 2026)
 * is four clicks away, never month-by-month.
 *
 * No dependency (FontAwesome is already loaded for the calendar icon).
 *
 * Markup expected:
 *   <span class="frdate" data-frdate data-min="2019-01-01" data-max="2027-12-31">
 *     <input type="text" class="frdate__input" id="X_d" placeholder="jj/mm/aaaa"
 *            inputmode="numeric" maxlength="10" autocomplete="off">
 *     <button type="button" class="frdate__toggle" tabindex="-1" aria-label="Ouvrir le calendrier">
 *       <i class="fas fa-calendar-day" aria-hidden="true"></i>
 *     </button>
 *     <input type="hidden" id="X" name="date_from">
 *     <span class="frdate__error" role="alert" hidden></span>
 *   </span>
 *
 * Public API (window.frdate):
 *   frdate.enhance(root?)               — (re)scan for [data-frdate] under root (default: document)
 *   frdate.clear(hiddenInput)           — empty the field (visible + hidden + error)
 *   frdate.set(hiddenInput, isoOrEmpty) — set from an ISO value
 *   frdate.setBounds(hiddenInput, minIso, maxIso)
 *   frdate.fromIso(iso) -> 'dd/mm/yyyy' | ''
 *   frdate.toIso('dd/mm/yyyy') -> 'yyyy-mm-dd' | null
 */
(function (window, document) {
    'use strict';

    var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    var MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
        'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    var DOW = ['L', 'M', 'M', 'J', 'V', 'S', 'D']; // Monday first

    // 12-year block aligned so the block always contains `y` (…, 2016-2027, 2028-2039, …).
    function yearBlockStart(y) { return y - (((y % 12) + 12) % 12); }

    var ERR_FORMAT = 'Date invalide (format attendu : jj/mm/aaaa).';
    var ERR_RANGE  = 'Date hors de la période disponible.';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    // 'dd/mm/yyyy' -> {y,m,d} | null. Strict: real calendar day only
    // (rejects 31/02/2026, 29/02/2027, 00/01/2026, 01/13/2026, ...).
    function parseFr(str) {
        var m = /^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s*$/.exec(String(str || ''));
        if (!m) { return null; }
        var d = +m[1], mo = +m[2], y = +m[3];
        if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 1900 || y > 2999) { return null; }
        var dt = new Date(Date.UTC(y, mo - 1, d));
        if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) { return null; }
        return { y: y, m: mo, d: d };
    }

    function toIso(str) {
        var p = parseFr(str);
        return p ? p.y + '-' + pad(p.m) + '-' + pad(p.d) : null;
    }

    // 'yyyy-mm-dd' (or a longer ISO string) -> 'dd/mm/yyyy' | ''
    function fromIso(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }

    function isoToParts(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? { y: +m[1], m: +m[2], d: +m[3] } : null;
    }

    function cmpIso(a, b) { return a < b ? -1 : (a > b ? 1 : 0); }

    // ── one field ────────────────────────────────────────────────────
    function Field(root) {
        this.root   = root;
        this.input  = root.querySelector('.frdate__input');
        this.hidden = root.querySelector('input[type="hidden"]');
        this.toggle = root.querySelector('.frdate__toggle');
        this.errBox = root.querySelector('.frdate__error');
        this.cal    = null;
        this.viewY  = 0;
        this.viewM  = 0;

        if (!this.input || !this.hidden) { return; }
        root.__frdate = this;
        this.hidden.__frdate = this;

        // seed the visible field from an ISO value rendered server-side
        if (this.hidden.value) {
            this.input.value = fromIso(this.hidden.value);
        }

        var self = this;
        this.input.addEventListener('input', function () { self.mask(); });
        this.input.addEventListener('change', function () { self.commit(); });
        this.input.addEventListener('blur', function () { self.commit(); });
        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { self.commit(); }
            else if (e.key === 'Escape') { self.closeCal(); }
        });
        if (this.toggle) {
            this.toggle.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                self.cal ? self.closeCal() : self.openCal();
            });
        }
    }

    Field.prototype.min = function () { return this.root.getAttribute('data-min') || ''; };
    Field.prototype.max = function () { return this.root.getAttribute('data-max') || ''; };

    Field.prototype.showError = function (msg) {
        if (!this.errBox) { return; }
        this.errBox.textContent = msg;
        this.errBox.hidden = false;
        this.input.classList.add('is-invalid');
        this.input.setAttribute('aria-invalid', 'true');
    };
    Field.prototype.clearError = function () {
        if (this.errBox) { this.errBox.hidden = true; this.errBox.textContent = ''; }
        this.input.classList.remove('is-invalid');
        this.input.removeAttribute('aria-invalid');
    };

    // live digit mask: "14012026" -> "14/01/2026"
    Field.prototype.mask = function () {
        var digits = this.input.value.replace(/\D/g, '').slice(0, 8);
        var out = digits.slice(0, 2);
        if (digits.length >= 3) { out += '/' + digits.slice(2, 4); }
        if (digits.length >= 5) { out += '/' + digits.slice(4, 8); }
        this.input.value = out;
        if (this.errBox && !this.errBox.hidden) { this.clearError(); }
    };

    // validate the typed value, write the hidden ISO (or clear it)
    Field.prototype.commit = function () {
        var raw = this.input.value.trim();

        if (raw === '') {
            this.input.value = '';
            this.setHidden('');
            this.clearError();
            return true;
        }

        var iso = toIso(raw);
        if (!iso) {
            this.setHidden('');
            this.showError(ERR_FORMAT);
            return false;
        }
        if ((this.min() && cmpIso(iso, this.min()) < 0) || (this.max() && cmpIso(iso, this.max()) > 0)) {
            this.setHidden('');
            this.showError(ERR_RANGE);
            return false;
        }

        this.input.value = fromIso(iso); // normalise (e.g. "1/8/2026" -> "01/08/2026")
        this.setHidden(iso);
        this.clearError();
        return true;
    };

    Field.prototype.setHidden = function (iso) {
        if (this.hidden.value === iso) { return; }
        this.hidden.value = iso;
        this.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    };

    // programmatic — used by dashboard.js reset paths
    Field.prototype.setFromIso = function (iso) {
        iso = iso || '';
        this.input.value = iso ? fromIso(iso) : '';
        this.setHidden(iso ? (isoToParts(iso) ? iso : '') : '');
        this.clearError();
    };
    Field.prototype.reset = function () {
        this.input.value = '';
        this.setHidden('');
        this.clearError();
    };

    // ── calendar popup ───────────────────────────────────────────────
    // Three views share one popup: 'days' (grid of days), 'months' (Jan…Dec),
    // 'years' (12-year block + "aller à l'année" input). The header buttons
    // switch views, so reaching 15/01/2015 from 2026 is: year → 2015 → janvier
    // → 15 (four clicks, never month-by-month). minDate/maxDate still come
    // only from data-min / data-max (the real DATE_AB range) — out-of-range
    // days, months and years are shown disabled, exactly as before.
    Field.prototype.openCal = function () {
        var seed = isoToParts(this.hidden.value) || isoToParts(toIso(this.input.value) || '') || todayParts();
        this.viewY = seed.y;
        this.viewM = seed.m; // 1-12
        this.calView = 'days';
        this.yearBlock = yearBlockStart(seed.y);
        this.cal = document.createElement('div');
        this.cal.className = 'frdate__cal';
        this.root.appendChild(this.cal);
        this.renderCal();

        var self = this;
        this._outside = function (e) { if (!self.root.contains(e.target)) { self.closeCal(); } };
        setTimeout(function () { document.addEventListener('click', self._outside); }, 0);
    };

    Field.prototype.closeCal = function () {
        if (this._outside) { document.removeEventListener('click', this._outside); this._outside = null; }
        if (this.cal && this.cal.parentNode) { this.cal.parentNode.removeChild(this.cal); }
        this.cal = null;
    };

    Field.prototype.renderCal = function () {
        if (this.calView === 'years') { return this.renderYears(); }
        if (this.calView === 'months') { return this.renderMonths(); }
        return this.renderDays();
    };

    // Year range offered by the picker: bounded by data-min / data-max when
    // present (the real DATE_AB span), otherwise a generous default — never an
    // arbitrary "current year ± N".
    Field.prototype.yearRange = function () {
        var minIso = this.min(), maxIso = this.max();
        return {
            lo: minIso ? +minIso.slice(0, 4) : 1900,
            hi: maxIso ? +maxIso.slice(0, 4) : todayParts().y + 10
        };
    };

    Field.prototype.renderDays = function () {
        var y = this.viewY, mo = this.viewM;
        var first = new Date(Date.UTC(y, mo - 1, 1));
        var startDow = (first.getUTCDay() + 6) % 7; // Monday = 0
        var daysInMonth = new Date(Date.UTC(y, mo, 0)).getUTCDate();
        var minIso = this.min(), maxIso = this.max();
        var selIso = this.hidden.value;
        var todayIso = todayIsoStr();

        var head =
            '<div class="frdate__cal-head">' +
            '  <button type="button" class="frdate__nav" data-nav="-1" aria-label="Mois précédent">&lsaquo;</button>' +
            '  <span class="frdate__cal-switch">' +
            '    <button type="button" class="frdate__pick" data-view="months" aria-label="Choisir le mois">' + capitalise(MONTHS[mo - 1]) + '</button>' +
            '    <button type="button" class="frdate__pick" data-view="years" aria-label="Choisir l\'année">' + y + '</button>' +
            '  </span>' +
            '  <button type="button" class="frdate__nav" data-nav="1" aria-label="Mois suivant">&rsaquo;</button>' +
            '</div>';

        var dow = '<div class="frdate__cal-dow">' + DOW.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>';

        var cells = '';
        for (var i = 0; i < startDow; i++) { cells += '<span class="frdate__cal-empty"></span>'; }
        for (var d = 1; d <= daysInMonth; d++) {
            var iso = y + '-' + pad(mo) + '-' + pad(d);
            var disabled = (minIso && iso < minIso) || (maxIso && iso > maxIso);
            var cls = 'frdate__cal-day'
                + (iso === selIso ? ' is-selected' : '')
                + (iso === todayIso ? ' is-today' : '')
                + (disabled ? ' is-disabled' : '');
            cells += '<button type="button" class="' + cls + '" data-iso="' + iso + '"' + (disabled ? ' disabled' : '') + '>' + d + '</button>';
        }

        this.cal.innerHTML = head + dow + '<div class="frdate__cal-grid">' + cells + '</div>';

        var self = this;
        this.cal.querySelectorAll('[data-nav]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                self.viewM += parseInt(b.getAttribute('data-nav'), 10);
                if (self.viewM < 1) { self.viewM = 12; self.viewY--; }
                else if (self.viewM > 12) { self.viewM = 1; self.viewY++; }
                self.renderCal();
            });
        });
        this.bindViewSwitch();
        this.cal.querySelectorAll('.frdate__cal-day:not(.is-disabled)').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                self.input.value = fromIso(b.getAttribute('data-iso'));
                self.commit();
                self.closeCal();
                self.input.focus();
            });
        });
    };

    Field.prototype.renderMonths = function () {
        var y = this.viewY;
        var minIso = this.min(), maxIso = this.max();
        var selP = isoToParts(this.hidden.value);
        var t = todayParts();

        var head =
            '<div class="frdate__cal-head">' +
            '  <button type="button" class="frdate__nav" data-nav="-1" aria-label="Année précédente">&lsaquo;</button>' +
            '  <button type="button" class="frdate__pick" data-view="years" aria-label="Choisir l\'année">' + y + '</button>' +
            '  <button type="button" class="frdate__nav" data-nav="1" aria-label="Année suivante">&rsaquo;</button>' +
            '</div>';

        var grid = '';
        for (var mo = 1; mo <= 12; mo++) {
            var monthStart = y + '-' + pad(mo) + '-01';
            var monthEnd = y + '-' + pad(mo) + '-' + pad(new Date(Date.UTC(y, mo, 0)).getUTCDate());
            var disabled = (minIso && monthEnd < minIso) || (maxIso && monthStart > maxIso);
            var cls = 'frdate__cal-month'
                + (selP && selP.y === y && selP.m === mo ? ' is-selected' : '')
                + (t.y === y && t.m === mo ? ' is-today' : '')
                + (disabled ? ' is-disabled' : '');
            grid += '<button type="button" class="' + cls + '" data-mo="' + mo + '"'
                + (disabled ? ' disabled' : '') + ' title="' + capitalise(MONTHS[mo - 1]) + '">'
                + capitalise(MONTHS_SHORT[mo - 1]) + '</button>';
        }

        this.cal.innerHTML = head + '<div class="frdate__cal-months">' + grid + '</div>';

        var self = this;
        this.cal.querySelectorAll('[data-nav]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                self.viewY += parseInt(b.getAttribute('data-nav'), 10);
                self.renderCal();
            });
        });
        this.bindViewSwitch();
        this.cal.querySelectorAll('.frdate__cal-month:not(.is-disabled)').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                self.viewM = parseInt(b.getAttribute('data-mo'), 10);
                self.calView = 'days';
                self.renderCal();
            });
        });
    };

    Field.prototype.renderYears = function () {
        var start = this.yearBlock;
        var range = this.yearRange();
        var selP = isoToParts(this.hidden.value);
        var t = todayParts();

        var head =
            '<div class="frdate__cal-head">' +
            '  <button type="button" class="frdate__nav" data-nav="-12" aria-label="Années précédentes">&lsaquo;</button>' +
            '  <span class="frdate__cal-title">' + start + ' – ' + (start + 11) + '</span>' +
            '  <button type="button" class="frdate__nav" data-nav="12" aria-label="Années suivantes">&rsaquo;</button>' +
            '</div>';

        var grid = '';
        for (var i = 0; i < 12; i++) {
            var yr = start + i;
            var disabled = yr < range.lo || yr > range.hi;
            var cls = 'frdate__cal-year'
                + (selP && selP.y === yr ? ' is-selected' : '')
                + (t.y === yr ? ' is-today' : '')
                + (disabled ? ' is-disabled' : '');
            grid += '<button type="button" class="' + cls + '" data-y="' + yr + '"' + (disabled ? ' disabled' : '') + '>' + yr + '</button>';
        }

        var jump =
            '<form class="frdate__year-jump">' +
            '  <input type="number" class="frdate__year-input" inputmode="numeric" min="1900" max="2999" placeholder="Année" aria-label="Aller à une année précise">' +
            '  <button type="submit" class="frdate__year-go">Aller</button>' +
            '</form>';

        this.cal.innerHTML = head + '<div class="frdate__cal-years">' + grid + '</div>' + jump;

        var self = this;
        this.cal.querySelectorAll('[data-nav]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                self.yearBlock += parseInt(b.getAttribute('data-nav'), 10);
                self.renderCal();
            });
        });
        this.cal.querySelectorAll('.frdate__cal-year:not(.is-disabled)').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                self.viewY = parseInt(b.getAttribute('data-y'), 10);
                self.calView = 'months';
                self.renderCal();
            });
        });

        var form = this.cal.querySelector('.frdate__year-jump');
        var inp  = this.cal.querySelector('.frdate__year-input');
        form.addEventListener('click', function (e) { e.stopPropagation(); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var yr = parseInt(inp.value, 10);
            if (!yr || yr < 1900 || yr > 2999) { inp.classList.add('is-invalid'); inp.focus(); return; }
            self.viewY = yr;
            self.yearBlock = yearBlockStart(yr);
            self.calView = 'months';
            self.renderCal();
        });
    };

    // Wires the header "[ Mois ] [ Année ]" buttons (present in the days and
    // months views) to the view switch.
    Field.prototype.bindViewSwitch = function () {
        var self = this;
        this.cal.querySelectorAll('.frdate__pick').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var v = b.getAttribute('data-view');
                if (v === 'years') { self.yearBlock = yearBlockStart(self.viewY); }
                self.calView = v;
                self.renderCal();
            });
        });
    };

    function todayParts() {
        var n = new Date();
        return { y: n.getFullYear(), m: n.getMonth() + 1, d: n.getDate() };
    }
    function todayIsoStr() {
        var t = todayParts();
        return t.y + '-' + pad(t.m) + '-' + pad(t.d);
    }
    function capitalise(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    // ── bootstrap / public API ───────────────────────────────────────
    function enhance(root) {
        (root || document).querySelectorAll('[data-frdate]').forEach(function (el) {
            if (el.getAttribute('data-frdate-ready')) { return; }
            el.setAttribute('data-frdate-ready', '1');
            new Field(el);
        });
    }

    function fieldOf(hiddenInput) {
        return hiddenInput && hiddenInput.__frdate ? hiddenInput.__frdate : null;
    }

    window.frdate = {
        enhance: enhance,
        fromIso: fromIso,
        toIso: toIso,
        clear: function (hiddenInput) { var f = fieldOf(hiddenInput); if (f) { f.reset(); } else if (hiddenInput) { hiddenInput.value = ''; } },
        set: function (hiddenInput, iso) { var f = fieldOf(hiddenInput); if (f) { f.setFromIso(iso); } else if (hiddenInput) { hiddenInput.value = iso || ''; } },
        setBounds: function (hiddenInput, minIso, maxIso) {
            var f = fieldOf(hiddenInput);
            if (!f) { return; }
            if (minIso != null) { f.root.setAttribute('data-min', minIso || ''); }
            if (maxIso != null) { f.root.setAttribute('data-max', maxIso || ''); }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
})(window, document);
