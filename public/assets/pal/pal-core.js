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
    window.showToast = function (msg, type) {
        type = type || 'success';
        var colors = { success: 'bg-green-600', error: 'bg-red-600', info: 'bg-blue-600', warning: 'bg-yellow-500 text-yellow-900' };
        var el = document.createElement('div');
        el.className = (colors[type] || 'bg-gray-700') + ' text-white px-4 py-2 rounded-lg shadow-lg text-sm transition-opacity duration-300';
        el.textContent = msg;
        el.setAttribute('role', 'alert');
        var container = document.getElementById('toast-container');
        if (container) container.appendChild(el);
        setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 4000);
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
                    setTimeout(function () { location.reload(); }, 600);
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
                if (d.ok) { window.showToast(msg || 'Saved'); setTimeout(function () { location.reload(); }, 400); }
                else { window.showToast(d.error || 'Failed', 'error'); }
            })
            .catch(function () { window.showToast('Request failed', 'error'); });
    };

    // ── Accessible dialog (reusable) ──
    function palDialog(options) {
        var overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[10000] bg-black/50 flex items-center justify-center';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', options.title || 'Confirm');
        var box = document.createElement('div');
        box.className = 'bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4';
        box.innerHTML = '<h3 class="text-lg font-semibold text-gray-900 mb-2">' + (options.title || '') + '</h3>'
            + (options.body ? '<div class="text-sm text-gray-700 mb-4">' + options.body + '</div>' : '')
            + (options.input ? '<input type="text" id="pal-dialog-input" class="w-full px-3 py-2 border border-gray-300 rounded text-sm mb-4" placeholder="' + (options.placeholder || '') + '">' : '')
            + '<div class="flex gap-2 justify-end">'
            + '<button id="pal-dialog-cancel" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-300">' + (options.cancelLabel || 'Cancel') + '</button>'
            + '<button id="pal-dialog-confirm" class="px-4 py-2 text-white text-sm rounded-lg hover:opacity-90 ' + (options.confirmClass || 'bg-blue-600 hover:bg-blue-700') + '">' + (options.confirmLabel || 'OK') + '</button>'
            + '</div>';
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        var inputEl = document.getElementById('pal-dialog-input');
        var result = { value: null, cancelled: true };

        function cleanup() {
            document.body.removeChild(overlay);
            if (options.onClose) options.onClose(result);
        }

        document.getElementById('pal-dialog-confirm').addEventListener('click', function () {
            result.cancelled = false;
            result.value = inputEl ? inputEl.value.trim() : true;
            cleanup();
        });
        document.getElementById('pal-dialog-cancel').addEventListener('click', cleanup);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) cleanup(); });
        overlay.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') cleanup();
        });
        if (inputEl) inputEl.focus();
        else document.getElementById('pal-dialog-confirm').focus();
    }

    // ── Approvals ──
    window.approve = function (id) {
        palDialog({
            title: 'Approve Request',
            body: 'This action cannot be undone. The entity will be marked as approved and any side effects (stock movements, cost updates) will execute.',
            confirmLabel: 'Approve',
            confirmClass: 'bg-green-600 hover:bg-green-700',
            onClose: function (result) {
                if (!result.cancelled) decide(id, 'approved');
            }
        });
    };
    window.reject = function (id) {
        palDialog({
            title: 'Reject Request',
            body: 'Please provide a reason for rejection.',
            input: true,
            placeholder: 'Rejection reason (required)',
            confirmLabel: 'Reject',
            confirmClass: 'bg-red-600 hover:bg-red-700',
            onClose: function (result) {
                if (result.cancelled) return;
                if (!result.value) {
                    window.showToast('A rejection reason is required.', 'error');
                    window.reject(id); // Re-open
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
                if (d.ok) { window.showToast(decision === 'approved' ? 'Approved' : 'Rejected'); setTimeout(function () { location.reload(); }, 600); }
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
        if (!confirm('Toggle this user\'s active status?')) return;
        var body = csrfBody();
        fetch(window.PalRoutes.action('user.toggle', id), {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) { window.showToast(d.action === 'restored' ? 'User reactivated' : 'User deactivated'); setTimeout(function () { location.reload(); }, 600); }
                else { window.showToast(d.error || 'Failed', 'error'); }
            })
            .catch(function () { window.showToast('Request failed', 'error'); });
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
        if (!confirm('Delete this PO image?')) return;
        var body = csrfBody();
        fetch(window.PalRoutes.action('attachment.delete', id), {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) { window.showToast('Deleted'); setTimeout(function () { location.reload(); }, 400); }
                else { window.showToast(d.error || 'Failed', 'error'); }
            })
            .catch(function () { window.showToast('Request failed', 'error'); });
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
                if (d.ok) { window.showToast('File uploaded'); setTimeout(function () { location.reload(); }, 600); }
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
