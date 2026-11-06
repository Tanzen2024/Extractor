/**
 * Permanent value labels for the dashboard charts — a Chart.js 4 plugin, no
 * extra dependency. Registered once by dashboard.js, so every chart (and any
 * future one) shows its values without hovering; the tooltips are untouched
 * and stay the detailed view. Labels are drawn on the canvas itself, so they
 * are part of any image capture of a chart (toBase64Image, screenshot).
 *
 * Placement is DOM-free (plan* functions) and unit-tested with Node
 * (tests/js/chart-value-labels.test.js):
 *   - bars: past the bar end; inside it, in a contrasting colour, when the
 *     chart edge is too close; one font size per chart, shrunk (11→9 px) and
 *     then rotated 90° when the category slot is too narrow — never dropped;
 *   - doughnut/pie: "% + value" inside the slice when the box fits in it,
 *     "%" alone if smaller, else "%" just outside the ring, de-overlapped;
 *   - line: above the point, below it when that collides with another label.
 *
 * Per chart: options.plugins.bscdValueLabels = { display, formatValue,
 * formatPercent, color, haloColor, darkColor, lightColor, center }. formatValue /
 * formatPercent are plain functions (the app's fmt / pct), not scriptable.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdChartValueLabels = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var BASE_SIZES = [11, 10, 9];  // preferred → smallest upright label size (px, 12 px chart font)
    var BASE_MIN = [8];
    var GAP = 4;              // px between a mark and its label
    var EDGE = 2;             // px kept free along the canvas edges
    var LINE_HEIGHT = 1.2;
    var MIN_CENTER_SIZE = 7;
    var ARC_TYPES = ['doughnut', 'pie', 'polarArea'];

    // ── colour ─────────────────────────────────────────────────────────
    // WCAG relative luminance of #rgb / #rrggbb / rgb(a)(); null if unknown.
    function luminance(color) {
        var c = String(color || '').trim(), rgb = null, m;
        if ((m = c.match(/^#([0-9a-f]{3})$/i))) {
            rgb = m[1].split('').map(function (h) { return parseInt(h + h, 16); });
        } else if ((m = c.match(/^#([0-9a-f]{6})/i))) {
            rgb = [0, 2, 4].map(function (i) { return parseInt(m[1].substr(i, 2), 16); });
        } else if ((m = c.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i))) {
            rgb = [+m[1], +m[2], +m[3]];
        }
        if (!rgb) return null;
        var lin = rgb.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
    }

    // The text colour (dark or light) with the better contrast on `bg`.
    function textOn(bg, dark, light) {
        var l = luminance(bg), ld = luminance(dark), ll = luminance(light);
        if (l === null || ld === null || ll === null) return dark;
        var ratio = function (a, b) { return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); };
        return ratio(l, ll) >= ratio(l, ld) ? light : dark;
    }

    // ── geometry helpers ───────────────────────────────────────────────
    // Smallest distance between distinct centres; `fallback` if fewer than 2.
    function minGap(values, fallback) {
        var u = values.slice().sort(function (a, b) { return a - b; });
        var gap = Infinity;
        for (var i = 1; i < u.length; i++) {
            var d = u[i] - u[i - 1];
            if (d > 0.5 && d < gap) gap = d;
        }
        return gap === Infinity ? fallback : gap;
    }

    // Label sizes follow the chart font (the PNG export renders at 14 px).
    function sizes(k, list) {
        return (list || BASE_SIZES).map(function (s) { return Math.round(s * (k || 1)); });
    }

    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    function rect(l) { return { left: l.x - l.w / 2, right: l.x + l.w / 2, top: l.y - l.h / 2, bottom: l.y + l.h / 2 }; }

    function overlaps(a, b) {
        var r = rect(a), s = rect(b);
        return r.left < s.right && s.left < r.right && r.top < s.bottom && s.top < r.bottom;
    }

    // A label = centre (x, y), rotation, text lines, its unrotated box (w, h).
    function label(x, y, lines, measure, extra) {
        var w = 0, h = 0;
        lines.forEach(function (ln) { w = Math.max(w, measure(ln.text, ln.size, ln.bold)); h += ln.size * LINE_HEIGHT; });
        var l = { x: x, y: y, angle: 0, lines: lines, w: w, h: h, color: null, halo: false };
        for (var k in extra) l[k] = extra[k];
        return l;
    }

    // ── bars ───────────────────────────────────────────────────────────
    /**
     * @param {Array<{x,y,base,horizontal,value,text,bg}>} bars visible bars of one chart
     * @param {{left,top,right,bottom}} bounds where labels may be drawn
     * @param {function(text,size,bold):number} measure text width in px
     * @param {{text,dark,light}} colors
     * @param {number=} k label size factor (chart font size / 12; 1 on screen)
     */
    function planBars(bars, bounds, measure, colors, k) {
        if (!bars.length) return [];
        var SIZES = sizes(k), MIN_SIZE = sizes(k, BASE_MIN)[0];
        var horizontal = !!bars[0].horizontal;
        var slot = minGap(bars.map(function (b) { return horizontal ? b.y : b.x; }),
            horizontal ? bounds.bottom - bounds.top : bounds.right - bounds.left);
        var widest = function (size) {
            return Math.max.apply(null, bars.map(function (b) { return measure(b.text, size, true); }));
        };

        // One size (and orientation) for the whole chart: labels read as a set.
        var size = null, rotate = false;
        if (horizontal) {
            size = clamp(Math.floor((slot - 3) / LINE_HEIGHT), MIN_SIZE, SIZES[0]);
        } else {
            for (var i = 0; i < SIZES.length && size === null; i++) {
                if (widest(SIZES[i]) <= slot - 2) size = SIZES[i];
            }
            if (size === null) { rotate = true; size = clamp(Math.floor((slot - 3) / LINE_HEIGHT), MIN_SIZE, SIZES[0]); }
        }

        return bars.map(function (b) {
            var w = measure(b.text, size, true);
            var along = horizontal || rotate ? w : size * LINE_HEIGHT; // extent on the value axis
            var end = horizontal ? b.x : b.y;
            // Bar grows right / up. A bar under ~1 px (Chart.js shifts its base
            // by half a grid line) goes by the sign of its value instead.
            var forward = Math.abs(b.base - end) > 1 || b.value == null
                ? (horizontal ? b.x >= b.base : b.y <= b.base)
                : b.value >= 0;
            var dir = horizontal ? (forward ? 1 : -1) : (forward ? -1 : 1);
            var limit = horizontal ? (dir > 0 ? bounds.right : bounds.left) : (dir < 0 ? bounds.top : bounds.bottom);
            var room = Math.abs(limit - end);
            var length = Math.abs(b.base - end);

            var inside = room < along + GAP && length >= along + 2 * GAP;
            var centre;
            if (inside) {
                centre = end - dir * (GAP + along / 2);
            } else {
                centre = end + dir * (GAP + along / 2);
                // Not enough room: pinned to the edge, over the bar (halo keeps it legible).
                if (room < along + GAP) centre = limit - dir * (along / 2);
            }

            return label(horizontal ? centre : b.x, horizontal ? b.y : centre,
                [{ text: b.text, size: size, bold: true }], measure, {
                    angle: rotate ? -Math.PI / 2 : 0,
                    color: inside ? textOn(b.bg, colors.dark, colors.light) : colors.text,
                    halo: !inside
                });
        });
    }

    // ── arcs (doughnut / pie) ──────────────────────────────────────────
    function inSector(px, py, a) {
        var dx = px - a.x, dy = py - a.y, r = Math.sqrt(dx * dx + dy * dy);
        if (r < a.innerRadius + 1 || r > a.outerRadius - 1) return false;
        var ang = Math.atan2(dy, dx);
        while (ang < a.startAngle) ang += 2 * Math.PI;
        while (ang >= a.startAngle + 2 * Math.PI) ang -= 2 * Math.PI;
        return ang <= a.endAngle;
    }

    function boxInSector(l, a) {
        var r = rect(l);
        return inSector(r.left, r.top, a) && inSector(r.right, r.top, a) &&
            inSector(r.left, r.bottom, a) && inSector(r.right, r.bottom, a) &&
            inSector(l.x, r.top, a) && inSector(l.x, r.bottom, a);
    }

    /**
     * @param {Array<{x,y,startAngle,endAngle,innerRadius,outerRadius,text,pctText,bg}>} arcs
     *        pctText null = no percentage (value only)
     */
    function planArcs(arcs, bounds, measure, colors, k) {
        var SIZES = sizes(k), out = [], outside = [];
        arcs.forEach(function (a) {
            if (!(a.endAngle > a.startAngle)) return;
            var mid = (a.startAngle + a.endAngle) / 2;
            var r = a.innerRadius > 0 ? (a.innerRadius + a.outerRadius) / 2 : a.outerRadius * 0.62;
            var cx = a.x + Math.cos(mid) * r, cy = a.y + Math.sin(mid) * r;
            var main = a.pctText || a.text;
            var inColor = textOn(a.bg, colors.dark, colors.light);

            var tries = [];
            SIZES.forEach(function (s) {
                if (a.pctText) tries.push([{ text: a.pctText, size: s, bold: true }, { text: a.text, size: Math.max(s - 1, 1), bold: false }]);
            });
            SIZES.forEach(function (s) { tries.push([{ text: main, size: s, bold: true }]); });

            for (var i = 0; i < tries.length; i++) {
                var l = label(cx, cy, tries[i], measure, { color: inColor });
                if (boxInSector(l, a)) { out.push(l); return; }
            }

            // Too thin for any text: the main figure just outside the ring.
            var size = SIZES[SIZES.length - 1];
            var lo = label(0, 0, [{ text: main, size: size, bold: true }], measure, { color: colors.text, halo: true });
            var ax = a.x + Math.cos(mid) * (a.outerRadius + GAP), ay = a.y + Math.sin(mid) * (a.outerRadius + GAP);
            lo.x = ax + Math.cos(mid) * lo.w / 2;
            lo.y = ay + Math.sin(mid) * lo.h / 2;
            lo.side = Math.cos(mid) >= 0 ? 1 : -1;
            outside.push(lo);
        });

        // De-overlap the outside labels, per side, top to bottom.
        [1, -1].forEach(function (side) {
            var col = outside.filter(function (l) { return l.side === side; }).sort(function (p, q) { return p.y - q.y; });
            for (var i = 1; i < col.length; i++) {
                var minY = col[i - 1].y + (col[i - 1].h + col[i].h) / 2;
                if (col[i].y < minY) col[i].y = minY;
            }
        });
        outside.forEach(function (l) { keepInside(l, bounds); out.push(l); });
        return out;
    }

    // ── points (line) ──────────────────────────────────────────────────
    /** @param {Array<{x,y,text,color}>} points visible points, all datasets */
    function planPoints(points, bounds, measure, colors, k) {
        if (!points.length) return [];
        var SIZES = sizes(k);
        var slot = minGap(points.map(function (p) { return p.x; }), bounds.right - bounds.left);
        var size = SIZES[SIZES.length - 1];
        for (var i = 0; i < SIZES.length; i++) {
            var s = SIZES[i];
            if (Math.max.apply(null, points.map(function (p) { return measure(p.text, s, true); })) <= slot - 2) { size = s; break; }
        }
        var placed = [];
        points.forEach(function (p) {
            var lines = [{ text: p.text, size: size, bold: true }];
            var h = size * LINE_HEIGHT, off = GAP + h / 2;
            var cands = [p.y - off, p.y + off, p.y - off - h, p.y + off + h].map(function (y) {
                var l = label(p.x, y, lines, measure, { color: p.color || colors.text, halo: true });
                keepInside(l, bounds);
                return l;
            });
            var pick = cands.filter(function (c) {
                return !placed.some(function (q) { return overlaps(c, q); });
            })[0] || cands[0];
            placed.push(pick);
        });
        return placed;
    }

    function keepInside(l, bounds) {
        var hw = (l.angle ? l.h : l.w) / 2, hh = (l.angle ? l.w : l.h) / 2;
        l.x = clamp(l.x, bounds.left + hw, bounds.right - hw);
        l.y = clamp(l.y, bounds.top + hh, bounds.bottom - hh);
    }

    // ── Chart.js glue ──────────────────────────────────────────────────
    function fontOf(chart, size, bold) {
        var family = (chart.options.font && chart.options.font.family) || 'sans-serif';
        return (bold ? '600 ' : '400 ') + size + 'px ' + family;
    }

    function measurer(chart) {
        var ctx = chart.ctx;
        return function (text, size, bold) {
            ctx.save();
            ctx.font = fontOf(chart, size, bold);
            var w = ctx.measureText(text).width;
            ctx.restore();
            return w;
        };
    }

    function scaleOf(chart) { return ((chart.options.font && chart.options.font.size) || 12) / 12; }

    function formatter(fn) { return typeof fn === 'function' ? fn : function (v) { return String(v); }; }

    function typeOf(chart, i) { return chart.getDatasetMeta(i).type || chart.config.type; }

    function rawValue(d, axis) {
        if (typeof d === 'number') return d;
        if (d && typeof d === 'object') return Number(d[axis]);
        return d == null ? null : Number(d);
    }

    // Room the labels need around the plot, reserved before layout so the
    // outer labels are never cut by the canvas edge.
    function extraPadding(chart, opts) {
        var pad = { top: 0, right: 0, bottom: 0, left: 0 };
        if (opts.display === false) return pad;
        var measure = measurer(chart), fmt = formatter(opts.formatValue);
        var horizontal = chart.options.indexAxis === 'y';
        var top = sizes(scaleOf(chart))[0];
        var lineH = top * LINE_HEIGHT + GAP + EDGE;
        chart.data.datasets.forEach(function (ds, i) {
            if (!chart.isDatasetVisible(i)) return;
            var type = typeOf(chart, i);
            var values = (ds.data || []).map(function (d) { return rawValue(d, horizontal ? 'x' : 'y'); })
                .filter(function (v) { return v !== null && isFinite(v); });
            if (!values.length) return;
            if (ARC_TYPES.indexOf(type) !== -1) {
                var total = values.reduce(function (s, v) { return s + Math.max(v, 0); }, 0);
                // A thin slice gets its label outside the ring: keep a margin for it.
                if (total && values.some(function (v) { return v > 0 && v / total < 0.08; })) {
                    pad.top = pad.bottom = Math.max(pad.top, lineH);
                    pad.left = pad.right = Math.max(pad.left, lineH);
                }
            } else if (type === 'bar' && horizontal) {
                var w = Math.max.apply(null, values.map(function (v) { return measure(fmt(v), top, true); })) + GAP + EDGE;
                if (values.some(function (v) { return v >= 0; })) pad.right = Math.max(pad.right, w);
                if (values.some(function (v) { return v < 0; })) pad.left = Math.max(pad.left, w);
            } else {
                if (values.some(function (v) { return v >= 0; })) pad.top = Math.max(pad.top, lineH);
                if (values.some(function (v) { return v < 0; })) pad.bottom = Math.max(pad.bottom, lineH);
            }
        });
        return pad;
    }

    function addPadding(base, extra) {
        var b = typeof base === 'number' ? { top: base, right: base, bottom: base, left: base } : (base || {});
        var out = {};
        ['top', 'right', 'bottom', 'left'].forEach(function (k) { out[k] = (Number(b[k]) || 0) + (extra ? extra[k] : 0); });
        return out;
    }

    function collect(chart, opts) {
        var measure = measurer(chart);
        var fmt = formatter(opts.formatValue);
        var fmtPct = typeof opts.formatPercent === 'function' ? opts.formatPercent : null;
        var colors = { text: opts.color, dark: opts.darkColor, light: opts.lightColor };
        var bounds = { left: EDGE, top: EDGE, right: chart.width - EDGE, bottom: chart.height - EDGE };
        var bars = [], arcs = [], points = [];
        var lineCount = chart.data.datasets.filter(function (ds, i) { return typeOf(chart, i) === 'line' && chart.isDatasetVisible(i); }).length;

        chart.data.datasets.forEach(function (ds, i) {
            if (!chart.isDatasetVisible(i)) return;
            var meta = chart.getDatasetMeta(i), type = typeOf(chart, i);
            var isArc = ARC_TYPES.indexOf(type) !== -1;
            var valueAt = function (j) {
                var p = meta.controller.getParsed(j);
                var v = typeof p === 'number' ? p : (p && meta.vScale ? p[meta.vScale.axis] : null);
                return v === null || v === undefined || !isFinite(v) ? null : v;
            };
            var total = 0;
            if (isArc) meta.data.forEach(function (el, j) { var v = valueAt(j); if (v > 0) total += v; });

            meta.data.forEach(function (el, j) {
                var v = valueAt(j);
                if (v === null || el.skip) return;
                if (isArc && !chart.getDataVisibility(j)) return;
                var bg = el.options && el.options.backgroundColor;
                if (isArc) {
                    arcs.push({ x: el.x, y: el.y, startAngle: el.startAngle, endAngle: el.endAngle,
                        innerRadius: el.innerRadius, outerRadius: el.outerRadius, text: fmt(v),
                        pctText: fmtPct && total ? fmtPct(v / total * 100) : null, bg: bg });
                } else if (type === 'bar') {
                    bars.push({ x: el.x, y: el.y, base: el.base, horizontal: !!el.horizontal, value: v, text: fmt(v), bg: bg });
                } else {
                    // Several series: the label takes its series colour.
                    points.push({ x: el.x, y: el.y, text: fmt(v), color: lineCount > 1 ? (el.options && el.options.borderColor) : null });
                }
            });
        });
        var k = scaleOf(chart);
        return planBars(bars, bounds, measure, colors, k)
            .concat(planArcs(arcs, bounds, measure, colors, k))
            .concat(planPoints(points, bounds, measure, colors, k));
    }

    function draw(chart, labels, opts) {
        var ctx = chart.ctx;
        labels.forEach(function (l) {
            ctx.save();
            ctx.translate(l.x, l.y);
            if (l.angle) ctx.rotate(l.angle);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.lineJoin = 'round';
            var y = -l.h / 2;
            l.lines.forEach(function (ln) {
                var lh = ln.size * LINE_HEIGHT;
                ctx.font = fontOf(chart, ln.size, ln.bold);
                if (l.halo) {
                    ctx.lineWidth = 3;
                    ctx.strokeStyle = opts.haloColor;
                    ctx.strokeText(ln.text, 0, y + lh / 2);
                }
                ctx.fillStyle = l.color;
                ctx.fillText(ln.text, 0, y + lh / 2);
                y += lh;
            });
            ctx.restore();
        });
    }

    // options.center = { label, value }: two lines in the hole of a doughnut,
    // on the canvas like the labels, so exported images carry them too.
    function drawCenter(chart, opts) {
        var center = opts.center;
        var arc = chart.getDatasetMeta(0).data[0];
        if (!center || !arc || !(arc.innerRadius > 0)) return;
        var measure = measurer(chart), k = scaleOf(chart), ctx = chart.ctx;
        var room = arc.innerRadius * 1.7;
        var fit = function (text, size, bold) {
            while (size > MIN_CENTER_SIZE * k && measure(text, size, bold) > room) size--;
            return size;
        };
        var labelSize = fit(center.label, Math.round(9 * k), true);
        var valueSize = fit(center.value, Math.round(20 * k), true);
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = opts.color;
        ctx.font = fontOf(chart, labelSize, true);
        ctx.fillText(center.label, arc.x, arc.y - valueSize * 0.65);
        ctx.font = fontOf(chart, valueSize, true);
        ctx.fillText(center.value, arc.x, arc.y + labelSize * 0.6);
        ctx.restore();
    }

    var plugin = {
        id: 'bscdValueLabels',
        defaults: {
            display: true,
            formatValue: null,
            formatPercent: null,
            color: '#374151',
            darkColor: '#1f2937',
            lightColor: '#ffffff',
            haloColor: '#ffffff',
            center: null
        },
        // formatValue / formatPercent are formatters, not scriptable options.
        descriptors: { _scriptable: false, _indexable: false },

        beforeInit: function (chart) {
            // layout.padding is scriptable: the chart's own padding + what the
            // labels need, recomputed on every update (data, resize). A new
            // layout object: a copy of these options (PNG export) shares the
            // old one, and unwraps our function back to the chart's own padding.
            var options = chart.config.options;
            var layout = options.layout || {};
            var base = layout.padding && layout.padding.$bscdBase !== undefined ? layout.padding.$bscdBase : layout.padding;
            var padding = function (ctx) {
                return addPadding(typeof base === 'function' ? base(ctx) : base, chart.$bscdLabelPadding);
            };
            padding.$bscdBase = base === undefined ? null : base;
            options.layout = Object.assign({}, layout, { padding: padding });
        },
        beforeUpdate: function (chart, args, opts) {
            chart.$bscdLabelPadding = extraPadding(chart, opts);
        },
        afterDatasetsDraw: function (chart, args, opts) {
            if (opts.display === false) return;
            draw(chart, collect(chart, opts), opts);
            drawCenter(chart, opts);
        }
    };

    return {
        plugin: plugin,
        planBars: planBars,
        planArcs: planArcs,
        planPoints: planPoints,
        textOn: textOn,
        addPadding: addPadding
    };
}));
