/**
 * PAL Core JS — toast, lightbox, AJAX, mobile sidebar, approvals, CSV export.
 * Shared across all PAL admin pages.
 */
(function () {
  'use strict';

  // ── Mobile sidebar ──
  window.toggleMobileSidebar = function () {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    if (!sidebar || !overlay) return;
    var isOpen = sidebar.classList.contains('-translate-x-full');
    if (isOpen) {
      sidebar.classList.remove('-translate-x-full');
      overlay.classList.remove('hidden');
    } else {
      window.closeMobileSidebar();
    }
  };
  window.closeMobileSidebar = function () {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    if (sidebar) sidebar.classList.add('-translate-x-full');
    if (overlay) overlay.classList.add('hidden');
  };

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
  window.openLightbox = function (url, caption) {
    var lb = document.getElementById('lightbox');
    var img = document.getElementById('lightbox-img');
    var cap = document.getElementById('lightbox-caption');
    if (!lb || !img) return;
    img.src = url;
    if (cap) cap.textContent = caption || '';
    lb.classList.add('active');
    var closeBtn = lb.querySelector('.lightbox-close');
    if (closeBtn) closeBtn.focus();
    lb._escHandler = function (e) { if (e.key === 'Escape') window.closeLightbox(); };
    document.addEventListener('keydown', lb._escHandler);
  };
  window.closeLightbox = function () {
    var lb = document.getElementById('lightbox');
    if (!lb) return;
    lb.classList.remove('active');
    if (lb._escHandler) {
      document.removeEventListener('keydown', lb._escHandler);
      lb._escHandler = null;
    }
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
          if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
      })
      .catch(function () {
        window.showToast('Network error', 'error');
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

  // ── Approvals ──
  window.approve = function (id) {
    if (!confirm('Approve this request? This action cannot be undone.')) return;
    decide(id, 'approved');
  };
  window.reject = function (id) {
    var remarks = prompt('Rejection reason (required):');
    if (remarks === null) return;
    if (!remarks.trim()) {
      window.showToast('A rejection reason is required.', 'error');
      return;
    }
    decide(id, 'rejected', remarks);
  };
  function decide(id, decision, remarks) {
    var body = 'decision=' + encodeURIComponent(decision) + '&remarks=' + encodeURIComponent(remarks || '');
    var tokenBody = csrfBody();
    if (tokenBody) body += '&' + tokenBody;
    fetch('/api/v1/project-audit-ledger/approvals/' + id + '/decide', {
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
    fetch('/api/v1/project-audit-ledger/users/' + id + '/delete', {
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
    fetch('/api/v1/project-audit-ledger/attachments/' + id + '/delete', {
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
    fetch('/api/v1/project-audit-ledger/attachments', { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { window.showToast('File uploaded'); setTimeout(function () { location.reload(); }, 600); }
        else { window.showToast(d.error || 'Upload failed', 'error'); btn.disabled = false; btn.textContent = orig; }
      })
      .catch(function () { window.showToast('Upload failed', 'error'); btn.disabled = false; btn.textContent = orig; });
    return false;
  };

})();
