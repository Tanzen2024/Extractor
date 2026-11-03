/**
 * Per-chart export — "⋮" menu on each dashboard chart card:
 *   Exporter les données   CSV / Excel (.xlsx)
 *   Exporter le graphique  PNG (high resolution, report-ready)
 *
 * One source of truth: dashboard.js registers, at render time, the very
 * dataset a chart was drawn from (GET /dashboard/stats answer -> filters ->
 * the same transformations: "Autres" fold, business order, labels). The rows
 * are read back from the Chart.js instance itself (labels + plotted values),
 * so the files always hold exactly what is on screen. Nothing is re-fetched
 * or recomputed: the export can never widen the data the user can see.
 *
 *   register(key, spec)  spec = { chart } (Chart.js instance) or
 *                        { rows: [{ label, value, count, color }] } (HTML list),
 *                        plus values (technical value per label), filters
 *                        ([{ label, text }] as the chips show them).
 *   clear(key)           chart reloading / empty: the menu says so, no file.
 *
 * Markup: <div class="bscd-cx" data-chart-export="{key}"></div> inside a
 * .bscd-chart-card__header — the menu is built here. Title/subtitle come
 * from the card (so a translated title is exported translated). An ancestor
 * with data-export-label / data-export-prefix (dashboard "Vue Postpaid")
 * prefixes the exported title ("Vue Postpaid — …") and the file name
 * ("postpaid_…"). The PNG follows the page theme (body.dark-mode); in dark
 * mode a light-background variant is offered too, for printed reports.
 *
 * DOM-free helpers (slug, CSV, XLSX, ZIP) are unit-tested with Node
 * (tests/js/chart-export.test.js).
 */
