document.addEventListener('DOMContentLoaded', () => {
    const packageSel = document.getElementById('deploy-package');
    const profileSel = document.getElementById('deploy-profile');
    const detail = document.getElementById('deploy-profile-detail');
    const status = document.getElementById('deploy-status');
    const history = document.getElementById('deploy-history');
    const note = document.getElementById('deploy-inventory-note');
    const goBtn = document.getElementById('deploy-go');
    const confirmBox = document.getElementById('deploy-confirm');
    const confirmPkg = document.getElementById('deploy-confirm-pkg');
    const confirmHost = document.getElementById('deploy-confirm-host');
    const receiptEl = document.getElementById('deploy-receipt');
    const receiptSummaryEl = document.getElementById('deploy-receipt-summary');
    const receiptToggle = document.getElementById('deploy-receipt-toggle');

    const ACTIVE = ['QUEUED', 'CLAIMED', 'UPLOADING', 'EXTRACTING', 'VERIFYING'];
    const LABEL = {
        QUEUED: 'Queued', CLAIMED: 'Claimed by your local client', UPLOADING: 'Uploading…',
        EXTRACTING: 'Extracting…', VERIFYING: 'Verifying…', SUCCEEDED: 'Deployed', FAILED: 'Failed', CANCELLED: 'Cancelled'
    };
    let pollTimer = null;
    let trackedId = null;
    let pollErrors = 0;
    let idemKey = '';

    function resetReceipt() {
        receiptSummaryEl.hidden = true;
        receiptSummaryEl.innerHTML = '';
        receiptEl.hidden = true;
        receiptEl.textContent = '';
        receiptToggle.hidden = true;
        receiptToggle.textContent = 'Show raw receipt';
    }

    function fillSelect(el, options, placeholder) {
        el.innerHTML = '';
        const ph = document.createElement('option');
        ph.value = '';
        ph.textContent = placeholder || '— none —';
        el.appendChild(ph);
        for (const o of options) {
            const op = document.createElement('option');
            op.value = o.value;
            op.textContent = o.label;
            el.appendChild(op);
        }
    }
    function fmtBytes(n) {
        if (n == null) return '?';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(2) + ' MB';
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    // Bypass any browser/service-worker cache so polls always reflect live state.
    async function fetchNoStore(url, opts) {
        return Harpp.fetch(url, Object.assign({ cache: 'no-store' }, opts || {}));
    }

    function renderProfileDetail() {
        const name = profileSel.value;
        const list = window.__deployProfiles || [];
        const p = list.find(x => x.name === name);
        if (!p) { detail.innerHTML = ''; return; }
        detail.innerHTML =
            '<div><b>host</b> ' + esc(p.host) + ' : ' + esc(p.port) + '</div>' +
            '<div><b>user</b> ' + esc(p.user || '—') + ' · <b>transport</b> ' + esc(p.transport) + '</div>' +
            '<div><b>root</b> ' + esc(p.root_path) + '</div>' +
            '<div><b>extraction</b> ' + esc(p.extraction_adapter) + '</div>' +
            '<div><b>ops</b> ' + esc((p.allowed_operations || []).join(', ')) + '</div>';
    }

    function statusPill(state) {
        const el = document.createElement('span');
        el.className = 'pill';
        el.textContent = LABEL[state] || state;
        if (ACTIVE.includes(state)) el.style.background = '#b45309';
        else if (state === 'SUCCEEDED') el.style.background = '#065f46';
        else if (state === 'FAILED') el.style.background = '#7f1d1d';
        return el;
    }

    function renderHistory(jobs) {
        if (!jobs.length) { history.innerHTML = '<li class="muted">(none)</li>'; return; }
        history.innerHTML = '';
        for (const j of jobs) {
            const li = document.createElement('li');
            li.appendChild(statusPill(j.status));
            li.appendChild(document.createTextNode(' #' + j.id + ' ' + j.package_name + ' → ' + j.profile_name));
            const meta = document.createElement('div');
            meta.className = 'muted';
            meta.textContent = (j.created_at || '').replace('T', ' ').slice(0, 19) + (j.error ? ' · ' + j.error : '');
            li.appendChild(meta);
            if (j.can_cancel) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'button';
                btn.textContent = 'Cancel';
                btn.style.marginTop = '.3rem';
                btn.addEventListener('click', async () => {
                    try {
                        await Harpp.fetch('/api/v1/harpp/deploys/' + j.id + '/cancel', { method: 'POST', body: {} });
                        if (trackedId === j.id) trackedId = null;
                        status.textContent = 'Deploy cancelled.';
                        await loadJobs();
                    } catch (e) { status.textContent = e.message; }
                });
                li.appendChild(btn);
            }
            history.appendChild(li);
        }
    }

    async function loadJobs() {
        const jobs = (await fetchNoStore('/api/v1/harpp/deploys')).data.jobs;
        renderHistory(jobs);
        return jobs;
    }

    function renderReceiptSummary(r) {
        const rows = [];
        const a = r.artifact || {};
        const p = r.profile || {};
        const m = r.manifest || {};
        if (a.name) rows.push('<div><b>Package</b> ' + esc(a.name) + '</div>');
        if (a.size != null) rows.push('<div><b>Size</b> ' + esc(fmtBytes(a.size)) + '</div>');
        if (a.sha256) rows.push('<div><b>SHA-256</b> <span class="mono">' + esc(a.sha256) + '</span></div>');
        if (r.created_at) rows.push('<div><b>Completed</b> ' + esc(String(r.created_at).replace('T', ' ').slice(0, 19)) + '</div>');
        if (p.host) rows.push('<div><b>Target</b> ' + esc(p.host + (p.port ? ':' + p.port : '')) + ' · ' + esc(p.transport || '') + (p.user ? ' · ' + esc(p.user) : '') + '</div>');
        if (p.root_path) rows.push('<div><b>Remote path</b> <span class="mono">' + esc(p.root_path) + '</span></div>');
        if (p.extraction_adapter) rows.push('<div><b>Extraction</b> ' + esc(p.extraction_adapter) + '</div>');
        if (Array.isArray(p.allowed_operations) && p.allowed_operations.length) rows.push('<div><b>Allowed ops</b> ' + esc(p.allowed_operations.join(', ')) + '</div>');
        if (Array.isArray(r.steps) && r.steps.length) rows.push('<div><b>Steps</b> ' + esc(r.steps.join(' → ')) + '</div>');
        if (m.member_count != null || m.uncompressed_bytes != null) {
            let txt = (m.member_count != null ? esc(String(m.member_count)) + ' files' : '');
            if (m.uncompressed_bytes != null) txt += (txt ? ', ' : '') + esc(fmtBytes(m.uncompressed_bytes)) + ' uncompressed';
            rows.push('<div><b>Contents</b> ' + txt + '</div>');
        }
        if (Array.isArray(m.allowed_prefixes) && m.allowed_prefixes.length) rows.push('<div><b>Prefixes</b> <span class="mono">' + esc(m.allowed_prefixes.join(', ')) + '</span></div>');
        if (r.remote_temp) rows.push('<div><b>Remote file</b> <span class="mono">' + esc(r.remote_temp) + '</span></div>');
        if (r.rollback) rows.push('<div><b>Rollback</b> ' + esc(r.rollback) + '</div>');
        if (r.manual_action) rows.push('<div class="dim"><b>Manual action</b> ' + esc(r.manual_action) + '</div>');
        return rows.join('');
    }

    async function showReceipt(id) {
        resetReceipt();
        try {
            const job = (await fetchNoStore('/api/v1/harpp/deploys/' + id)).data.job;
            if (job && job.receipt) {
                receiptSummaryEl.innerHTML = '<h3>Receipt</h3>' + renderReceiptSummary(job.receipt);
                receiptSummaryEl.hidden = false;
                receiptEl.textContent = JSON.stringify(job.receipt, null, 2);
                receiptToggle.hidden = false;
            }
            return job;
        } catch (e) {
            status.textContent = 'Receipt unavailable: ' + e.message;
            return null;
        }
    }

    // Poll the active deploy until it reaches a terminal state, then show the receipt.
    async function tick() {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
        try {
            const jobs = await loadJobs();
            pollErrors = 0;
            let active = trackedId ? jobs.find(j => j.id === trackedId) : (jobs.find(j => ACTIVE.includes(j.status)) || null);
            if (active && ACTIVE.includes(active.status)) {
                status.textContent = 'Deploy #' + active.id + ': ' + (LABEL[active.status] || active.status);
                pollTimer = setTimeout(tick, 3000);
                return;
            }
            if (trackedId) {
                const finished = jobs.find(j => j.id === trackedId) || active;
                trackedId = null;
                if (finished) {
                    await showReceipt(finished.id);
                    if (finished.status === 'SUCCEEDED') {
                        status.textContent = 'Deploy #' + finished.id + ' deployed successfully.';
                    } else {
                        status.textContent = 'Deploy #' + finished.id + ' ' + (LABEL[finished.status] || finished.status) +
                            (finished.error ? ' — ' + finished.error : '');
                    }
                }
                return;
            }
            if (active) { // adopt an in-progress job found on page load
                trackedId = active.id;
                pollTimer = setTimeout(tick, 1000);
                return;
            }
            status.textContent = 'Ready';
        } catch (e) {
            pollErrors += 1;
            if (pollErrors >= 6) {
                status.textContent = 'Live updates paused (' + e.message + '). Reload to resume.';
                pollTimer = null;
                return;
            }
            status.textContent = e.message;
            pollTimer = setTimeout(tick, 5000);
        }
    }

    async function loadAll() {
        try {
            const inv = (await fetchNoStore('/api/v1/harpp/deploys/inventory')).data;
            const packages = inv.packages || [];
            const profiles = inv.profiles || [];
            window.__deployProfiles = profiles;
            fillSelect(packageSel, packages.map(p => ({ value: p.name, label: p.name + ' · ' + fmtBytes(p.size) })), 'No packages registered');
            fillSelect(profileSel, profiles.map(p => ({ value: p.name, label: p.name + ' · ' + p.host + ' · ' + p.transport })), 'No profiles registered');
            note.textContent = inv.published_at
                ? 'Registered by your local client ' + inv.published_at.replace('T', ' ').slice(0, 16)
                : 'No inventory yet — start the deploy worker on the local machine.';
            const jobs = await loadJobs();
            status.textContent = 'Ready';
            if (jobs.some(j => ACTIVE.includes(j.status))) tick();
        } catch (e) { status.textContent = e.message; }
    }

    function currentProfile() {
        const list = window.__deployProfiles || [];
        return list.find(x => x.name === profileSel.value) || null;
    }

    goBtn.addEventListener('click', () => {
        const pkg = packageSel.value, prof = currentProfile();
        if (!pkg || !prof) { status.textContent = 'Choose a package and a profile first.'; return; }
        // Per-submit idempotency key: a retry of the same logical request
        // (network drop, double-tap) maps back to the same deploy job instead of
        // queueing a duplicate.
        idemKey = (crypto.randomUUID ? crypto.randomUUID() : 'dep-' + Date.now() + '-' + Math.random().toString(36).slice(2));
        confirmPkg.textContent = pkg;
        confirmHost.textContent = prof.host;
        confirmBox.hidden = false;
    });
    document.getElementById('deploy-confirm-no').addEventListener('click', () => { confirmBox.hidden = true; });
    document.getElementById('deploy-confirm-yes').addEventListener('click', async () => {
        const pkg = packageSel.value, prof = profileSel.value;
        if (!pkg || !prof) return;
        confirmBox.hidden = true;
        goBtn.disabled = true;
        receiptSummaryEl.hidden = true;
        receiptEl.hidden = true;
        receiptToggle.hidden = true;
        status.textContent = 'Queuing deploy…';
        try {
            const data = await Harpp.fetch('/api/v1/harpp/deploys', { method: 'POST', body: { package: pkg, profile: prof, idempotency_key: idemKey } });
            trackedId = data.data.deploy_id;
            status.textContent = 'Deploy #' + trackedId + ' queued.';
            await loadAll();
        } catch (e) { status.textContent = 'Deploy failed: ' + e.message; }
        finally { goBtn.disabled = false; }
    });

    receiptToggle.addEventListener('click', () => {
        const showRaw = receiptEl.hidden;
        receiptEl.hidden = !showRaw;
        receiptToggle.textContent = showRaw ? 'Hide raw receipt' : 'Show raw receipt';
    });

    profileSel.addEventListener('change', renderProfileDetail);
    resetReceipt();
    loadAll();
});

