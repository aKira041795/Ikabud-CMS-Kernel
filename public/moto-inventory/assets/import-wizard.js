/* Moto Inventory — Excel import wizard.
 *
 * Port of the Fazt Sale source app's import mapping process:
 *   file → sheet → range → mapping → review → stage.
 *
 * The .xlsx is parsed entirely in the browser (zero dependencies; a hand-rolled
 * ZIP/deflate reader + XML parse, same approach as the source app). The user
 * picks the sheet, the column/row range, and maps each column to a part field
 * (Part No., Description, Cost Price, Sell Price, Quantity, Code Price) or a
 * custom field. Unknown columns become custom fields under the label typed.
 * Sell Price and Code Price are mutually exclusive (both are "the price");
 * a Code Price column is decoded via the M-I-C-H-A-E-L-S-O-N cipher.
 *
 * The server remains the source of truth: the wizard only computes the
 * mapping / sheet / range and POSTs it to the existing stage endpoint.
 */
(function () {
    'use strict';

    var S = window.MOTO_INVENTORY || {};
    function $(id) { return document.getElementById(id); }
    function esc(v) { if (typeof S.esc === 'function') return S.esc(v); var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }

    // ── XLSX parsing (port of Fazt Sale readZipEntries/parseSheetXml) ──
    function colLetterToIndex(letters) {
        var n = 0, L = String(letters).toUpperCase();
        for (var i = 0; i < L.length; i++) n = n * 26 + (L.charCodeAt(i) - 64);
        return n - 1;
    }
    function colIndexToLetter(idx) {
        idx += 1; var s = '';
        while (idx > 0) { var rem = (idx - 1) % 26; s = String.fromCharCode(65 + rem) + s; idx = Math.floor((idx - 1) / 26); }
        return s;
    }
    function splitCellRef(ref) {
        var m = /^([A-Za-z]+)(\d+)$/.exec(ref);
        if (!m) return null;
        return { col: colLetterToIndex(m[1]), row: parseInt(m[2], 10) - 1 };
    }
    async function inflateRaw(bytes) {
        if (typeof DecompressionStream === 'undefined') throw new Error('NO_DECOMPRESSION_STREAM');
        var ds = new DecompressionStream('deflate-raw');
        var stream = new Blob([bytes]).stream().pipeThrough(ds);
        var buf = await new Response(stream).arrayBuffer();
        return new Uint8Array(buf);
    }
    async function readZipEntries(arrayBuffer) {
        var view = new DataView(arrayBuffer);
        var bytes = new Uint8Array(arrayBuffer);
        var eocdOffset = -1;
        for (var i = bytes.length - 22; i >= 0; i--) { if (view.getUint32(i, true) === 0x06054b50) { eocdOffset = i; break; } }
        if (eocdOffset === -1) throw new Error('Not a valid .xlsx file (zip signature not found).');
        var cdEntries = view.getUint16(eocdOffset + 10, true);
        var cdOffset = view.getUint32(eocdOffset + 16, true);
        var results = {};
        var offset = cdOffset;
        for (var j = 0; j < cdEntries; j++) {
            var sig = view.getUint32(offset, true);
            if (sig !== 0x02014b50) break;
            var compMethod = view.getUint16(offset + 10, true);
            var compSize = view.getUint32(offset + 20, true);
            var nameLen = view.getUint16(offset + 28, true);
            var extraLen = view.getUint16(offset + 30, true);
            var commentLen = view.getUint16(offset + 32, true);
            var localHeaderOffset = view.getUint32(offset + 42, true);
            var name = new TextDecoder().decode(bytes.slice(offset + 46, offset + 46 + nameLen));
            if (name === 'xl/workbook.xml' || name === 'xl/_rels/workbook.xml.rels' || name === 'xl/sharedStrings.xml' || name.indexOf('xl/worksheets/') === 0) {
                var lhNameLen = view.getUint16(localHeaderOffset + 26, true);
                var lhExtraLen = view.getUint16(localHeaderOffset + 28, true);
                var dataStart = localHeaderOffset + 30 + lhNameLen + lhExtraLen;
                var compData = bytes.slice(dataStart, dataStart + compSize);
                var raw;
                if (compMethod === 0) raw = compData;
                else if (compMethod === 8) raw = await inflateRaw(compData);
                else { offset += 46 + nameLen + extraLen + commentLen; continue; }
                results[name] = new TextDecoder('utf-8').decode(raw);
            }
            offset += 46 + nameLen + extraLen + commentLen;
        }
        return results;
    }
    function parseSharedStrings(xml) {
        if (!xml) return [];
        var doc = new DOMParser().parseFromString(xml, 'application/xml');
        return Array.from(doc.getElementsByTagName('si')).map(function (si) {
            return Array.from(si.getElementsByTagName('t')).map(function (t) { return t.textContent; }).join('');
        });
    }
    function listSheets(entries) {
        var wbXml = entries['xl/workbook.xml'];
        var relsXml = entries['xl/_rels/workbook.xml.rels'];
        if (!wbXml) return [{ name: 'Sheet1', path: 'xl/worksheets/sheet1.xml' }];
        var wbDoc = new DOMParser().parseFromString(wbXml, 'application/xml');
        var sheetEls = Array.from(wbDoc.getElementsByTagName('sheet'));
        if (!sheetEls.length) return [{ name: 'Sheet1', path: 'xl/worksheets/sheet1.xml' }];
        var rels = {};
        if (relsXml) {
            var relsDoc = new DOMParser().parseFromString(relsXml, 'application/xml');
            Array.from(relsDoc.getElementsByTagName('Relationship')).forEach(function (r) {
                rels[r.getAttribute('Id')] = r.getAttribute('Target');
            });
        }
        return sheetEls.map(function (el) {
            var name = el.getAttribute('name') || 'Sheet';
            var rId = el.getAttribute('r:id');
            var target = rels[rId] || 'worksheets/sheet1.xml';
            target = target.charAt(0) === '/' ? target.slice(1) : 'xl/' + target;
            return { name: name, path: target };
        });
    }
    function parseSheetXml(xml, sharedStrings) {
        var doc = new DOMParser().parseFromString(xml, 'application/xml');
        var grid = []; var maxRow = -1; var maxCol = -1;
        var rowIdx = 0;
        Array.from(doc.getElementsByTagName('row')).forEach(function (rowEl) {
            Array.from(rowEl.getElementsByTagName('c')).forEach(function (c) {
                var ref = c.getAttribute('r'); if (!ref) return;
                var pos = splitCellRef(ref); if (!pos) return;
                var t = c.getAttribute('t');
                var value = '';
                if (t === 'inlineStr') {
                    var isEl = c.getElementsByTagName('is')[0];
                    value = isEl ? ((isEl.getElementsByTagName('t')[0] ? isEl.getElementsByTagName('t')[0].textContent : '')) : '';
                } else {
                    var vEl = c.getElementsByTagName('v')[0];
                    var raw = vEl ? vEl.textContent : '';
                    if (t === 's') value = sharedStrings[parseInt(raw, 10)] || '';
                    else if (t === 'str') value = raw;
                    else if (t === 'b') value = raw === '1' ? 'TRUE' : 'FALSE';
                    else if (t === 'e') value = '';
                    else value = raw === '' ? '' : parseFloat(raw);
                }
                // Rows are indexed sequentially (row element order), matching the
                // server's parseSheetXml so header/data row indices line up.
                if (!grid[rowIdx]) grid[rowIdx] = [];
                grid[rowIdx][pos.col] = value;
                if (pos.col > maxCol) maxCol = pos.col;
            });
            maxRow = rowIdx;
            rowIdx++;
        });
        for (var r = 0; r <= maxRow; r++) if (!grid[r]) grid[r] = [];
        return { grid: grid, maxRow: maxRow, maxCol: maxCol };
    }

    // ── Coded price (MICHAELSON cipher) ──
    var CODE_CIPHER = { M: '1', I: '2', C: '3', H: '4', A: '5', E: '6', L: '7', S: '8', O: '9', N: '0' };
    function codeToPrice(code) {
        if (!code) return null;
        var clean = String(code).trim().toUpperCase().replace(/[^A-Z]/g, '');
        if (!clean) return null;
        var digits = '';
        for (var i = 0; i < clean.length; i++) {
            if (!(clean[i] in CODE_CIPHER)) return null;
            digits += CODE_CIPHER[clean[i]];
        }
        var n = parseFloat(digits);
        return isNaN(n) ? null : n;
    }

    // ── Mapping heuristics (port of Fazt Sale guessImportLabel) ──
    function guessImportLabel(header) {
        var c = String(header || '').toLowerCase();
        if (c.indexOf('part') !== -1 && c.indexOf('name') === -1 && c.indexOf('desc') === -1) return 'Part No.';
        if (c.indexOf('desc') !== -1 || c.indexOf('part name') !== -1 || c === 'name') return 'Description';
        if ((c.indexOf('cost') !== -1 || c.indexOf('dealer') !== -1 || c.indexOf('w/o vat') !== -1) && c.indexOf('total') === -1) return 'Cost Price';
        if (c.indexOf('srp') !== -1 || (c.indexOf('sell') !== -1 && c.indexOf('basis') === -1) || (c.indexOf('price') !== -1 && c.indexOf('cost') === -1 && c.indexOf('total') === -1)) return 'Sell Price';
        if (c.indexOf('qty') !== -1 || c.indexOf('quantity') !== -1 || c.indexOf('stock') !== -1 || c === 'qoh' || c.indexOf('on hand') !== -1) return 'Quantity';
        if (c.indexOf('code') !== -1 && c.indexOf('part') === -1 && c.indexOf('name') === -1) return 'Code Price';
        return header || '(unnamed column)';
    }
    function resolveFieldType(label) {
        var c = String(label || '').trim().toLowerCase();
        if (!c) return 'custom';
        if (c === 'part no.' || c === 'part no' || c.indexOf('part number') !== -1 || c === 'partno') return 'Part No.';
        if (c === 'description' || (c.indexOf('desc') !== -1 && c.indexOf('code') === -1)) return 'Description';
        if (c === 'cost price' || (c.indexOf('cost') !== -1 && c.indexOf('desc') === -1 && c.indexOf('total') === -1 && c.indexOf('basis') === -1)) return 'Cost Price';
        if (c === 'sell price' || (c.indexOf('sell') !== -1 && c.indexOf('basis') === -1) || c.indexOf('srp') !== -1 || (c.indexOf('price') !== -1 && c.indexOf('cost') === -1 && c.indexOf('total') === -1 && c.indexOf('code') === -1)) return 'Sell Price';
        if (c === 'quantity' || c === 'qty' || c.indexOf('quantity') !== -1 || c === 'qoh' || c.indexOf('on hand') !== -1 || c.indexOf('stock') !== -1) return 'Quantity';
        if (c === 'code (store)') return 'Code (store)';
        if (c === 'code' || c === 'code price' || (c.indexOf('code') !== -1 && c.indexOf('name') === -1 && c.indexOf('part') === -1)) return 'Code Price';
        return 'custom';
    }
    function fieldToServerKey(fieldType) {
        switch (fieldType) {
            case 'Part No.': return 'part_number';
            case 'Description': return 'description';
            case 'Cost Price': return 'cost';
            case 'Sell Price': return 'price';
            case 'Quantity': return 'qty';
            case 'Code Price': return 'code';
            case 'Code (store)': return 'code_attr';
            default: return 'custom';
        }
    }

    // ── Wizard state machine ──
    var st = {
        grid: [], maxRow: -1, maxCol: -1,
        sheets: [], sheetIdx: 0, sheetPath: null,
        headerRow: 0, dataStartRow: 1, dataEndRow: null,
        cols: [], mappings: [], pendingRows: [],
        brandId: 0, branchId: 0, file: null,
        templateMode: '__auto__', template: null
    };

    // ── Brand template support ──
    var TEMPLATE_FIELD_LABELS = {
        part_number: 'Part No.', description: 'Description', cost: 'Cost Price',
        price: 'Sell Price', qty: 'Quantity', code: 'Code Price', code_attr: 'Code (store)'
    };
    function templateFieldLabel(field) {
        if (String(field).indexOf('custom:') === 0) return String(field).slice(7);
        return TEMPLATE_FIELD_LABELS[field] || field;
    }
    // Map a template field key to the wizard's role. This is authoritative for
    // template pre-fills: a custom field labelled "Qty Stock" or "Code Price"
    // must stay a custom field even though the label matches a heuristic.
    function fieldKeyToRole(field) {
        if (String(field).indexOf('custom:') === 0) return 'custom';
        var roles = {
            part_number: 'Part No.', description: 'Description', cost: 'Cost Price',
            price: 'Sell Price', qty: 'Quantity', code: 'Code Price', code_attr: 'Code (store)'
        };
        return roles[field] || 'custom';
    }
    // Effective role of a mapping row: an explicit template role wins; a
    // user-typed label falls back to the label heuristics.
    function mappingRole(m) {
        if (m && m.role) return m.role;
        return resolveFieldType(m ? m.label : '');
    }
    function resolveAutoTemplate(sheetName) {
        var reg = window.MOTO_IMPORT_TEMPLATES || {};
        var keys = Object.keys(reg);
        var target = String(sheetName || '').toLowerCase();
        for (var i = 0; i < keys.length; i++) {
            var t = reg[keys[i]];
            if (t && t.kind === 'preset' && t.sheet && String(t.sheet).toLowerCase() === target) return t;
        }
        return null;
    }
    // Turn a template's mapping (col → field, tolerant of field → col) into the
    // wizard's st.mappings rows. Returns true when applied.
    function applyTemplateMappings(tmpl) {
        var map = tmpl && tmpl.mapping ? tmpl.mapping : null;
        if (!map) return false;
        var colToField = {};
        Object.keys(map).forEach(function (k) {
            var v = map[k];
            var col = parseInt(k, 10);
            if (!isNaN(col) && typeof v === 'string') { colToField[col] = v; }
            else if (/^\d+$/.test(String(k)) === false && (typeof v === 'number' || /^\d+$/.test(String(v)))) { colToField[parseInt(v, 10)] = k; }
        });
        var cols = Object.keys(colToField).map(Number).filter(function (c) { return c >= 0 && c <= st.maxCol; }).sort(function (a, b) { return a - b; });
        if (!cols.length) return false;
        st.mappings = cols.map(function (c) {
            var header = (st.grid[st.headerRow] && st.grid[st.headerRow][c] != null) ? String(st.grid[st.headerRow][c]) : '';
            var field = colToField[c];
            return { colIdx: c, header: header, label: templateFieldLabel(field), role: fieldKeyToRole(field) };
        });
        return true;
    }
    function prepareRangeStep() {
        $('mi-wiz-cols').value = 'A-' + colIndexToLetter(st.maxCol);
        if (st.template) {
            var hrow = Math.max(1, parseInt(st.template.header_row, 10) || 1);
            var drow = Math.max(hrow + 1, parseInt(st.template.data_start_row, 10) || (hrow + 1));
            $('mi-wiz-row-from').value = hrow;
        } else {
            $('mi-wiz-row-from').value = 1;
        }
        $('mi-wiz-row-to').value = st.maxRow + 1;
        showStep('range');
        updateRangePreview();
    }

    function showStep(step) {
        ['sheet', 'range', 'mapping', 'review'].forEach(function (s) {
            var el = $('mi-wiz-step-' + s);
            if (el) el.style.display = (s === step) ? 'block' : 'none';
        });
        var note = { sheet: 'Step 1 · Choose sheet', range: 'Step 2 · Choose columns & rows', mapping: 'Step 3 · Map columns', review: 'Step 4 · Review before staging' };
        var n = $('mi-wiz-note');
        if (n) n.textContent = note[step] || '';
    }

    function parseColumnSpec(spec, maxColIdx) {
        var set = {};
        String(spec).split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (part) {
            var m = /^([A-Za-z]+)(?:-([A-Za-z]+))?$/.exec(part);
            if (!m) return;
            var start = colLetterToIndex(m[1]);
            var end = m[2] ? colLetterToIndex(m[2]) : start;
            for (var c = Math.min(start, end); c <= Math.max(start, end); c++) if (c <= maxColIdx) set[c] = true;
        });
        return Object.keys(set).map(Number).sort(function (a, b) { return a - b; });
    }

    function loadSheet(sheetPath) {
        var xml = st.rawEntries[sheetPath];
        if (!xml) throw new Error('Sheet data not found: ' + sheetPath);
        var parsed = parseSheetXml(xml, st.sharedStrings);
        st.grid = parsed.grid; st.maxRow = parsed.maxRow; st.maxCol = parsed.maxCol;
    }

    function updateRangePreview() {
        var spec = $('mi-wiz-cols').value || ('A-' + colIndexToLetter(st.maxCol));
        st.cols = parseColumnSpec(spec, st.maxCol);
        var rowFrom = Math.max(1, parseInt($('mi-wiz-row-from').value, 10) || 1);
        var rowTo = Math.min(st.maxRow + 1, parseInt($('mi-wiz-row-to').value, 10) || (st.maxRow + 1));
        st.headerRow = rowFrom - 1;
        st.dataStartRow = rowFrom;
        st.dataEndRow = rowTo - 1;
        var info = $('mi-wiz-range-info');
        if (info) info.textContent = 'Header row ' + (rowFrom) + ' · data rows ' + (rowFrom + 1) + '–' + (rowTo) + ' (' + Math.max(0, rowTo - rowFrom) + ' rows) · columns ' + spec;
        var headerCells = st.cols.map(function (c) { return (st.grid[st.headerRow] && st.grid[st.headerRow][c] != null) ? String(st.grid[st.headerRow][c]) : ''; });
        var preview = [];
        for (var r = st.dataStartRow; r <= Math.min(st.dataEndRow, st.dataStartRow + 9); r++) preview.push(st.cols.map(function (c) { return (st.grid[r] && st.grid[r][c] != null) ? String(st.grid[r][c]) : ''; }));
        var html = '<table class="mi-table"><thead><tr>';
        st.cols.forEach(function (c, i) { html += '<th>' + colIndexToLetter(c) + '<br><span class="mi-muted">"' + esc(headerCells[i]) + '"</span></th>'; });
        html += '</tr></thead><tbody>';
        preview.forEach(function (row) { html += '<tr>' + row.map(function (v) { return '<td>' + esc(v) + '</td>'; }).join('') + '</tr>'; });
        html += '</tbody></table>';
        var box = $('mi-wiz-range-preview');
        if (box) box.innerHTML = html;
    }

    function renderMappingRows() {
        var box = $('mi-wiz-map-rows');
        box.innerHTML = st.mappings.map(function (m, idx) {
            return '<div class="mi-row" style="margin-bottom:8px;align-items:center">' +
                '<span class="mi-muted" style="flex:1;min-width:150px">"' + esc(m.header) + '" (col ' + colIndexToLetter(m.colIdx) + ') →</span>' +
                '<input type="text" class="mi-input" data-map-idx="' + idx + '" value="' + esc(m.label) + '" style="flex:1;min-width:150px;margin:0 8px" aria-label="Field label">' +
                '<span class="mi-role-pill" data-map-flag="' + idx + '" style="min-width:120px;text-align:center"></span>' +
                '<button type="button" class="mi-btn ghost" data-map-remove="' + idx + '">✕</button>' +
                '</div>';
        }).join('');
        Array.prototype.forEach.call(box.querySelectorAll('input[data-map-idx]'), function (inp) {
            inp.addEventListener('input', function () {
                var m = st.mappings[+inp.dataset.mapIdx];
                m.label = inp.value;
                delete m.role; // a hand-typed label re-resolves from its text
                validateMappings();
            });
        });
        Array.prototype.forEach.call(box.querySelectorAll('[data-map-remove]'), function (btn) {
            btn.addEventListener('click', function () { st.mappings.splice(+btn.dataset.mapRemove, 1); renderMappingRows(); });
        });
        validateMappings();
    }

    function validateMappings() {
        var counts = {};
        st.mappings.forEach(function (m, idx) {
            var t = mappingRole(m);
            var flag = document.querySelector('[data-map-flag="' + idx + '"]');
            if (flag) { flag.textContent = t === 'custom' ? 'custom field' : t; flag.className = 'mi-role-pill ' + (t === 'custom' ? 'viewer' : 'admin'); }
            if (t !== 'custom') counts[t] = (counts[t] || 0) + 1;
        });
        // A template may synthesize the part number (description / composite)
        // so no Part No. column is required in the mapping step.
        var pnSynthesized = st.template && (st.template.part_number_source === 'description' || st.template.part_number_source === 'composite');
        var ok = true, msg = '';
        if (!counts['Part No.'] && !pnSynthesized) { ok = false; msg = 'Exactly one column must map to Part No. — none currently does.'; }
        else if (counts['Part No.'] > 1) { ok = false; msg = "More than one column maps to Part No. — that's ambiguous."; }
        ['Description', 'Cost Price', 'Sell Price', 'Quantity', 'Code Price', 'Code (store)'].forEach(function (f) {
            if ((counts[f] || 0) > 1) { ok = false; msg = 'More than one column maps to ' + f + ' — that\'s ambiguous.'; }
        });
        if ((counts['Sell Price'] || 0) > 0 && (counts['Code Price'] || 0) > 0) { ok = false; msg = "Sell Price and Code Price both map to the item's price — pick one."; }
        if ((counts['Code Price'] || 0) > 0 && (counts['Code (store)'] || 0) > 0) { ok = false; msg = "A column cannot be both a Code Price and a stored code — pick one."; }
        var warn = $('mi-wiz-map-warning');
        var next = $('mi-wiz-map-next');
        if (warn) warn.style.display = ok ? 'none' : 'block';
        if (warn) warn.textContent = msg;
        if (next) next.disabled = !ok;
    }

    function populateAddColumnPicker() {
        var sel = $('mi-wiz-add-col');
        var opts = [];
        for (var c = 0; c <= st.maxCol; c++) {
            var header = (st.grid[st.headerRow] && st.grid[st.headerRow][c] != null) ? String(st.grid[st.headerRow][c]) : '(blank)';
            opts.push('<option value="' + c + '">' + colIndexToLetter(c) + ' — "' + esc(header) + '"</option>');
        }
        sel.innerHTML = opts.join('');
    }

    function buildPendingRows() {
        var partCol = null, descCol = null, costCol = null, priceCol = null, qtyCol = null, codeCol = null, codeStoreCol = null;
        var customCols = [];
        st.mappings.forEach(function (m) {
            var t = mappingRole(m);
            if (t === 'Part No.') partCol = m.colIdx;
            else if (t === 'Description') descCol = m.colIdx;
            else if (t === 'Cost Price') costCol = m.colIdx;
            else if (t === 'Sell Price') priceCol = m.colIdx;
            else if (t === 'Quantity') qtyCol = m.colIdx;
            else if (t === 'Code Price') codeCol = m.colIdx;
            else if (t === 'Code (store)') codeStoreCol = m.colIdx;
            else customCols.push(m);
        });
        var pnSource = st.template ? (st.template.part_number_source || 'column') : 'column';
        var pnCompositeCols = (st.template && st.template.part_number_cols) || [];
        var pnSep = (st.template && st.template.part_number_sep) || ' ';
        var rows = [], undecodable = 0;
        for (var r = st.dataStartRow; r <= st.dataEndRow; r++) {
            var row = st.grid[r];
            if (!row) continue;
            var rawPart = partCol != null ? row[partCol] : null;
            var part = rawPart == null ? '' : String(rawPart).trim();
            if (!part && pnSource === 'description' && descCol != null) {
                part = row[descCol] == null ? '' : String(row[descCol]).trim();
            }
            if (!part && pnSource === 'composite') {
                var bits = [];
                for (var ci = 0; ci < pnCompositeCols.length; ci++) {
                    var cv = row[pnCompositeCols[ci]];
                    if (cv != null && String(cv).trim() !== '') bits.push(String(cv).trim());
                }
                part = bits.join(pnSep);
            }
            if (!part) continue;
            var desc = descCol != null ? String(row[descCol] || '') : '';
            if (!desc && pnSource === 'composite' && part) desc = part;
            var extra = {};
            customCols.forEach(function (m) { var v = row[m.colIdx]; extra[m.label] = v == null ? '' : String(v); });
            var code = '', price = null;
            if (codeStoreCol) {
                code = row[codeStoreCol] == null ? '' : String(row[codeStoreCol]).trim().toUpperCase();
                if (priceCol) price = parseFloat(String(row[priceCol]).replace(/,/g, '')) || 0;
            } else if (codeCol) {
                code = row[codeCol] == null ? '' : String(row[codeCol]).trim().toUpperCase();
                if (code) { var p = codeToPrice(code); if (p == null) { undecodable++; price = 0; } else { price = p; } }
            } else if (priceCol) {
                price = parseFloat(String(row[priceCol]).replace(/,/g, '')) || 0;
            }
            rows.push({
                part: part,
                desc: desc,
                cost: costCol != null ? (parseFloat(String(row[costCol]).replace(/,/g, '')) || 0) : 0,
                price: price,
                qty: qtyCol != null ? (parseFloat(String(row[qtyCol]).replace(/,/g, '')) || 0) : null,
                code: code,
                extra: extra
            });
        }
        return { rows: rows, undecodable: undecodable };
    }

    function renderReview() {
        var built = buildPendingRows();
        st.pendingRows = built.rows;
        var box = $('mi-wiz-review-body');
        var html = '<div class="mi-grid cols-3">' +
            '<div class="mi-stat"><div class="mi-stat-label">Rows with part no.</div><div class="mi-stat-value">' + built.rows.length + '</div></div>' +
            '<div class="mi-stat"><div class="mi-stat-label">Price source</div><div class="mi-stat-value">' + (st.mappings.some(function (m) { return mappingRole(m) === 'Code Price'; }) ? 'Code (decoded)' : (st.mappings.some(function (m) { return mappingRole(m) === 'Code (store)'; }) ? 'Sell + stored code' : 'Sell price')) + '</div></div>' +
            '<div class="mi-stat"><div class="mi-stat-label">Custom fields</div><div class="mi-stat-value">' + st.mappings.filter(function (m) { return mappingRole(m) === 'custom'; }).length + '</div></div>' +
            '</div>';
        if (built.rows.length) {
            var first = built.rows[0];
            html += '<p class="mi-muted" style="margin-top:10px">Sample: <strong>' + esc(first.part) + '</strong> · ' + esc(first.desc) +
                ' · price <strong>' + (first.price == null ? '—' : (typeof S.fmtMoney === 'function' ? S.fmtMoney(first.price) : first.price)) + '</strong></p>';
        }
        if (built.undecodable) {
            html += '<div class="mi-banner error" style="margin-top:10px"><strong>' + built.undecodable + ' row(s)</strong> have a code containing a letter outside M-I-C-H-A-E-L-S-O-N and will import with price 0. Check the source data.</div>';
        }
        if (st.mappings.some(function (m) { return mappingRole(m) === 'Quantity'; })) {
            html += '<p class="mi-muted" style="margin-top:8px">Quantity column mapped — new items will use it; existing items are only updated if you check "Also overwrite quantity" on the next screen.</p>';
        }
        box.innerHTML = html;
    }

    function buildMappingPayload() {
        var mapping = {};
        st.mappings.forEach(function (m) {
            var t = mappingRole(m);
            var key = fieldToServerKey(t);
            mapping[key === 'custom' ? ('custom:' + m.label) : key] = m.colIdx;
        });
        return mapping;
    }

    function init(opts) {
        st.brandId = opts.brandId; st.branchId = opts.branchId; st.file = opts.file;
        st.templateMode = opts.templateMode || '__auto__';
        st.template = opts.template || null;
        var errBox = $('mi-imp-file-error') || null;
        function fail(msg) { if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; } else if (typeof S.toast === 'function') S.toast(msg, true); }

        (async function () {
            try {
                var buf = await st.file.arrayBuffer();
                st.rawEntries = await readZipEntries(buf);
                st.sharedStrings = parseSharedStrings(st.rawEntries['xl/sharedStrings.xml']);
                st.sheets = listSheets(st.rawEntries);
                var wizard = $('mi-imp-wizard');
                if (!wizard) return fail('Import wizard is not available on this page.');
                wizard.style.display = 'block';

                // Auto mode: match a bundled preset to the sheet it was derived
                // from (e.g. a "HONDA GEN" sheet auto-applies the HONDA GEN
                // template). Explicit preset/custom selections use the chosen
                // template directly; __custom__ maps manually.
                if (st.templateMode === '__auto__') {
                    st.template = null;
                    for (var si = 0; si < st.sheets.length; si++) {
                        var auto = resolveAutoTemplate(st.sheets[si].name);
                        if (auto) { st.template = auto; break; }
                    }
                }

                if (st.sheets.length === 1) {
                    st.sheetIdx = 0;
                    st.sheetPath = st.sheets[0].path;
                    loadSheet(st.sheetPath);
                    prepareRangeStep();
                } else {
                    var defaultIdx = 0;
                    if (st.template && st.template.sheet) {
                        for (var si2 = 0; si2 < st.sheets.length; si2++) {
                            if (String(st.sheets[si2].name).toLowerCase() === String(st.template.sheet).toLowerCase()) { defaultIdx = si2; break; }
                        }
                    }
                    st.sheetIdx = defaultIdx;
                    var list = $('mi-wiz-sheet-list');
                    list.innerHTML = st.sheets.map(function (s, i) {
                        return '<label class="mi-row" style="gap:8px;padding:8px;border:1px solid var(--line);border-radius:6px;margin-bottom:6px;cursor:pointer">' +
                            '<input type="radio" name="mi-wiz-sheet" value="' + i + '"' + (i === defaultIdx ? ' checked' : '') + '>' +
                            '<span>' + esc(s.name) + '</span></label>';
                    }).join('');
                    showStep('sheet');
                }
            } catch (e) {
                fail(e.message === 'NO_DECOMPRESSION_STREAM'
                    ? "This browser can't read .xlsx directly (needs the DecompressionStream API — try a recent Chrome, Edge, or Firefox)."
                    : "Couldn't read that file: " + e.message);
            }
        })();

        var sheetNext = $('mi-wiz-sheet-next');
        if (sheetNext) sheetNext.addEventListener('click', function () {
            var sel = document.querySelector('input[name="mi-wiz-sheet"]:checked');
            if (!sel) return;
            st.sheetIdx = +sel.value;
            st.sheetPath = st.sheets[st.sheetIdx].path;
            // Auto mode re-matches the bundled preset to the chosen sheet so a
            // TIRE sheet, for example, applies the TIRE template.
            if (st.templateMode === '__auto__') {
                st.template = resolveAutoTemplate(st.sheets[st.sheetIdx].name);
            }
            try { loadSheet(st.sheetPath); } catch (e) { return fail(e.message); }
            prepareRangeStep();
        });

        var rangeBack = $('mi-wiz-range-back');
        if (rangeBack) rangeBack.addEventListener('click', function () { showStep(st.sheets.length > 1 ? 'sheet' : 'range'); });
        ['mi-wiz-cols', 'mi-wiz-row-from', 'mi-wiz-row-to'].forEach(function (id) {
            var el = $(id);
            if (el) el.addEventListener('input', updateRangePreview);
        });
        var rangeNext = $('mi-wiz-range-next');
        if (rangeNext) rangeNext.addEventListener('click', function () {
            updateRangePreview();
            // A template pre-fills the mapping; otherwise guess from headers.
            var applied = st.template ? applyTemplateMappings(st.template) : false;
            if (!applied) {
                st.mappings = st.cols.map(function (c) {
                    var header = (st.grid[st.headerRow] && st.grid[st.headerRow][c] != null) ? String(st.grid[st.headerRow][c]) : '';
                    return { colIdx: c, header: header, label: guessImportLabel(header) };
                });
            }
            populateAddColumnPicker();
            renderMappingRows();
            showStep('mapping');
        });

        var mapBack = $('mi-wiz-map-back');
        if (mapBack) mapBack.addEventListener('click', function () { showStep('range'); });
        var addBtn = $('mi-wiz-add-col-btn');
        if (addBtn) addBtn.addEventListener('click', function () {
            var colIdx = +$('mi-wiz-add-col').value;
            if (isNaN(colIdx)) return;
            if (st.mappings.some(function (m) { return m.colIdx === colIdx; })) { if (typeof S.toast === 'function') S.toast('That column is already mapped.', true); return; }
            var header = (st.grid[st.headerRow] && st.grid[st.headerRow][colIdx] != null) ? String(st.grid[st.headerRow][colIdx]) : '';
            st.mappings.push({ colIdx: colIdx, header: header, label: guessImportLabel(header) });
            renderMappingRows();
        });
        var mapNext = $('mi-wiz-map-next');
        if (mapNext) mapNext.addEventListener('click', function () {
            validateMappings();
            if (mapNext.disabled) return;
            renderReview();
            showStep('review');
        });

        var reviewBack = $('mi-wiz-review-back');
        if (reviewBack) reviewBack.addEventListener('click', function () { showStep('mapping'); });
        var stageBtn = $('mi-wiz-stage');
        if (stageBtn) stageBtn.addEventListener('click', function () {
            var mapping = buildMappingPayload();
            if (typeof opts.onStage === 'function') {
                opts.onStage(mapping, st.headerRow, st.dataStartRow, st.dataEndRow, st.sheetIdx);
            }
        });
    }

    // Payload for saving the current mapping as a reusable custom template.
    function buildTemplatePayload() {
        var hasCodeAttr = st.mappings.some(function (m) { return mappingRole(m) === 'Code (store)'; });
        var hasCodePrice = st.mappings.some(function (m) { return mappingRole(m) === 'Code Price'; });
        return {
            sheet: (st.sheets[st.sheetIdx] || {}).name || '',
            header_row: st.headerRow + 1,
            data_start_row: st.dataStartRow + 1,
            mapping: buildMappingPayload(),
            code_mode: hasCodeAttr ? 'attribute' : (hasCodePrice ? 'decode' : 'attribute'),
            part_number_source: 'column'
        };
    }

    window.MOTO_IMPORT_WIZARD = {
        init: init,
        buildTemplatePayload: buildTemplatePayload,
        codeToPrice: codeToPrice,
        resolveFieldType: resolveFieldType,
        guessImportLabel: guessImportLabel
    };
})();
