/**
 * PAL Forms JS — autocomplete, creatable selects, quick-create modal, PO row management.
 */
(function () {
    'use strict';

    // ── Autocomplete ──
    window.autocomplete = function (input, type, opts) {
        opts = opts || {};
        var container = document.createElement('div');
        container.className = 'relative';
        input.parentNode.insertBefore(container, input);
        container.appendChild(input);
        var dropdown = document.createElement('div');
        dropdown.className = 'absolute z-50 w-full bg-white border border-gray-300 rounded shadow-lg max-h-48 overflow-y-auto hidden text-sm';
        container.appendChild(dropdown);
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = input.name.replace('_autocomplete', '_id');
        container.appendChild(hidden);
        input.addEventListener('input', function () {
            var q = input.value.trim();
            if (q.length < 1) { dropdown.classList.add('hidden'); dropdown.innerHTML = ''; return; }
            fetch(window.PalRoutes.action('autocomplete') + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    dropdown.innerHTML = '';
                    if (data.length === 0) {
                        var d = document.createElement('div');
                        d.className = 'px-3 py-2 text-xs text-gray-600';
                        d.textContent = opts.noResults || 'No results. Type to create new.';
                        dropdown.appendChild(d);
                    } else {
                        data.forEach(function (item) {
                            var d = document.createElement('div');
                            d.className = 'px-3 py-2 cursor-pointer hover:bg-blue-50 border-b border-gray-100 last:border-b-0';
                            var labelEl = document.createElement('div');
                            labelEl.className = 'font-medium text-sm';
                            labelEl.textContent = item.label || '';
                            d.appendChild(labelEl);
                            if (item.sublabel) {
                                var subEl = document.createElement('div');
                                subEl.className = 'text-xs text-gray-600';
                                subEl.textContent = item.sublabel;
                                d.appendChild(subEl);
                            }
                            d.addEventListener('click', function () {
                                input.value = item.label;
                                hidden.value = item.id;
                                dropdown.classList.add('hidden');
                                if (opts.onSelect) opts.onSelect(item);
                            });
                            dropdown.appendChild(d);
                        });
                    }
                    dropdown.classList.remove('hidden');
                })
                .catch(function () { });
        });
        input.addEventListener('blur', function () { setTimeout(function () { dropdown.classList.add('hidden'); }, 200); });
        input.addEventListener('focus', function () { if (dropdown.children.length > 0) dropdown.classList.remove('hidden'); });
        input.dataset.autocomplete = 'true';
        input.name = input.name + '_autocomplete';
    };

    // ── Creatable select ──
    window.makeCreatable = function (select) {
        var opt = document.createElement('option');
        opt.value = '__other__';
        opt.textContent = '✦ Other: type new...';
        select.appendChild(opt);
        select.addEventListener('change', function () {
            if (select.value === '__other__') {
                window.showQuickCreateModal(select);
                select.value = '';
            }
        });
    };

    // ── Quick-create modal (uses shared palDialog) ──
    window.showQuickCreateModal = function (select) {
        var type = select.getAttribute('data-creatable') || '';
        if (!type) return;
        var title = 'Add New ' + type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' ');
        palDialog({
            title: title,
            input: true,
            placeholder: 'Enter name',
            confirmLabel: 'Add',
            onClose: function (result) {
                if (result.cancelled || !result.value) return;
                var name = result.value.trim();
                if (!name) { window.showToast('Enter a name', 'error'); return; }
                doQuickCreate(select, type, name);
            }
        });
    };

    function doQuickCreate(select, type, name) {
        var fd = new FormData();
        fd.append('type', type);
        fd.append('name', name);
        var csrf = document.querySelector('input[name="_token"]');
        if (csrf) fd.append('_token', csrf.value);
        var timedOut = false;
        var timer = setTimeout(function () { timedOut = true; window.showToast('Request timed out', 'error'); }, 10000);
        fetch(window.PalRoutes.action('quick_create'), { method: 'POST', body: fd })
            .then(function (r) { if (timedOut) return; clearTimeout(timer); return r.json(); })
            .then(function (d) {
                if (timedOut) return;
                if (d.ok) {
                    var o = document.createElement('option');
                    o.value = d.id; o.textContent = d.label; o.selected = true;
                    select.add(o);
                    select.value = d.id;
                    window.showToast('Added: ' + d.label);
                } else { window.showToast(d.error || 'Failed', 'error'); }
            })
            .catch(function () { if (!timedOut) { clearTimeout(timer); window.showToast('Failed to create', 'error'); } });
    }

    // ── PO row management ──
    window.addPoRow = function () {
        var row = document.querySelector('.po-row');
        if (!row) return;
        var clone = row.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        clone.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; if (s.dataset && s.dataset.creatable) { window.makeCreatable(s); } });
        clone.querySelector('.line-total').textContent = '\u20B10.00';
        document.getElementById('po-items').appendChild(clone);
    };

})();
