/* global DLOfflineVault */
'use strict';

/**
 * Daily Ledger — Offline Shell Controller
 * =======================================
 * Deterministic state machine for the token-free static shell
 * (offline.html). It never holds a cloud credential; all data comes from the
 * encrypted vault. States:
 *
 *   Not enrolled / Preparing / Unlock / Offline ready /
 *   Expired / Revoked / Update required / Storage unavailable
 *
 * Enrollment itself happens ONLINE in the authenticated ledger page; this
 * shell is the offline face. Reconnect sync requires an active session
 * cookie (online reauthentication) and validates the enrollment before any
 * write.
 */

(function () {
    var V = DLOfflineVault;
    var state = 'preparing';
    var bootstrap = null;
    var enrollment = null;
    var pendingCount = 0;
    var online = navigator.onLine;
    var syncing = false;
    var backoffMs = 0;
    var MAX_BACKOFF_MS = 60000;
    var syncTimer = null;

    var root = document.getElementById('state-root');

    function el(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    }

    function renderInto(section) {
        root.innerHTML = '';
        root.appendChild(section);
    }

    function setState(next) {
        state = next;
    }

    function fmtDateTime(iso) {
        if (!iso) return '—';
        try {
            var d = new Date(iso);
            return d.toLocaleString();
        } catch (e) {
            return String(iso);
        }
    }

    function escaper(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Prerequisites / diagnostics ──────────────────────────────────────
    function renderPrereqFailure(reason) {
        var messages = {
            'insecure-context': 'Offline access requires a secure (HTTPS) connection on this device.',
            'no-service-worker': 'This browser does not support service workers, which are required for offline access.',
            'no-indexeddb': 'This browser does not support IndexedDB, which is required for offline storage.',
            'no-webcrypto': 'This browser does not support WebCrypto, which is required to protect offline data.'
        };
        var msg = messages[reason] || 'Offline access is not available on this device.';
        var section = el(
            '<section class="card card-pad" role="alert">' +
            '<h2>Offline not available</h2>' +
            '<p class="muted">' + escaper(msg) + '</p>' +
            '<div class="mt-3"><a class="btn btn-primary" href="/daily-ledger/ledger">Go to online ledger</a></div>' +
            '</section>'
        );
        renderInto(section);
    }

    function renderNotEnrolled() {
        var section = el(
            '<section class="card card-pad" role="status">' +
            '<div class="badge badge-slate">Not enrolled</div>' +
            '<h1 class="mt-2">Offline access is not set up on this device</h1>' +
            '<p class="muted mt-2">Open the online Daily Ledger while connected, then choose <b>Enable offline access</b> on the ledger page. PIN creation alone does not enable offline access.</p>' +
            '<div class="mt-3"><a class="btn btn-primary" href="/daily-ledger/ledger">Open online ledger</a></div>' +
            '</section>'
        );
        renderInto(section);
    }

    function renderPreparing() {
        var section = el(
            '<section class="card card-pad" role="status">' +
            '<div class="badge badge-amber">Preparing</div>' +
            '<h1 class="mt-2">Checking offline vault…</h1>' +
            '<p class="muted mt-2">Reading the encrypted vault on this device.</p>' +
            '</section>'
        );
        renderInto(section);
    }

    function renderUnlock() {
        var section = el(
            '<section class="card card-pad" role="status">' +
            '<div class="badge badge-indigo">Locked</div>' +
            '<h1 class="mt-2">Unlock offline ledger</h1>' +
            '<p class="muted mt-2">Enter the offline PIN for this cashier on this device to view and edit while offline.</p>' +
            '<div class="mt-3">' +
            '<input type="password" inputmode="numeric" id="offline-pin" maxlength="6" autocomplete="off" placeholder="••••••" style="letter-spacing:.4em;text-align:center;font-size:20px;width:160px;">' +
            '</div>' +
            '<p id="unlock-msg" class="muted mt-2 hidden" style="color:#be123c;font-weight:600;"></p>' +
            '<div class="mt-3 row">' +
            '<button type="button" class="btn btn-primary" id="unlock-btn">Unlock</button>' +
            '<button type="button" class="btn btn-outline" id="go-online-btn">Open online ledger</button>' +
            '</div>' +
            '</section>'
        );
        renderInto(section);

        var input = document.getElementById('offline-pin');
        var msg = document.getElementById('unlock-msg');
        setTimeout(function () { if (input) input.focus(); }, 50);

        function attemptUnlock() {
            var pin = input ? input.value : '';
            if (!/^\d{4,6}$/.test(pin)) {
                if (msg) { msg.classList.remove('hidden'); msg.textContent = 'Enter a 4–6 digit PIN.'; }
                return;
            }
            if (msg) msg.classList.add('hidden');
            V.unlock(pin).then(function (res) {
                if (res.ok) {
                    return onUnlocked();
                }
                var reasonText = {
                    'bad-pin': 'Incorrect PIN.',
                    'locked': 'Too many attempts. Try again in a few minutes.',
                    'corrupt': 'The offline vault is unreadable on this device. Remove offline access online and re-enroll.',
                    'expired': 'Offline access has expired. Open the online ledger to re-enroll.',
                    'revoked': 'Offline access was revoked. Open the online ledger to re-enroll.',
                    'storage': 'Device storage is unavailable.'
                }[res.reason] || 'Unable to unlock.';
                if (msg) { msg.classList.remove('hidden'); msg.textContent = reasonText; }
                if (input) { input.value = ''; input.focus(); }
            }).catch(function () {
                if (msg) { msg.classList.remove('hidden'); msg.textContent = 'Unable to unlock on this device.'; }
            });
        }

        document.getElementById('unlock-btn').addEventListener('click', attemptUnlock);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') attemptUnlock(); });
        document.getElementById('go-online-btn').addEventListener('click', function () { window.location.href = '/daily-ledger/ledger'; });
    }

    function onUnlocked() {
        renderPreparing();
        V.getBootstrap()
            .then(function (boot) {
                bootstrap = boot;
                enrollment = null;
                return V.getEnrollment().then(function (en) { enrollment = en; });
            })
            .then(function () {
                return V.countPending().then(function (n) { pendingCount = n; });
            })
            .then(function () {
                // Best-effort legacy migration (fresh online re-enrollment required;
                // this only imports scoped reference/queue records into the vault).
                return V.migrateLegacy().catch(function () { return null; });
            })
            .then(function () {
                return V.countPending().then(function (n) { pendingCount = n; });
            })
            .then(renderReady)
            .catch(function (err) {
                if (err && err.message === 'scope-mismatch') {
                    renderScopeMismatch();
                } else {
                    renderStorageUnavailable();
                }
            });
    }

    function renderScopeMismatch() {
        var section = el(
            '<section class="card card-pad" role="alert">' +
            '<div class="badge badge-red">Scope changed</div>' +
            '<h1 class="mt-2">This offline data no longer matches your session</h1>' +
            '<p class="muted mt-2">Tenant, cashier, branch, business date, or shift changed. Lock this device, then open the online ledger to re-enroll on the current scope.</p>' +
            '<div class="mt-3 row">' +
            '<button type="button" class="btn btn-outline" id="lock-scope-btn">Lock</button>' +
            '<a class="btn btn-primary" href="/daily-ledger/ledger">Open online ledger</a>' +
            '</div>' +
            '</section>'
        );
        renderInto(section);
        document.getElementById('lock-scope-btn').addEventListener('click', function () {
            V.lock().then(renderUnlock);
        });
    }

    function renderStorageUnavailable() {
        var section = el(
            '<section class="card card-pad" role="alert">' +
            '<div class="badge badge-red">Storage unavailable</div>' +
            '<h1 class="mt-2">Offline storage is not available on this device</h1>' +
            '<p class="muted mt-2">The encrypted vault could not be read or written. This can happen in private browsing, after storage was cleared, or when the device is out of quota. No unsynced data can be recovered once it is gone.</p>' +
            '<div class="mt-3"><a class="btn btn-primary" href="/daily-ledger/ledger">Open online ledger</a></div>' +
            '</section>'
        );
        renderInto(section);
    }

    function renderExpired() {
        var section = el(
            '<section class="card card-pad" role="alert">' +
            '<div class="badge badge-amber">Expired</div>' +
            '<h1 class="mt-2">Offline access has expired</h1>' +
            '<p class="muted mt-2">Re-enroll from the online ledger to continue offline. Pending work on this device is preserved until you re-enroll or remove access.</p>' +
            '<div class="mt-3"><a class="btn btn-primary" href="/daily-ledger/ledger">Open online ledger</a></div>' +
            '</section>'
        );
        renderInto(section);
    }

    function renderRevoked() {
        var section = el(
            '<section class="card card-pad" role="alert">' +
            '<div class="badge badge-red">Revoked</div>' +
            '<h1 class="mt-2">Offline access was revoked</h1>' +
            '<p class="muted mt-2">This device is no longer authorized for offline access. Open the online ledger to re-enroll. Pending work is preserved until you remove access.</p>' +
            '<div class="mt-3"><a class="btn btn-primary" href="/daily-ledger/ledger">Open online ledger</a></div>' +
            '</section>'
        );
        renderInto(section);
    }

    function renderUpdateRequired() {
        var section = el(
            '<section class="card card-pad" role="alert">' +
            '<div class="badge badge-amber">Update required</div>' +
            '<h1 class="mt-2">A Daily Ledger update is required</h1>' +
            '<p class="muted mt-2">The offline data format changed. Open the online ledger while connected to update. Pending work is preserved.</p>' +
            '<div class="mt-3"><a class="btn btn-primary" href="/daily-ledger/ledger">Open online ledger</a></div>' +
            '</section>'
        );
        renderInto(section);
    }

    // ── Offline ready (the ledger) ───────────────────────────────────────
    function renderReady() {
        if (!bootstrap) { renderStorageUnavailable(); return; }
        var boot = bootstrap;
        var dayOpen = boot.day_status === 'open' && !boot.reference_only;
        var canEdit = dayOpen;

        var statusBadge = online
            ? '<div class="badge badge-green">Offline ready · Connected</div>'
            : '<div class="badge badge-green">Offline ready</div>';

        var blocked = [];
        if (boot.pos_enabled && boot.can_pos_sell) blocked.push('POS (online only)');
        blocked.push('Day close (online only)');
        if (boot.formal_delivery_enabled) blocked.push('Send to branch / dispatch (online only)');
        if (boot.can_edit_delivery) blocked.push('Delivery correction (online only)');
        if (!dayOpen) blocked.push('Edits while this day is closed or reference-only');

        var rows = (boot.ledger_rows || []).map(function (r) {
            var inputs = '';
            if (canEdit) {
                inputs = '<input type="number" class="num" data-product="' + escaper(r.product_id) + '" data-field="beg_bal" value="' + escaper(r.beg_bal != null ? r.beg_bal : 0) + '" min="0">' +
                    '<input type="number" class="num" data-product="' + escaper(r.product_id) + '" data-field="addtl" value="' + escaper(r.addtl != null ? r.addtl : 0) + '" min="0">' +
                    '<input type="number" class="num" data-product="' + escaper(r.product_id) + '" data-field="withdraw" value="' + escaper(r.withdraw != null ? r.withdraw : 0) + '" min="0">' +
                    '<input type="number" class="num" data-product="' + escaper(r.product_id) + '" data-field="bal_end" value="' + escaper(r.bal_end != null ? r.bal_end : '') + '" min="0">';
            } else {
                inputs = '<span class="num">' + escaper(r.beg_bal != null ? r.beg_bal : 0) + '</span>' +
                    '<span class="num">' + escaper(r.addtl != null ? r.addtl : 0) + '</span>' +
                    '<span class="num">' + escaper(r.withdraw != null ? r.withdraw : 0) + '</span>' +
                    '<span class="num">' + (r.bal_end != null ? escaper(r.bal_end) : '—') + '</span>';
            }
            var salesPending = (r.bal_end == null);
            var sales = salesPending
                ? 'Pending'
                : Math.max(0, Number(r.beg_bal != null ? r.beg_bal : 0) + Number(r.addtl != null ? r.addtl : 0) - Number(r.withdraw != null ? r.withdraw : 0) - Number(r.bal_end));
            return '<tr>' +
                '<td>' + escaper(r.product_id) + '</td>' +
                '<td>' + escaper(r.name) + '</td>' +
                '<td class="num">' + inputs + '</td>' +
                '<td class="num">' + (salesPending ? '<span class="badge badge-amber">Pending</span>' : escaper(sales)) + '</td>' +
                '</tr>';
        }).join('');

        var expiry = enrollment && enrollment.expires_at ? fmtDateTime(enrollment.expires_at) : '—';
        var snapshotAt = boot.snapshot_at ? fmtDateTime(boot.snapshot_at) : '—';

        var section = el(
            '<section>' +
            '<div class="row-between card card-pad">' +
            '<div>' +
            statusBadge +
            '<h1 class="mt-2">Daily Ledger — Offline</h1>' +
            '<p class="muted mt-2">' +
            'Branch: <b>' + escaper((boot.branch && boot.branch.name) || '—') + '</b>' +
            ' · Business date: <b>' + escaper(boot.business_date || '—') + '</b>' +
            ' · Shift: <b>' + escaper(boot.shift || '—') + '</b>' +
            ' · Day: <b>' + escaper(boot.day_status || '—') + '</b>' +
            '</p>' +
            '<p class="muted">Snapshot: ' + snapshotAt + ' · Expires: ' + expiry + '</p>' +
            '</div>' +
            '<div class="ledger-actions">' +
            '<button type="button" class="btn btn-outline" id="reconnect-btn">Reconnect & sync</button>' +
            '<button type="button" class="btn btn-outline" id="lock-btn">Lock</button>' +
            '<button type="button" class="btn btn-danger" id="remove-btn">Remove offline access</button>' +
            '</div>' +
            '</div>' +

            '<div id="sync-banner" class="banner banner-green mt-2 hidden"></div>' +
            '<div id="pending-banner" class="banner ' + (pendingCount > 0 ? 'banner-amber' : 'banner-green') + ' mt-2">' +
            (pendingCount > 0
                ? pendingCount + ' change' + (pendingCount === 1 ? '' : 's') + ' pending on this device — will sync when connected.'
                : 'All changes saved on this device. Nothing to sync.') +
            '</div>' +

            (canEdit
                ? '<div class="card card-pad mt-2">' +
                '<h2>Offline actions</h2>' +
                '<p class="muted">The following are approved for offline use and are queued on this device, then synced when connected.</p>' +
                '<div class="ledger-actions mt-2">' +
                '<button type="button" class="btn btn-outline" id="withdraw-btn">Stock Adjustment</button>' +
                '<button type="button" class="btn btn-outline" id="receive-btn">Receive Paper DR</button>' +
                '</div>' +
                '<div class="divider"></div>' +
                '<h2>Sales Report</h2>' +
                '<div class="table-wrap" style="overflow-x:auto;">' +
                '<table><thead><tr><th>#</th><th>Description</th><th style="width:330px;">Beg / Add / WD / End</th><th>Sales</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table>' +
                '</div>' +
                '<p class="muted mt-2">Changes to the fields above are saved securely on this device (encrypted) before they are marked saved. POS, day close, dispatch, and delivery correction remain online-only.</p>' +
                '</div>'
                : '<div class="card card-pad mt-2">' +
                '<div class="badge badge-slate">Reference only</div>' +
                '<p class="muted mt-2">This day is closed or in reference-only mode. No offline edits are allowed.</p>' +
                '</div>') +

            '<div class="card card-pad mt-2">' +
            '<h2>Online-only actions</h2>' +
            '<p class="muted">These require cloud connectivity and are disabled offline:' +
            '<ul class="plain">' + blocked.map(function (b) { return '<li>' + escaper(b) + '</li>'; }).join('') + '</ul>' +
            '</p>' +
            '</div>' +
            '</section>'
        );

        renderInto(section);

        bindReadyEvents();
    }

    function bindReadyEvents() {
        document.getElementById('lock-btn').addEventListener('click', function () {
            V.lock().then(renderUnlock);
        });

        document.getElementById('reconnect-btn').addEventListener('click', function () {
            runReconcile(true);
        });

        document.getElementById('remove-btn').addEventListener('click', function () {
            removeOfflineAccess();
        });

        if (document.getElementById('withdraw-btn')) {
            document.getElementById('withdraw-btn').addEventListener('click', function () { openWithdrawalModal(); });
        }
        if (document.getElementById('receive-btn')) {
            document.getElementById('receive-btn').addEventListener('click', function () { openReceiveModal(); });
        }

        // Ledger field edits → durable encrypted vault queue.
        document.querySelectorAll('#state-root input.num[data-field]').forEach(function (input) {
            input.addEventListener('change', function () {
                var productId = input.getAttribute('data-product');
                var field = input.getAttribute('data-field');
                var value = parseInt(input.value, 10);
                if (isNaN(value) || value < 0) value = 0;
                input.value = value;
                var op = {
                    product_id: productId,
                    field: field,
                    value: value,
                    date: bootstrap.business_date,
                    branch_id: bootstrap.branch && bootstrap.branch.id,
                    shift: bootstrap.shift
                };
                saveFieldOffline(input, op);
            });
        });
    }

    function saveFieldOffline(input, op) {
        input.disabled = true;
        V.enqueueOperation('ledger_save', op)
            .then(function () {
                pendingCount += 1;
                updatePendingBanner();
                input.classList.add('saved');
                input.disabled = false;
                var banner = document.getElementById('sync-banner');
                if (banner) { banner.className = 'banner banner-green mt-2'; banner.textContent = 'Saved securely on this device. Will sync when connected.'; }
            })
            .catch(function (err) {
                input.disabled = false;
                var banner = document.getElementById('sync-banner');
                if (banner) {
                    banner.className = 'banner banner-red mt-2';
                    banner.textContent = (err && err.message === 'locked') ? 'The offline vault is locked — unlock to save.' : 'Could not save on this device. Reconnect and try again.';
                }
            });
    }

    function updatePendingBanner() {
        var b = document.getElementById('pending-banner');
        if (!b) return;
        b.className = 'banner mt-2 ' + (pendingCount > 0 ? 'banner-amber' : 'banner-green');
        b.textContent = pendingCount > 0
            ? pendingCount + ' change' + (pendingCount === 1 ? '' : 's') + ' pending on this device — will sync when connected.'
            : 'All changes saved on this device. Nothing to sync.';
    }

    function openWithdrawalModal() {
        var body = buildProductLines('withdrawal');
        showModal('Stock Adjustment (offline)', body, function () {
            var header = {
                withdrawal_type: document.getElementById('wd-type').value,
                reason_code: document.getElementById('wd-reason').value,
                custom_reason: document.getElementById('wd-custom').value,
                dr_number: document.getElementById('wd-dr').value,
                target_branch_id: document.getElementById('wd-target').value
            };
            if (header.reason_code === 'other' && !header.custom_reason) {
                window.alert('A custom reason is required when reason is Other.');
                return null;
            }
            var lines = collectLines('wd-lines');
            if (lines.length === 0) { window.alert('Add at least one product with a quantity greater than 0.'); return null; }
            return {
                type: 'withdrawal',
                payload: {
                    header: header,
                    lines: lines,
                    date: bootstrap.business_date,
                    branch_id: bootstrap.branch && bootstrap.branch.id,
                    shift: bootstrap.shift
                }
            };
        });
    }

    function openReceiveModal() {
        var body = buildReceiveBody();
        showModal('Receive Paper DR (offline)', body, function () {
            var dr = document.getElementById('rc-dr').value.trim();
            var originType = document.getElementById('rc-origin-type').value;
            var originId = document.getElementById('rc-origin-id').value;
            var items = collectLines('rc-lines');
            if (!dr) { window.alert('Paper DR number is required.'); return null; }
            if (originType === 'branch' && !originId) { window.alert('A source branch is required.'); return null; }
            if (items.length === 0) { window.alert('Add at least one item.'); return null; }
            return {
                type: 'receive_paper_dr',
                payload: {
                    origin_type: originType,
                    origin_id: originId ? parseInt(originId, 10) : null,
                    dr_number: dr,
                    delivery_date: bootstrap.business_date,
                    receive_date: bootstrap.business_date,
                    items: items,
                    branch_id: bootstrap.branch && bootstrap.branch.id,
                    shift: bootstrap.shift
                }
            };
        });
    }

    function buildProductLines(prefix) {
        var products = (bootstrap.products || []).map(function (p) {
            return '<option value="' + escaper(p.id) + '">' + escaper(p.name) + '</option>';
        }).join('');
        return '' +
            '<div class="grid2">' +
            (prefix === 'withdrawal'
                ? '<div><label class="form-label">Type</label><select id="wd-type" class="form-input"><option value="charge">Charge</option><option value="pullout">Pullout</option><option value="adjustment_add">Add Stock</option></select></div>' +
                '<div><label class="form-label">Reason</label><select id="wd-reason" class="form-input"><option value="manual_adjustment">Manual adjustment</option><option value="spoilage">Spoilage</option><option value="damage">Damage</option><option value="staff_meal">Staff meal</option><option value="sampling">Sampling</option><option value="testing">Testing</option><option value="promo">Promo</option><option value="donation">Donation</option><option value="other">Other</option></select></div>'
                : '<div><label class="form-label">Origin type</label><select id="rc-origin-type" class="form-input"><option value="commissary">Commissary</option><option value="branch">Branch</option></select></div>' +
                '<div><label class="form-label">Origin branch id</label><input type="number" id="rc-origin-id" class="form-input" placeholder="(empty for commissary)"></div>') +
            '<div><label class="form-label">DR number</label><input type="text" id="' + (prefix === 'withdrawal' ? 'wd-dr' : 'rc-dr') + '" class="form-input" placeholder="e.g. DR-123"></div>' +
            '<div><label class="form-label">Target branch id (pullout only)</label><input type="number" id="wd-target" class="form-input" placeholder="Commissary branch id"></div>' +
            '<div style="grid-column:1/-1;"><label class="form-label">Custom reason (if Other)</label><input type="text" id="wd-custom" class="form-input"></div>' +
            '</div>' +
            '<div id="' + prefix + '-lines" class="mt-3"></div>' +
            '<div class="mt-2"><button type="button" class="btn btn-outline" id="add-' + prefix + '-line">+ Add line</button></div>';
    }

    function buildReceiveBody() {
        var products = (bootstrap.products || []).map(function (p) {
            return '<option value="' + escaper(p.id) + '">' + escaper(p.name) + '</option>';
        }).join('');
        return '' +
            '<div class="grid2">' +
            '<div><label class="form-label">Origin type</label><select id="rc-origin-type" class="form-input"><option value="commissary">Commissary</option><option value="branch">Branch</option></select></div>' +
            '<div><label class="form-label">Origin branch id</label><input type="number" id="rc-origin-id" class="form-input" placeholder="(empty for commissary)"></div>' +
            '<div style="grid-column:1/-1;"><label class="form-label">Paper DR number</label><input type="text" id="rc-dr" class="form-input"></div>' +
            '</div>' +
            '<div id="rc-lines" class="mt-3"></div>' +
            '<div class="mt-2"><button type="button" class="btn btn-outline" id="add-rc-line">+ Add line</button></div>';
    }

    function addLine(containerId, prefix) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var products = (bootstrap.products || []).map(function (p) {
            return '<option value="' + escaper(p.id) + '">' + escaper(p.name) + '</option>';
        }).join('');
        var row = document.createElement('div');
        row.className = 'grid2 mt-2';
        row.style.gridTemplateColumns = '2fr 1fr';
        row.innerHTML = '' +
            '<select class="form-input ' + prefix + '-product">' + products + '</select>' +
            '<div class="row"><input type="number" class="form-input ' + prefix + '-qty" min="1" value="1" style="flex:1;"><button type="button" class="btn btn-outline ' + prefix + '-remove" title="Remove line">✕</button></div>';
        row.querySelector('.' + prefix + '-remove').addEventListener('click', function () { container.removeChild(row); });
        container.appendChild(row);
    }

    function collectLines(containerId) {
        var container = document.getElementById(containerId);
        if (!container) return [];
        var out = [];
        container.querySelectorAll('div').forEach(function (row) {
            var sel = row.querySelector('select');
            var qty = row.querySelector('input[type=number]');
            if (sel && qty) {
                var pid = parseInt(sel.value, 10);
                var q = parseInt(qty.value, 10);
                if (pid > 0 && q > 0) out.push({ product_id: pid, quantity: q });
            }
        });
        return out;
    }

    function showModal(title, body, onOk) {
        var overlay = document.getElementById('modal-overlay');
        var titleEl = document.getElementById('modal-title');
        var bodyEl = document.getElementById('modal-body');
        titleEl.textContent = title;
        bodyEl.innerHTML = body;
        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');

        function close() {
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            bodyEl.innerHTML = '';
        }

        document.getElementById('modal-cancel').onclick = close;
        var okBtn = document.getElementById('modal-ok');
        okBtn.onclick = function () {
            var op = onOk();
            if (!op) return;
            okBtn.disabled = true;
            V.enqueueOperation(op.type, op.payload)
                .then(function () {
                    pendingCount += 1;
                    updatePendingBanner();
                    close();
                    var banner = document.getElementById('sync-banner');
                    if (banner) { banner.className = 'banner banner-green mt-2'; banner.textContent = 'Queued securely on this device. Will sync when connected.'; }
                })
                .catch(function () {
                    okBtn.disabled = false;
                    window.alert('Could not save on this device. Reconnect and try again.');
                });
        };

        // Wire dynamic add-line buttons after insertion.
        setTimeout(function () {
            var addWd = document.getElementById('add-withdrawal-line');
            if (addWd) addWd.addEventListener('click', function () { addLine('withdrawal-lines', 'wd'); });
            var addRc = document.getElementById('add-rc-line');
            if (addRc) addRc.addEventListener('click', function () { addLine('rc-lines', 'rc'); });
            if (document.getElementById('withdrawal-lines')) addLine('withdrawal-lines', 'wd');
            if (document.getElementById('rc-lines')) addLine('rc-lines', 'rc');
        }, 0);
    }

    // ── Remove offline access / logout / lock ────────────────────────────
    function removeOfflineAccess() {
        var pendingMsg = pendingCount > 0
            ? 'There ' + (pendingCount === 1 ? 'is' : 'are') + ' ' + pendingCount + ' unsynced change' + (pendingCount === 1 ? '' : 's') + ' on this device. Removing offline access will keep the changes visible here until you remove them, but they will no longer be synced. Continue?'
            : 'Remove offline access for this cashier on this device? This revokes the enrollment and clears the encrypted vault.';
        if (!window.confirm(pendingMsg)) return;

        // Revoke online first (best-effort; uses the session cookie).
        V.getStoredEnrollmentId().then(function (enId) {
            return V.ensureDeviceId().then(function (deviceId) {
                return {
                    enrollment_id: enId,
                    device_id: deviceId,
                    attemptRevoke: function () {
                        return fetch('/daily-ledger/api/v1/offline/revoke', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (window.DL_CSRF || '') },
                            body: JSON.stringify({ enrollment_id: enId, device_id: deviceId })
                        }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
                    }
                };
            });
        }).then(function (ctx) {
            var doClear = function () {
                return V.clearEnrollment().then(function () {
                    return window.caches.open('daily-ledger-pwa-v6').then(function (cache) {
                        return cache.delete('/daily-ledger/offline.html').catch(function () { });
                    }).catch(function () { });
                });
            };
            if (!ctx.enrollment_id) {
                // Nothing enrolled server-side; clear locally.
                return doClear();
            }
            return ctx.attemptRevoke().then(function (res) {
                // Even if revocation failed (offline), the user chose to remove local
                // access — clear the local vault so no decrypted data remains.
                return doClear();
            });
        }).then(function () {
            renderNotEnrolled();
        }).catch(function () {
            window.alert('Could not remove offline access on this device.');
        });
    }

    // ── Reconnect / sync ─────────────────────────────────────────────────
    function runReconcile(userInitiated) {
        if (syncing) return;
        if (!online) {
            showSyncBanner('Offline — will sync when a connection returns.', 'amber');
            return;
        }
        syncing = true;
        if (userInitiated) showSyncBanner('Reconnecting…', 'amber');

        V.getStoredEnrollmentId().then(function (enId) {
            return V.ensureDeviceId().then(function (deviceId) {
                return { enrollment_id: enId, device_id: deviceId };
            });
        }).then(function (ctx) {
            if (!ctx.enrollment_id) { syncing = false; return; }

            return fetch('/daily-ledger/api/v1/offline/status?enrollment_id=' + encodeURIComponent(ctx.enrollment_id) + '&device_id=' + encodeURIComponent(ctx.device_id), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.json().catch(function () { return {}; }); })
                .then(function (status) {
                    if (!status.ok) {
                        var reason = status.reason || '';
                        if (reason === 'expired') { setState('expired'); renderExpired(); }
                        else if (reason === 'revoked') { setState('revoked'); renderRevoked(); }
                        else if (reason === 'auth' || reason === 'not-found' || status.error === 'Auth required') {
                            showSyncBanner('Reconnect online (log in) to sync.', 'amber');
                        } else {
                            showSyncBanner(status.error || 'Unable to sync.', 'red');
                        }
                        syncing = false;
                        return;
                    }
                    // Validate versions for update-required detection.
                    var v = status.server_versions || {};
                    if (v.schema_version && Number(v.schema_version) !== V.SCHEMA_VERSION) {
                        setState('update-required');
                        renderUpdateRequired();
                        syncing = false;
                        return;
                    }
                    return V.listOperations().then(function (ops) {
                        if (ops.length === 0) {
                            backoffMs = 0;
                            showSyncBanner('Connected. Nothing to sync.', 'green');
                            syncing = false;
                            return;
                        }
                        return syncBatch(ctx, ops);
                    });
                })
                .catch(function () {
                    syncing = false;
                    showSyncBanner('Connection lost — will retry.', 'red');
                });
        }).catch(function () {
            syncing = false;
        });
    }

    function syncBatch(ctx, ops) {
        var payload = ops.map(function (op) {
            return {
                client_op_id: op.client_op_id,
                type: op.type,
                base_version: op.base_version,
                payload: op.payload
            };
        });

        return fetch('/daily-ledger/api/v1/offline/reconcile', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (window.DL_CSRF || '') },
            body: JSON.stringify({
                enrollment_id: ctx.enrollment_id,
                device_id: ctx.device_id,
                operations: payload
            })
        }).then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (res) {
                if (!res.ok) {
                    if (res.reason === 'expired') { setState('expired'); renderExpired(); }
                    else if (res.reason === 'revoked') { setState('revoked'); renderRevoked(); }
                    else if (res.error === 'Auth required') { showSyncBanner('Reconnect online (log in) to sync.', 'amber'); }
                    else { showSyncBanner(res.error || 'Sync failed.', 'red'); }
                    syncing = false;
                    return;
                }

                var results = res.results || [];
                var keep = [];
                var remove = [];

                results.forEach(function (r) {
                    if (r.status === 'applied' || r.duplicate) {
                        V.saveReceipt({ client_op_id: r.client_op_id, result: r.result || {}, duplicate: !!r.duplicate, status: r.status }).catch(function () { });
                        remove.push(r.client_op_id);
                    } else if (r.status === 'rejected' || r.status === 'conflict') {
                        V.quarantineRecord({ reason: r.status, source_key: 'offline-reconcile', payload: { client_op_id: r.client_op_id, type: r.type, error: r.error } }).catch(function () { });
                        remove.push(r.client_op_id);
                    } else {
                        // server_error — keep for retry.
                        keep.push(r.client_op_id);
                    }
                });

                var removals = remove.map(function (id) { return V.removeOperation(id).catch(function () { }); });
                return Promise.all(removals).then(function () {
                    return V.countPending().then(function (n) {
                        pendingCount = n;
                        updatePendingBanner();
                        if (keep.length > 0) {
                            backoffMs = backoffMs === 0 ? 2000 : Math.min(backoffMs * 2, MAX_BACKOFF_MS);
                            showSyncBanner(keep.length + ' item' + (keep.length === 1 ? '' : 's') + ' will retry in ~' + Math.round(backoffMs / 1000) + 's.', 'amber');
                            scheduleRetry(backoffMs);
                        } else {
                            backoffMs = 0;
                            showSyncBanner('Synced. All changes sent to the cloud.', 'green');
                        }
                        syncing = false;
                    });
                });
            })
            .catch(function () {
                syncing = false;
                backoffMs = backoffMs === 0 ? 2000 : Math.min(backoffMs * 2, MAX_BACKOFF_MS);
                showSyncBanner('Connection lost — will retry in ~' + Math.round(backoffMs / 1000) + 's.', 'red');
                scheduleRetry(backoffMs);
            });
    }

    function scheduleRetry(ms) {
        if (syncTimer) clearTimeout(syncTimer);
        syncTimer = setTimeout(function () { if (online) runReconcile(false); }, ms);
    }

    function showSyncBanner(text, kind) {
        var b = document.getElementById('sync-banner');
        if (!b) return;
        b.className = 'banner banner-' + kind + ' mt-2';
        b.textContent = text;
    }

    // ── Boot / launch messaging ──────────────────────────────────────────
    function init() {
        var prereq = V.prerequisites();
        if (!prereq.ok) {
            renderPrereqFailure(prereq.reason);
            return;
        }

        // Launch messages from the online ledger (same origin, controlled page):
        //   {type:'dl-offline-activated'}  → enrollment finished online; lock shell
        //     so the next unlock reads the fresh vault.
        //   {type:'dl-offline-locked'}     → logout / lock from the online page.
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.addEventListener('message', function (event) {
                var d = event.data;
                if (!d || !d.type) return;
                if (d.type === 'dl-offline-activated' || d.type === 'dl-offline-locked') {
                    V.lock().then(function () {
                        renderUnlock();
                    });
                }
            });
        }

        window.addEventListener('online', function () {
            online = true;
            if (state === 'offline-ready') {
                showSyncBanner('Connected — syncing…', 'amber');
                runReconcile(false);
            }
        });
        window.addEventListener('offline', function () {
            online = false;
            if (state === 'offline-ready') {
                showSyncBanner('Offline — will sync when a connection returns.', 'amber');
            }
        });
        // Periodic reconcile attempt while connected.
        setInterval(function () { if (online && state === 'offline-ready' && !syncing) runReconcile(false); }, 30000);

        V.hasLocalEnrollment().then(function (has) {
            if (!has) {
                setState('not-enrolled');
                renderNotEnrolled();
                return;
            }
            // Try to auto-unlock if keys still in memory (page reload mid-session).
            if (V.isUnlocked()) {
                onUnlocked();
                return;
            }
            setState('unlock');
            renderUnlock();
        }).catch(function () {
            renderStorageUnavailable();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
