/**
 * PAL Core JS — toast, lightbox, AJAX, mobile sidebar, approvals, CSV export.
 * Shared across all PAL admin pages.
 */
(function () {
    'use strict';

    // ── Mobile sidebar ──
    var _sidebarOpener = null;
    window.toggleMobileSidebar = function () {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebar-overlay');
        var btn = document.getElementById('mobile-menu-btn');
        if (!sidebar || !overlay) return;
        var isOpen = !sidebar.classList.contains('-translate-x-full');
        if (isOpen) {
            window.closeMobileSidebar();
        } else {
            _sidebarOpener = document.activeElement;
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            overlay.setAttribute('aria-hidden', 'false');
            if (btn) btn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
            // Focus first link in sidebar
            var firstLink = sidebar.querySelector('a');
            if (firstLink) setTimeout(function () { firstLink.focus(); }, 100);
        }
    };
    window.closeMobileSidebar = function () {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebar-overlay');
        var btn = document.getElementById('mobile-menu-btn');
        if (sidebar) sidebar.classList.add('-translate-x-full');
        if (overlay) { overlay.classList.add('hidden'); overlay.setAttribute('aria-hidden', 'true'); }
        if (btn) btn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (_sidebarOpener) { _sidebarOpener.focus(); _sidebarOpener = null; }
    };
    // Close sidebar on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var sidebar = document.getElementById('sidebar');
            if (sidebar && !sidebar.classList.contains('-translate-x-full')) {
                window.closeMobileSidebar();
            }
        }
    });

    // ── Toast (accessible) ──
    function toastContainer() {
        var container = document.getElementById('toast-container') || document.getElementById('wb-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'fixed top-4 right-4 z-[9999] flex flex-col gap-2';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            document.body.appendChild(container);
        }
        return container;
    }

    window.showToast = function (msg, type) {
        type = type || 'success';
        var colors = { success: 'bg-green-600', error: 'bg-red-600', info: 'bg-blue-600', warning: 'bg-yellow-500 text-yellow-900' };
        var el = document.createElement('div');
        el.className = (colors[type] || 'bg-gray-700') + ' text-white px-4 py-2 rounded-lg shadow-lg text-sm transition-opacity duration-300';
        el.textContent = msg;
        el.setAttribute('role', 'alert');
        try {
            toastContainer().appendChild(el);
            setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 4000);
        } catch (e) {
            // Never let a toast failure break the surrounding action.
            console.error('showToast failed:', e);
        }
    };

    // ── Lightbox (accessible dialog) ──
    var _lightboxOpener = null;
    window.openLightbox = function (url, caption) {
        var lb = document.getElementById('lightbox');
        var img = document.getElementById('lightbox-img');
        var cap = document.getElementById('lightbox-caption');
        if (!lb || !img) return;
        _lightboxOpener = document.activeElement;
        img.src = url;
        if (cap) cap.textContent = caption || '';
        lb.classList.add('active');
        var closeBtn = lb.querySelector('.lightbox-close');
        if (closeBtn) closeBtn.focus();
        // Focus trap: keep focus inside lightbox
        lb._focusTrap = function (e) {
            if (e.key === 'Escape') { window.closeLightbox(); return; }
            if (e.key !== 'Tab') return;
            var focusable = lb.querySelectorAll('button, [tabindex]:not([tabindex="-1"])');
            if (focusable.length === 0) return;
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        };
        document.addEventListener('keydown', lb._focusTrap);
    };
    window.closeLightbox = function () {
        var lb = document.getElementById('lightbox');
        if (!lb) return;
        lb.classList.remove('active');
        if (lb._focusTrap) {
            document.removeEventListener('keydown', lb._focusTrap);
            lb._focusTrap = null;
        }
        if (_lightboxOpener) { _lightboxOpener.focus(); _lightboxOpener = null; }
    };

    // ── CSRF helper ──
    function csrfBody() {
        var input = document.querySelector('input[name="_token"]');
        return input ? input.name + '=' + encodeURIComponent(input.value) : '';
    }
    function csrfFormData() {
        var fd = new FormData();
        var input = document.querySelector('input[name="_token"]');
        if (input) fd.append('_token', input.value);
        return fd;
    }

    // ── AJAX form submit (with field-level errors) ──
    window.ajaxSubmit = function (form, successMsg) {
        if (form.dataset.submitting === '1') return false;
        form.dataset.submitting = '1';
        form.querySelectorAll('.field-error').forEach(function (e) { e.remove(); });
        form.querySelectorAll('.border-red-500').forEach(function (e) { e.classList.remove('border-red-500'); });
        var btn = form.querySelector('button[type="submit"]');
        var orig = btn ? btn.textContent : 'Submit';
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        var data = new FormData(form);
        fetch(form.action, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    if (d.redirect) { window.location.href = d.redirect; return; }
                    window.showToast(successMsg || 'Saved');
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    window.showToast(d.error || 'Request failed', 'error');
                    if (d.errors && typeof d.errors === 'object') {
                        var firstField = null;
                        Object.keys(d.errors).forEach(function (fieldName) {
                            var field = form.querySelector('[name="' + fieldName + '"]');
                            if (field) {
                                field.classList.add('border-red-500');
                                var errEl = document.createElement('p');
                                errEl.className = 'field-error text-red-600 text-xs mt-1';
                                errEl.textContent = d.errors[fieldName];
                                field.parentNode.appendChild(errEl);
                                if (!firstField) firstField = field;
                            }
                        });
                        if (firstField) firstField.focus();
                    }
                }
            })
            .catch(function () {
                window.showToast('Network error', 'error');
            })
            .finally(function () {
                delete form.dataset.submitting;
                if (btn) { btn.disabled = false; btn.textContent = orig; }
            });
        return false;
    };

    // ── AJAX POST helper (x-www-form-urlencoded) ──
    window.ajaxPost = function (url, body, msg) {
        var tokenBody = csrfBody();
        if (tokenBody) body += '&' + tokenBody;
        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) { window.showToast(msg || 'Saved'); setTimeout(function () { location.reload(); }, 1500); }
                else { window.showToast(d.error || 'Failed', 'error'); }
            })
            .catch(function () { window.showToast('Request failed', 'error'); });
    };

    // ── Accessible dialog (reusable, DOM-based, focus-trapped) ──
    window.palDialog = function (options) {
        var opener = document.activeElement;
        var overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[10000] bg-black/50 flex items-center justify-center';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', options.title || 'Confirm');

        var box = document.createElement('div');
        box.className = 'bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4';

        // Title
        var titleEl = document.createElement('h3');
        titleEl.className = 'text-lg font-semibold text-gray-900 mb-3';
        titleEl.textContent = options.title || '';
        box.appendChild(titleEl);

        // Fields (structured data — no innerHTML)
        if (options.fields && options.fields.length) {
            var fieldsDiv = document.createElement('div');
            fieldsDiv.className = 'space-y-2 text-sm mb-3';
            options.fields.forEach(function (f) {
                var row = document.createElement('div');
                row.className = 'flex justify-between';
                var label = document.createElement('span');
                label.className = 'text-gray-600';
                label.textContent = f.label || '';
                var value = document.createElement('span');
                value.className = f.valueClass || 'font-medium';
                value.textContent = f.value || '';
                row.appendChild(label);
                row.appendChild(value);
                fieldsDiv.appendChild(row);
            });
            box.appendChild(fieldsDiv);
        }

        // Body text (for non-field content)
        if (options.bodyText) {
            var bodyP = document.createElement('p');
            bodyP.className = 'text-sm text-gray-700 mb-3';
            bodyP.textContent = options.bodyText;
            box.appendChild(bodyP);
        }

        // Notes (italic)
        if (options.notes) {
            var notesDiv = document.createElement('div');
            notesDiv.className = 'text-xs text-gray-500 italic mt-2 border-t pt-2';
            notesDiv.textContent = '"' + options.notes + '"';
            box.appendChild(notesDiv);
        }

        // Input (for prompts)
        var inputEl = null;
        if (options.input) {
            inputEl = document.createElement('input');
            inputEl.type = 'text';
            inputEl.id = 'pal-dialog-input';
            inputEl.className = 'w-full px-3 py-2 border border-gray-300 rounded text-sm mb-3';
            inputEl.placeholder = options.placeholder || '';
            box.appendChild(inputEl);
        }

        // Buttons
        var btnRow = document.createElement('div');
        btnRow.className = 'flex gap-2 justify-end';
        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-300';
        cancelBtn.textContent = options.cancelLabel || 'Cancel';
        var confirmBtn = document.createElement('button');
        confirmBtn.className = 'px-4 py-2 text-white text-sm rounded-lg hover:opacity-90 ' + (options.confirmClass || 'bg-blue-600 hover:bg-blue-700');
        confirmBtn.textContent = options.confirmLabel || 'OK';
        btnRow.appendChild(cancelBtn);
        btnRow.appendChild(confirmBtn);
        box.appendChild(btnRow);

        overlay.appendChild(box);
        document.body.appendChild(overlay);

        var result = { value: null, cancelled: true };
        var focusable = function () {
            return Array.from(box.querySelectorAll('button, input, [tabindex]:not([tabindex="-1"])'));
        };

        function cleanup() {
            document.body.removeChild(overlay);
            if (opener && typeof opener.focus === 'function') opener.focus();
            if (options.onClose) options.onClose(result);
        }

        // Focus trap
        overlay.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { cleanup(); return; }
            if (e.key !== 'Tab') return;
            var els = focusable();
            if (els.length === 0) return;
            var first = els[0], last = els[els.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });

        confirmBtn.addEventListener('click', function () {
            result.cancelled = false;
            result.value = inputEl ? inputEl.value.trim() : true;
            cleanup();
        });
        cancelBtn.addEventListener('click', cleanup);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) cleanup(); });

        if (inputEl) inputEl.focus();
        else confirmBtn.focus();
    }

    // ── Approvals ──
    window.approve = function (id, entityLabel, amount, projectTitle, submitter, notes) {
        var fields = [];
        if (entityLabel) fields.push({ label: 'Type', value: entityLabel });
        if (projectTitle) fields.push({ label: 'Project', value: projectTitle });
        if (amount > 0) fields.push({ label: 'Amount', value: '\u20B1' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2 }), valueClass: 'font-semibold text-gray-900' });
        if (submitter) fields.push({ label: 'Submitted by', value: submitter });
        palDialog({
            title: 'Approve ' + (entityLabel || 'Request'),
            fields: fields,
            notes: notes || null,
            confirmLabel: 'Approve',
            confirmClass: 'bg-green-600 hover:bg-green-700',
            onClose: function (result) {
                if (!result.cancelled) decide(id, 'approved');
            }
        });
    };
    window.reject = function (id, entityLabel, amount, projectTitle) {
        var fields = [];
        if (entityLabel) fields.push({ label: 'Type', value: entityLabel });
        if (projectTitle) fields.push({ label: 'Project', value: projectTitle });
        if (amount > 0) fields.push({ label: 'Amount', value: '\u20B1' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2 }), valueClass: 'font-semibold' });
        palDialog({
            title: 'Reject Request',
            fields: fields,
            bodyText: 'Please provide a reason for rejection.',
            input: true,
            placeholder: 'Rejection reason (required)',
            confirmLabel: 'Reject',
            confirmClass: 'bg-red-600 hover:bg-red-700',
            onClose: function (result) {
                if (result.cancelled) return;
                if (!result.value) {
                    window.showToast('A rejection reason is required.', 'error');
                    window.reject(id, entityLabel, amount, projectTitle);
                    return;
                }
                decide(id, 'rejected', result.value);
            }
        });
    };
    function decide(id, decision, remarks) {
        var body = 'decision=' + encodeURIComponent(decision) + '&remarks=' + encodeURIComponent(remarks || '');
        var tokenBody = csrfBody();
        if (tokenBody) body += '&' + tokenBody;
        fetch(window.PalRoutes.action('approval.decide', id), {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) { window.showToast(decision === 'approved' ? 'Approved' : 'Rejected'); setTimeout(function () { location.reload(); }, 1500); }
                else { window.showToast(d.error || 'Failed', 'error'); }
            })
            .catch(function () { window.showToast('Request failed', 'error'); });
    }

    // ── User management ──
    window.toggleEdit = function (id) {
        var el = document.getElementById('edit-user-' + id);
        if (el) el.classList.toggle('hidden');
    };
    window.switchTab = function (tab) {
        var activeEl = document.getElementById('table-active');
        var inactiveEl = document.getElementById('table-inactive');
        var tabActive = document.getElementById('tab-active');
        var tabInactive = document.getElementById('tab-inactive');
        if (activeEl) activeEl.classList.toggle('hidden', tab !== 'active');
        if (inactiveEl) inactiveEl.classList.toggle('hidden', tab !== 'inactive');
        if (tabActive) tabActive.className = 'px-4 py-2 text-sm font-medium border-b-2 ' + (tab === 'active' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600 hover:text-gray-700');
        if (tabInactive) tabInactive.className = 'px-4 py-2 text-sm font-medium border-b-2 ' + (tab === 'inactive' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600 hover:text-gray-700');
    };
    window.toggleUser = function (id) {
        palDialog({
            title: 'Toggle User Status',
            bodyText: 'Toggle this user\'s active status?',
            confirmLabel: 'Toggle',
            confirmClass: 'bg-yellow-600 hover:bg-yellow-700',
            onClose: function (result) {
                if (result.cancelled) return;
                var body = csrfBody();
                fetch(window.PalRoutes.action('user.toggle', id), {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.ok) { window.showToast(d.action === 'restored' ? 'User reactivated' : 'User deactivated'); setTimeout(function () { location.reload(); }, 1500); }
                        else { window.showToast(d.error || 'Failed', 'error'); }
                    })
                    .catch(function () { window.showToast('Request failed', 'error'); });
            }
        });
    };

    // ── CSV export ──
    window.exportCSV = function (tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) { window.showToast('Table not found', 'error'); return; }
        var rows = table.querySelectorAll('tr');
        var csv = '';
        rows.forEach(function (r) {
            var cols = r.querySelectorAll('th, td');
            var row = [];
            cols.forEach(function (c) { row.push('"' + (c.textContent || '').trim().replace(/"/g, '""') + '"'); });
            csv += row.join(',') + '\n';
        });
        var blob = new Blob([csv], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    };

    // ── Attachment helpers ──
    window.deletePoImage = function (id) {
        palDialog({
            title: 'Delete PO Image',
            bodyText: 'Delete this PO receipt image? This cannot be undone.',
            confirmLabel: 'Delete',
            confirmClass: 'bg-red-600 hover:bg-red-700',
            onClose: function (result) {
                if (result.cancelled) return;
                var body = csrfBody();
                fetch(window.PalRoutes.action('attachment.delete', id), {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.ok) { window.showToast('Deleted'); setTimeout(function () { location.reload(); }, 1500); }
                        else { window.showToast(d.error || 'Failed', 'error'); }
                    })
                    .catch(function () { window.showToast('Request failed', 'error'); });
            }
        });
    };
    window.uploadAttachment = function (form, entityType, entityId) {
        var btn = form.querySelector('button[type="submit"]');
        var orig = btn.textContent;
        btn.disabled = true; btn.textContent = 'Uploading...';
        var data = new FormData(form);
        data.append('entity_type', entityType);
        data.append('entity_id', entityId);
        fetch(window.PalRoutes.action('attachment.upload'), { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) { window.showToast('File uploaded'); setTimeout(function () { location.reload(); }, 1500); }
                else { window.showToast(d.error || 'Upload failed', 'error'); btn.disabled = false; btn.textContent = orig; }
            })
            .catch(function () { window.showToast('Upload failed', 'error'); btn.disabled = false; btn.textContent = orig; });
        return false;
    };

    // ── Responsive tables: auto-generate data-label from thead ──
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.pal-responsive-table').forEach(function (table) {
            var headers = [];
            table.querySelectorAll('thead th').forEach(function (th) {
                headers.push(th.textContent.trim());
            });
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.querySelectorAll('td').forEach(function (td, i) {
                    if (!td.hasAttribute('data-label') && headers[i]) {
                        td.setAttribute('data-label', headers[i]);
                    }
                });
            });
        });
    });

})();
