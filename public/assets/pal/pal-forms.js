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
    // Idempotent: each <select data-creatable> is wired at most once, so a
    // DOMContentLoaded auto-init and any page-level inline bootstrap scripts
    // can safely coexist without appending duplicate "Other" options.
    window.makeCreatable = function (select) {
        if (!select || select.dataset.palCreatableWired === '1') return;
        select.dataset.palCreatableWired = '1';
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

    // ── Creatable select auto-init ──
    // Wires every present <select data-creatable> without needing an inline
    // <script> in each page template. Inline templates historically wrote
    // bootstrap scripts like `forEach(function(e){makeCreatable(e);})` which
    // DiSyL's script-block interpolation can mangle (curly braces are also
    // DiSyL delimiters), producing invalid JS. Centralize that wiring here.
    window.wireCreatableSelects = function (root) {
        var scope = root || document;
        var selects = scope.querySelectorAll ? scope.querySelectorAll('select[data-creatable]') : [];
        for (var i = 0; i < selects.length; i++) {
            window.makeCreatable(selects[i]);
        }
    };

    function initCreatableSelects() {
        window.wireCreatableSelects(document);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCreatableSelects);
    } else {
        initCreatableSelects();
    }

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

    // ── Settings: Data Reset ──
    window.palConfirmFullReset = function () {
        var form = document.getElementById('pal-full-reset-form');
        if (!form) return;
        palDialog({
            title: 'Full Data Reset',
            bodyText: 'This permanently deletes ALL business data (projects, quotations, sales, collections, purchases, expenses, inventory & materials, cash advances, mobilization, fabrication, clients, suppliers, team leads, attachments, audit trail, approvals) and all user accounts EXCEPT the currently logged-in admin. Branding and system settings are preserved. This cannot be undone.',
            input: true,
            placeholder: 'Type RESET to confirm',
            confirmLabel: 'Reset All Data',
            confirmClass: 'bg-red-600 hover:bg-red-700',
            onClose: function (result) {
                if (result.cancelled) return;
                if ((result.value || '').toUpperCase() !== 'RESET') {
                    window.showToast('Reset cancelled — you did not type RESET.', 'error');
                    return;
                }
                ajaxSubmit(form, 'All data reset');
            }
        });
    };

    window.palConfirmGranularReset = function () {
        var form = document.getElementById('pal-granular-reset-form');
        if (!form) return;
        var checked = form.querySelectorAll('input[name="groups[]"]:checked');
        if (checked.length === 0) {
            window.showToast('Select at least one group to reset.', 'error');
            return;
        }
        var labels = Array.prototype.map.call(checked, function (c) { return c.dataset.label || c.value; });
        palDialog({
            title: 'Reset Selected Data',
            fields: [{ label: 'Groups', value: labels.length + ' selected' }],
            bodyText: 'This permanently deletes the selected data. Related approval records are cleared automatically. This cannot be undone.',
            confirmLabel: 'Reset Selected (' + labels.length + ')',
            confirmClass: 'bg-red-600 hover:bg-red-700',
            onClose: function (result) {
                if (result.cancelled) return;
                ajaxSubmit(form, 'Selected data reset');
            }
        });
    };

})();