(function (root, factory) {
    var api = factory(root);
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.bscdChartExport = api;
        api.init();
    }
}(typeof self !== 'undefined' ? self : this, function (root) {
    'use strict';

    var MESSAGES = {
        fr: {
            menu: 'Exporter ce graphique',
            dataHeader: 'Exporter les données',
            csv: 'Télécharger CSV',
            xlsx: 'Télécharger Excel',
            chartHeader: 'Exporter le graphique',
            png: 'Télécharger PNG',
            pngLight: 'Télécharger PNG (fond clair)',
            success: 'Export réussi',
            error: "Erreur lors de l'export",
            empty: 'Aucune donnée à exporter pour ce graphique.',
            colCategory: 'Catégorie',
            colValue: 'Valeur source',
            colCount: 'Nombre de clients',
            colShare: 'Part (%)',
            sheetData: 'Données',
            sheetContext: 'Contexte',
            ctxChart: 'Graphique',
            ctxSubtitle: 'Sous-titre',
            ctxFilters: 'Filtres appliqués',
            ctxNoFilter: 'Aucun (toute la base)',
            ctxTotal: 'Total',
            ctxGenerated: 'Généré le',
            ctxSource: 'Source',
            filters: 'Filtres',
            total: 'Total',
            clients: 'clients',
            generated: 'Généré le'
        },
        en: {
            menu: 'Export this chart',
            dataHeader: 'Export data',
            csv: 'Download CSV',
            xlsx: 'Download Excel',
            chartHeader: 'Export chart',
            png: 'Download PNG',
            pngLight: 'Download PNG (light background)',
            success: 'Export successful',
            error: 'Export failed',
            empty: 'No data to export for this chart.',
            colCategory: 'Category',
            colValue: 'Source value',
            colCount: 'Number of customers',
            colShare: 'Share (%)',
            sheetData: 'Data',
            sheetContext: 'Context',
            ctxChart: 'Chart',
            ctxSubtitle: 'Subtitle',
            ctxFilters: 'Applied filters',
            ctxNoFilter: 'None (whole base)',
            ctxTotal: 'Total',
            ctxGenerated: 'Generated on',
            ctxSource: 'Source',
            filters: 'Filters',
            total: 'Total',
            clients: 'customers',
            generated: 'Generated on'
        }
    };

    // ── locale / messages ──────────────────────────────────────────────
    function locale() {
        var l = root.BSCD_I18N && root.BSCD_I18N.locale;
        if (!l && root.document) l = root.document.documentElement.getAttribute('lang');
        l = String(l || 'fr').slice(0, 2).toLowerCase();
        return MESSAGES[l] ? l : 'fr';
    }

    // The app catalog (public/assets/i18n/*.json, "chartExport.*") wins when
    // it has the key; the built-in strings above are the fallback.
    function t(key, loc) {
        var i18n = root.bscdI18n;
        if (i18n && typeof i18n.t === 'function' && (!loc || loc === locale())) {
            var s = i18n.t('chartExport.' + key);
            if (typeof s === 'string' && s !== '' && s !== 'chartExport.' + key) return s;
        }
        return MESSAGES[loc || locale()][key];
    }

    function numberLocale(loc) { return loc === 'en' ? 'en-US' : 'fr-FR'; }

    // ── pure helpers ───────────────────────────────────────────────────
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function isoDate(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function displayDateTime(d, loc) {
        var date = loc === 'en'
            ? d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
            : pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
        return date + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    // "Répartition par région" -> "repartition_par_region": accents dropped,
    // anything not [a-z0-9] collapsed to one "_", bounded length.
    function slug(text) {
        var s = String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        return s.slice(0, 80).replace(/_+$/, '') || 'graphique';
    }

    function fileName(title, ext, date) { return slug(title) + '_' + isoDate(date || new Date()) + '.' + ext; }

    // Rows exactly as plotted. `values` = technical value per label (shown
    // as an extra column only when some label differs from it).
    function tableFromSpec(spec) {
        var rows;
        if (spec.chart) {
            var labels = spec.chart.data.labels || [];
            var data = (spec.chart.data.datasets[0] || {}).data || [];
            rows = labels.map(function (label, i) {
                return { label: String(label), value: spec.values ? spec.values[i] : label, count: Number(data[i]) || 0 };
            });
        } else {
            rows = (spec.rows || []).map(function (r) {
                return { label: String(r.label), value: r.value, count: Number(r.count) || 0, color: r.color };
            });
        }
        var total = rows.reduce(function (s, r) { return s + r.count; }, 0);
        rows.forEach(function (r) { r.share = total ? r.count / total : 0; });
        var withValue = rows.some(function (r) { return r.value != null && String(r.value) !== r.label; });
        return { rows: rows, total: total, withValue: withValue };
    }

    // Header + one array per row: [label, (value), count, share as a fraction].
    function tableMatrix(table, loc) {
        var head = [t('colCategory', loc)];
        if (table.withValue) head.push(t('colValue', loc));
        head.push(t('colCount', loc), t('colShare', loc));
        var body = table.rows.map(function (r) {
            var line = [r.label];
            if (table.withValue) line.push(r.value == null ? '' : String(r.value));
            line.push(r.count, r.share);
            return line;
        });
        return { head: head, body: body };
    }

    // A text cell starting like a formula is neutralised (CSV injection).
    function safeText(s) { return /^[=+\-@\t\r]/.test(s) ? "'" + s : s; }

    // CSV in the user's language convention: fr = ";" + decimal ",", en =
    // "," + decimal ".". Share = the percentage the tooltip shows (1 decimal).
    // No BOM here (added when the Blob is made).
    function toCsv(table, loc) {
        var sep = loc === 'en' ? ',' : ';';
        var m = tableMatrix(table, loc);
        function cell(v, isText) {
            if (!isText) return String(v);
            var s = safeText(String(v));
            return /["\r\n]/.test(s) || s.indexOf(sep) !== -1 ? '"' + s.replace(/"/g, '""') + '"' : s;
        }
        var lines = [m.head.map(function (h) { return cell(h, true); }).join(sep)];
        m.body.forEach(function (line) {
            var last = line.length - 1;
            lines.push(line.map(function (v, i) {
                if (i === last) {
                    var p = (Math.round(v * 1000) / 10).toFixed(1);
                    return loc === 'en' ? p : p.replace('.', ',');
                }
                return cell(v, typeof v !== 'number');
            }).join(sep));
        });
        return lines.join('\r\n') + '\r\n';
    }

    // ── minimal XLSX writer (stored ZIP, inline strings) ───────────────
    var CRC_TABLE = (function () {
        var table = [];
        for (var n = 0; n < 256; n++) {
            var c = n;
            for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
            table[n] = c >>> 0;
        }
        return table;
    }());

    function crc32(bytes) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    function utf8(s) { return new TextEncoder().encode(s); }

    // files = [{ name, data: string|Uint8Array }] -> Uint8Array (ZIP, no compression).
    function zip(files, date) {
        date = date || new Date();
        var dosTime = (date.getHours() << 11) | (date.getMinutes() << 5) | (date.getSeconds() >> 1);
        var dosDate = ((date.getFullYear() - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
        var parts = [], central = [], offset = 0;

        files.forEach(function (f) {
            var name = utf8(f.name);
            var data = typeof f.data === 'string' ? utf8(f.data) : f.data;
            var crc = crc32(data);
            var local = new DataView(new ArrayBuffer(30));
            local.setUint32(0, 0x04034b50, true);
            local.setUint16(4, 20, true);
            local.setUint16(6, 0x0800, true);   // UTF-8 names
            local.setUint16(8, 0, true);        // stored
            local.setUint16(10, dosTime, true);
            local.setUint16(12, dosDate, true);
            local.setUint32(14, crc, true);
            local.setUint32(18, data.length, true);
            local.setUint32(22, data.length, true);
            local.setUint16(26, name.length, true);
            local.setUint16(28, 0, true);
            parts.push(new Uint8Array(local.buffer), name, data);

            var cd = new DataView(new ArrayBuffer(46));
            cd.setUint32(0, 0x02014b50, true);
            cd.setUint16(4, 20, true);
            cd.setUint16(6, 20, true);
            cd.setUint16(8, 0x0800, true);
            cd.setUint16(10, 0, true);
            cd.setUint16(12, dosTime, true);
            cd.setUint16(14, dosDate, true);
            cd.setUint32(16, crc, true);
            cd.setUint32(20, data.length, true);
            cd.setUint32(24, data.length, true);
            cd.setUint16(28, name.length, true);
            cd.setUint32(42, offset, true);
            central.push(new Uint8Array(cd.buffer), name);
            offset += 30 + name.length + data.length;
        });

        var cdSize = central.reduce(function (s, p) { return s + p.length; }, 0);
        var end = new DataView(new ArrayBuffer(22));
        end.setUint32(0, 0x06054b50, true);
        end.setUint16(8, files.length, true);
        end.setUint16(10, files.length, true);
        end.setUint32(12, cdSize, true);
        end.setUint32(16, offset, true);

        var all = parts.concat(central, [new Uint8Array(end.buffer)]);
        var out = new Uint8Array(all.reduce(function (s, p) { return s + p.length; }, 0));
        var pos = 0;
        all.forEach(function (p) { out.set(p, pos); pos += p.length; });
        return out;
    }

    function xmlEscape(s) {
        return String(s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; })
            // characters XML 1.0 forbids
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '');
    }

    function colName(i) { var s = ''; i++; while (i > 0) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; }

    // Style ids, see STYLES_XML: 0 text, 1 header, 2 integer, 3 percent, 4 bold.
    var STYLES_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
        '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>' +
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' +
        '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' +
        '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/><bgColor indexed="64"/></patternFill></fill></fills>' +
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' +
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
        '<cellXfs count="5">' +
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' +
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' +
        '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' +
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' +
        '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' +
        '</cellXfs>' +
        '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' +
        '</styleSheet>';

    // rows = [[{ v, s }|string|number]], widths = column widths (chars).
    function sheetXml(rows, widths, frozenHeader) {
        var xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if (frozenHeader) {
            xml += '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        xml += '<cols>' + widths.map(function (w, i) {
            return '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + w + '" customWidth="1"/>';
        }).join('') + '</cols><sheetData>';
        rows.forEach(function (row, r) {
            xml += '<row r="' + (r + 1) + '">';
            row.forEach(function (cell, c) {
                if (cell == null || cell === '') return;
                if (typeof cell !== 'object') cell = { v: cell };
                var ref = colName(c) + (r + 1);
                var s = cell.s ? ' s="' + cell.s + '"' : '';
                if (typeof cell.v === 'number' && isFinite(cell.v)) {
                    xml += '<c r="' + ref + '"' + s + '><v>' + cell.v + '</v></c>';
                } else {
                    xml += '<c r="' + ref + '"' + s + ' t="inlineStr"><is><t xml:space="preserve">' + xmlEscape(cell.v) + '</t></is></c>';
                }
            });
            xml += '</row>';
        });
        return xml + '</sheetData></worksheet>';
    }

    // ctx = { title, subtitle, filters: [{label, text}], source, date }
    function toXlsx(table, ctx, loc) {
        var m = tableMatrix(table, loc);
        var last = m.head.length - 1;
        var dataRows = [m.head.map(function (h) { return { v: h, s: 1 }; })].concat(m.body.map(function (line) {
            return line.map(function (v, i) {
                if (i === last) return { v: v, s: 3 };
                if (i === last - 1) return { v: v, s: 2 };
                return { v: v };
            });
        }));
        var widths = m.head.map(function (h, i) {
            var longest = m.body.reduce(function (w, line) { return Math.max(w, String(line[i]).length); }, String(h).length);
            return i >= last - 1 ? Math.max(14, String(h).length + 2) : Math.min(60, Math.max(12, longest + 2));
        });

        var B = function (v) { return { v: v, s: 4 }; };
        var ctxRows = [[B(t('ctxChart', loc)), ctx.title]];
        if (ctx.subtitle) ctxRows.push([B(t('ctxSubtitle', loc)), ctx.subtitle]);
        if (ctx.filters && ctx.filters.length) {
            ctx.filters.forEach(function (f, i) { ctxRows.push([i === 0 ? B(t('ctxFilters', loc)) : '', f.label, f.text]); });
        } else {
            ctxRows.push([B(t('ctxFilters', loc)), t('ctxNoFilter', loc)]);
        }
        ctxRows.push([B(t('ctxTotal', loc)), { v: table.total, s: 2 }]);
        ctxRows.push([B(t('ctxGenerated', loc)), displayDateTime(ctx.date || new Date(), loc)]);
        if (ctx.source) ctxRows.push([B(t('ctxSource', loc)), ctx.source]);

        var sheets = [t('sheetData', loc), t('sheetContext', loc)];
        var ns = 'http://schemas.openxmlformats.org/';
        return zip([
            { name: '[Content_Types].xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                '<Types xmlns="' + ns + 'package/2006/content-types">' +
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
                '<Default Extension="xml" ContentType="application/xml"/>' +
                '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
                '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
                '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
                '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
                '</Types>' },
            { name: '_rels/.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                '<Relationships xmlns="' + ns + 'package/2006/relationships">' +
                '<Relationship Id="rId1" Type="' + ns + 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
                '</Relationships>' },
            { name: 'xl/workbook.xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                '<workbook xmlns="' + ns + 'spreadsheetml/2006/main" xmlns:r="' + ns + 'officeDocument/2006/relationships"><sheets>' +
                sheets.map(function (name, i) {
                    return '<sheet name="' + xmlEscape(name) + '" sheetId="' + (i + 1) + '" r:id="rId' + (i + 1) + '"/>';
                }).join('') + '</sheets></workbook>' },
            { name: 'xl/_rels/workbook.xml.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                '<Relationships xmlns="' + ns + 'package/2006/relationships">' +
                '<Relationship Id="rId1" Type="' + ns + 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' +
                '<Relationship Id="rId2" Type="' + ns + 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>' +
                '<Relationship Id="rId3" Type="' + ns + 'officeDocument/2006/relationships/styles" Target="styles.xml"/>' +
                '</Relationships>' },
            { name: 'xl/styles.xml', data: STYLES_XML },
            { name: 'xl/worksheets/sheet1.xml', data: sheetXml(dataRows, widths, true) },
            { name: 'xl/worksheets/sheet2.xml', data: sheetXml(ctxRows, [22, 40, 60], false) }
        ], ctx.date);
    }

    // ── browser part ───────────────────────────────────────────────────
    var registry = {};
    var PNG_WIDTH = 1200;   // CSS px of the exported image (×SCALE in real pixels)
    var PNG_PAD = 40;

    function doc() { return root.document; }

    function card(key) {
        var host = doc().querySelector('[data-chart-export="' + key + '"]');
        return host ? host.closest('.bscd-chart-card') : null;
    }

    function cardText(key, selector) {
        var c = card(key);
        var el = c && c.querySelector(selector);
        return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function context(key, spec) {
        var brand = doc().querySelector('.app-brand-header');
        var c = card(key);
        var scope = c && c.closest('[data-export-label]');
        var label = scope ? scope.getAttribute('data-export-label') : '';
        var prefix = scope ? scope.getAttribute('data-export-prefix') || '' : '';
        var title = cardText(key, '.bscd-chart-card__title') || key;
        return {
            title: label ? label + ' — ' + title : title,
            fileTitle: prefix ? prefix + ' ' + title : title,
            subtitle: cardText(key, '.bscd-chart-card__subtitle'),
            filters: spec.filters || [],
            source: brand ? brand.textContent.trim() : 'Extractor',
            date: new Date()
        };
    }

    function register(key, spec) { registry[key] = spec; syncMenu(key); }
    function clear(key) { delete registry[key]; syncMenu(key); }

    function hasData(key) {
        var spec = registry[key];
        return !!spec && tableFromSpec(spec).rows.length > 0;
    }

    function syncMenu(key) {
        if (!doc()) return;
        var host = doc().querySelector('[data-chart-export="' + key + '"]');
        if (!host) return;
        var ok = hasData(key);
        host.querySelectorAll('[data-cx-format]').forEach(function (b) { b.disabled = !ok; });
        var note = host.querySelector('[data-cx-note]');
        if (note) note.hidden = ok;
    }

    function buildMenu(host) {
        var key = host.getAttribute('data-chart-export');
        var id = 'bscdCx_' + key;
        host.innerHTML =
            '<button type="button" class="bscd-cx__toggle" id="' + id + '" data-toggle="dropdown" data-display="static"' +
            ' aria-haspopup="true" aria-expanded="false" aria-label="' + t('menu') + '" title="' + t('menu') + '">' +
            '<i class="fas fa-ellipsis-vertical" aria-hidden="true"></i></button>' +
            '<div class="dropdown-menu dropdown-menu-right bscd-cx__menu" aria-labelledby="' + id + '">' +
            '<h6 class="dropdown-header"><i class="fas fa-table" aria-hidden="true"></i>' + t('dataHeader') + '</h6>' +
            '<button type="button" class="dropdown-item" data-cx-format="csv"><i class="fas fa-file-csv" aria-hidden="true"></i>' + t('csv') + '<span class="bscd-cx__ext">.csv</span></button>' +
            '<button type="button" class="dropdown-item" data-cx-format="xlsx"><i class="fas fa-file-excel" aria-hidden="true"></i>' + t('xlsx') + '<span class="bscd-cx__ext">.xlsx</span></button>' +
            '<div class="dropdown-divider"></div>' +
            '<h6 class="dropdown-header"><i class="fas fa-image" aria-hidden="true"></i>' + t('chartHeader') + '</h6>' +
            '<button type="button" class="dropdown-item" data-cx-format="png"><i class="fas fa-file-image" aria-hidden="true"></i>' + t('png') + '<span class="bscd-cx__ext">.png</span></button>' +
            '<button type="button" class="dropdown-item" data-cx-format="png-light" data-cx-variant="light"><i class="fas fa-sun" aria-hidden="true"></i>' + t('pngLight') + '<span class="bscd-cx__ext">.png</span></button>' +
            '<p class="bscd-cx__note" data-cx-note>' + t('empty') + '</p>' +
            '</div>';
        syncMenu(key);
    }

    var toastTimer;
    function toast(text, isError) {
        var el = doc().getElementById('bscdCxToast');
        if (!el) {
            el = doc().createElement('div');
            el.id = 'bscdCxToast';
            el.className = 'bscd-cx-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            doc().body.appendChild(el);
        }
        el.classList.toggle('bscd-cx-toast--error', !!isError);
        el.innerHTML = '<i class="fas ' + (isError ? 'fa-circle-exclamation' : 'fa-circle-check') + '" aria-hidden="true"></i>';
        el.appendChild(doc().createTextNode(text));
        el.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('is-visible'); }, 3200);
    }

    function download(blob, name) {
        var url = URL.createObjectURL(blob);
        var a = doc().createElement('a');
        a.href = url;
        a.download = name;
        a.rel = 'noopener';
        doc().body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
    }

    // ── PNG ────────────────────────────────────────────────────────────
    // The live chart is drawn again, natively by Chart.js, on an off-screen
    // canvas: fixed report width, a height suited to its type, devicePixelRatio
    // SCALE, no animation, larger fonts — so nothing depends on the screen the
    // user is on (a phone gives the same file as a desktop) and no label is cut.
    function scale() { return Math.min(3, Math.max(2, Math.ceil(root.devicePixelRatio || 1))); }

    // Image colours per theme — the dark one matches the dark chart cards.
    var PNG_THEMES = {
        light: {
            bg: '#ffffff', title: '#1f2937', sub: '#6b7280', meta: '#4b5563', rule: '#e5e7eb', foot: '#9ca3af',
            muted: '#9ca3af', text: '#1f2937', line: '#eef0f4',
            chartText: '#666', grid: 'rgba(0, 0, 0, .1)', label: '#374151', halo: '#ffffff'
        },
        dark: {
            bg: '#1f2633', title: '#f3f4f6', sub: '#9ca3af', meta: '#cbd5e1', rule: '#334155', foot: '#7c8799',
            muted: '#94a3b8', text: '#e5e7eb', line: '#334155',
            chartText: '#cbd5e1', grid: 'rgba(148, 163, 184, .18)', label: '#e5e7eb', halo: '#1f2633'
        }
    };
    function currentTheme() { return doc() && doc().body && doc().body.classList.contains('dark-mode') ? 'dark' : 'light'; }

    function chartHeight(chart, width) {
        var n = (chart.data.labels || []).length;
        if (chart.config.type === 'doughnut' || chart.config.type === 'pie') return Math.round(width * 0.42);
        if (chart.options.indexAxis === 'y') return Math.max(320, n * 34 + 70);
        return Math.max(420, Math.round(width * 0.45));
    }

    function renderChart(live, width, height, s, pal) {
        var Chart = root.Chart;
        var host = doc().createElement('div');
        host.style.cssText = 'position:fixed;left:-100000px;top:0;pointer-events:none;width:' + width + 'px;height:' + height + 'px;';
        var canvas = doc().createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        host.appendChild(canvas);
        doc().body.appendChild(host);

        var cfg = live.config;
        var raw = cfg.options || {};
        var plugins = Object.assign({}, raw.plugins, { tooltip: { enabled: false } });
        if (plugins.legend && plugins.legend.display !== false) {
            plugins.legend = Object.assign({}, plugins.legend, {
                labels: Object.assign({}, plugins.legend.labels, { boxWidth: 16, padding: 14, font: { size: 15 } })
            });
        }
        var options = Object.assign({}, raw, {
            responsive: false, maintainAspectRatio: false, animation: false, devicePixelRatio: s, plugins: plugins
        });
        var data = {
            labels: (cfg.data.labels || []).slice(),
            datasets: cfg.data.datasets.map(function (d) {
                var copy = Object.assign({}, d, { data: d.data.slice() });
                if (copy.maxBarThickness) copy.maxBarThickness = Math.round(copy.maxBarThickness * 1.4);
                return copy;
            })
        };

        var previousSize = Chart.defaults.font.size;
        var previousFamily = Chart.defaults.font.family;
        var previousColor = Chart.defaults.color;
        var previousBorder = Chart.defaults.borderColor;
        var vl = Chart.defaults.plugins && Chart.defaults.plugins.bscdValueLabels;
        var previousLabel = vl && vl.color;
        var previousHalo = vl && vl.haloColor;
        Chart.defaults.font.size = 14;   // readable once pasted in a slide
        Chart.defaults.font.family = root.getComputedStyle(doc().body).fontFamily || previousFamily;
        Chart.defaults.color = pal.chartText;
        Chart.defaults.borderColor = pal.grid;
        if (vl) { vl.color = pal.label; vl.haloColor = pal.halo; }
        var chart;
        try {
            chart = new Chart(canvas.getContext('2d'), { type: cfg.type, data: data, options: options, plugins: cfg.plugins });
            var out = doc().createElement('canvas');
            out.width = canvas.width;
            out.height = canvas.height;
            out.getContext('2d').drawImage(canvas, 0, 0);
            return out;
        } finally {
            Chart.defaults.font.size = previousSize;
            Chart.defaults.font.family = previousFamily;
            Chart.defaults.color = previousColor;
            Chart.defaults.borderColor = previousBorder;
            if (vl) { vl.color = previousLabel; vl.haloColor = previousHalo; }
            if (chart) chart.destroy();
            host.remove();
        }
    }

    function wrap(ctx, text, maxWidth) {
        var words = String(text).split(' '), lines = [], line = '';
        words.forEach(function (w) {
            var test = line ? line + ' ' + w : w;
            if (line && ctx.measureText(test).width > maxWidth) { lines.push(line); line = w; } else { line = test; }
        });
        if (line) lines.push(line);
        return lines;
    }

    function roundRect(ctx, x, y, w, h, r) {
        if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x, y, w, h, r); ctx.fill(); } else { ctx.fillRect(x, y, w, h); }
    }

    // HTML list cards ("Type de compteurs"): drawn as on screen — total, then
    // one row per value: swatch, name, count, share.
    function listHeight(table) { return 96 + table.rows.length * 40; }

    function drawList(ctx, key, table, x, y, width, font, loc, pal) {
        var nf = new Intl.NumberFormat(numberLocale(loc));
        var pf = new Intl.NumberFormat(numberLocale(loc), { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'left';
        ctx.fillStyle = pal.muted;
        ctx.font = '700 13px ' + font;
        ctx.fillText((cardText(key, '.bscd-metertype__total-label') || t('total', loc)).toUpperCase(), x, y + 16);
        ctx.fillStyle = pal.text;
        ctx.font = '700 36px ' + font;
        ctx.fillText(nf.format(table.total), x, y + 58);
        ctx.fillStyle = pal.line;
        ctx.fillRect(x, y + 76, width, 1);
        table.rows.forEach(function (r, i) {
            var cy = y + 96 + i * 40 + 20;
            ctx.fillStyle = r.color || '#94a3b8';
            roundRect(ctx, x, cy - 8, 16, 16, 4);
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'left';
            ctx.fillStyle = pal.text;
            ctx.font = '600 17px ' + font;
            ctx.fillText(r.label, x + 30, cy);
            ctx.textAlign = 'right';
            ctx.font = '700 17px ' + font;
            ctx.fillText(nf.format(r.count), x + width - 110, cy);
            ctx.fillStyle = pal.muted;
            ctx.font = '400 17px ' + font;
            ctx.fillText(pf.format(r.share * 100) + ' %', x + width, cy);
        });
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
    }

    function renderPng(key, spec, table, ctxInfo, loc, theme) {
        var pal = PNG_THEMES[theme] || PNG_THEMES.light;
        var s = scale();
        var font = root.getComputedStyle(doc().body).fontFamily || 'sans-serif';
        var inner = PNG_WIDTH - 2 * PNG_PAD;
        var chartImg = spec.chart ? renderChart(spec.chart, inner, chartHeight(spec.chart, inner), s, pal) : null;
        var bodyHeight = chartImg ? chartImg.height / s : listHeight(table);

        var nf = new Intl.NumberFormat(numberLocale(loc));
        var filtersText = t('filters', loc) + ' : ' + (ctxInfo.filters.length
            ? ctxInfo.filters.map(function (f) { return f.label + ' : ' + f.text; }).join('  ·  ')
            : t('ctxNoFilter', loc));
        var totalText = t('total', loc) + ' : ' + nf.format(table.total) + ' ' + t('clients', loc);
        var footer = ctxInfo.source + '  ·  ' + t('generated', loc) + ' ' + displayDateTime(ctxInfo.date, loc);

        var measure = doc().createElement('canvas').getContext('2d');
        measure.font = '700 26px ' + font;
        var titleLines = wrap(measure, ctxInfo.title, inner);
        measure.font = '400 15px ' + font;
        var subLines = ctxInfo.subtitle ? wrap(measure, ctxInfo.subtitle, inner) : [];
        measure.font = '400 13px ' + font;
        var filterLines = wrap(measure, filtersText, inner);

        var headerHeight = titleLines.length * 34 + subLines.length * 22 + 10 + filterLines.length * 19 + 19;
        var height = Math.ceil(PNG_PAD + headerHeight + 18 + 1 + 22 + bodyHeight + 26 + 16 + PNG_PAD);

        var canvas = doc().createElement('canvas');
        canvas.width = PNG_WIDTH * s;
        canvas.height = height * s;
        var ctx = canvas.getContext('2d');
        ctx.scale(s, s);
        ctx.fillStyle = pal.bg;   // opaque: readable on any slide / mail background
        ctx.fillRect(0, 0, PNG_WIDTH, height);

        var y = PNG_PAD;
        ctx.textBaseline = 'top';
        ctx.fillStyle = pal.title;
        ctx.font = '700 26px ' + font;
        titleLines.forEach(function (l) { ctx.fillText(l, PNG_PAD, y); y += 34; });
        ctx.fillStyle = pal.sub;
        ctx.font = '400 15px ' + font;
        subLines.forEach(function (l) { ctx.fillText(l, PNG_PAD, y); y += 22; });
        y += 10;
        ctx.fillStyle = pal.meta;
        ctx.font = '400 13px ' + font;
        filterLines.forEach(function (l) { ctx.fillText(l, PNG_PAD, y); y += 19; });
        ctx.font = '600 13px ' + font;
        ctx.fillText(totalText, PNG_PAD, y);
        y += 19 + 18;
        ctx.fillStyle = pal.rule;
        ctx.fillRect(PNG_PAD, y, inner, 1);
        y += 1 + 22;

        if (chartImg) {
            ctx.drawImage(chartImg, PNG_PAD, y, inner, bodyHeight);
        } else {
            drawList(ctx, key, table, PNG_PAD, y, inner, font, loc, pal);
        }
        y += bodyHeight + 26;

        ctx.textBaseline = 'top';
        ctx.fillStyle = pal.foot;
        ctx.font = '400 12px ' + font;
        ctx.fillText(footer, PNG_PAD, y);

        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) { if (blob) resolve(blob); else reject(new Error('PNG encoding failed')); }, 'image/png');
        });
    }

    // ── actions ────────────────────────────────────────────────────────
    function run(key, format) {
        var loc = locale();
        var spec = registry[key];
        var table = spec ? tableFromSpec(spec) : null;
        if (!table || !table.rows.length) { toast(t('empty'), true); return Promise.resolve(false); }
        var info = context(key, spec);

        var work;
        if (format === 'csv') {
            work = Promise.resolve(new Blob(['﻿' + toCsv(table, loc)], { type: 'text/csv;charset=utf-8' }));
        } else if (format === 'xlsx') {
            work = Promise.resolve(new Blob([toXlsx(table, info, loc)],
                { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
        } else if (format === 'png' || format === 'png-light') {
            var theme = format === 'png-light' ? 'light' : currentTheme();
            work = new Promise(function (resolve) { setTimeout(resolve, 0); })   // let the menu close first
                .then(function () { return renderPng(key, spec, table, info, loc, theme); });
        } else {
            return Promise.resolve(false);
        }

        var host = doc().querySelector('[data-chart-export="' + key + '"]');
        if (host) host.classList.add('is-busy');
        return work.then(function (blob) {
            download(blob, fileName(info.fileTitle, format === 'png-light' ? 'png' : format, info.date));
            toast(t('success'));
            return true;
        }).catch(function (err) {
            if (root.console) root.console.error('[chart-export]', err);
            toast(t('error'), true);
            return false;
        }).then(function (ok) {
            if (host) host.classList.remove('is-busy');
            return ok;
        });
    }

    // PNG Blob of a registered chart, without downloading it (null = no data).
    // theme: 'light' | 'dark', default = the page theme.
    function pngBlob(key, theme) {
        var spec = registry[key];
        var table = spec ? tableFromSpec(spec) : null;
        if (!table || !table.rows.length) return Promise.resolve(null);
        return renderPng(key, spec, table, context(key, spec), locale(), theme || currentTheme());
    }

    function init() {
        if (!doc()) return;
        doc().querySelectorAll('[data-chart-export]').forEach(buildMenu);
        doc().addEventListener('click', function (e) {
            var item = e.target.closest && e.target.closest('[data-cx-format]');
            if (!item || item.disabled) return;
            var host = item.closest('[data-chart-export]');
            if (host) run(host.getAttribute('data-chart-export'), item.getAttribute('data-cx-format'));
        });
    }

    return {
        init: init, register: register, clear: clear, run: run, pngBlob: pngBlob,
        // pure helpers (tests)
        slug: slug, fileName: fileName, tableFromSpec: tableFromSpec, toCsv: toCsv, toXlsx: toXlsx,
        zip: zip, crc32: crc32, messages: MESSAGES
    };
}));
