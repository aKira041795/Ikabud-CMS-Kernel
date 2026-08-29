document.addEventListener('DOMContentLoaded', () => {
    const el = (id) => document.getElementById(id);
    const status = el('ws-status');
    const listEl = el('workspace-list');
    const activeSelect = el('active-workspace');
    let users = [];

    const ROLE_OPTIONS = ['manager', 'operator', 'reviewer', 'viewer'];
    const FULL_ROLES = ['manager', 'operator', 'reviewer', 'viewer'];
    const STANDARD_ROLES = ['operator', 'reviewer', 'viewer'];

    function show(message, kind) { status.textContent = message; status.className = kind || ''; }
    function chip(text, cls) { const n = document.createElement('span'); n.className = 'pill' + (cls ? ' ' + cls : ''); n.textContent = text; return n; }
    function roleChip(text) { const n = document.createElement('span'); n.className = 'role-pill'; n.textContent = text; return n; }
    function button(label, fn, cls) { const b = document.createElement('button'); b.type = 'button'; b.className = 'button' + (cls ? ' ' + cls : ''); b.textContent = label; b.addEventListener('click', fn); return b; }
    function defaultRolesFor(role) { return (role === 'owner' || role === 'admin') ? FULL_ROLES.slice() : STANDARD_ROLES.slice(); }

    // ── Active workspace (binds new conversation creation) ─────────────
    function persistActive(wsId) {
        if (Number(wsId) > 0) window.localStorage.setItem('HARPP_ACTIVE_WORKSPACE', String(wsId));
        else window.localStorage.removeItem('HARPP_ACTIVE_WORKSPACE');
    }
    function renderActiveSelect(workspaces) {
        const saved = Number(window.localStorage.getItem('HARPP_ACTIVE_WORKSPACE') || 0);
        activeSelect.replaceChildren();
        const legacy = document.createElement('option'); legacy.value = '0'; legacy.textContent = 'legacy (default)'; activeSelect.append(legacy);
        for (const w of workspaces) {
            if (w.status !== 'active') continue;
            const o = document.createElement('option');
            o.value = String(w.id);
            o.textContent = `${w.name} (${w.workspace_key})`;
            if (Number(w.id) === saved) o.selected = true;
            activeSelect.append(o);
        }
    }
    activeSelect.addEventListener('change', () => persistActive(activeSelect.value));

    // ── Members ────────────────────────────────────────────────────────
    async function enroll(workspaceId, userId, action, roles) {
        try {
            await Harpp.fetch(`/api/v1/harpp/workspaces/${workspaceId}/enroll`, { method: 'POST', body: { user_id: userId, action, roles } });
            show('Membership updated.', 'ok');
        } catch (e) { show(e.message, 'error'); }
    }

    function buildRolesGrid(defaults) {
        const grid = document.createElement('div'); grid.className = 'grid roles';
        for (const role of ROLE_OPTIONS) {
            const label = document.createElement('label');
            const box = document.createElement('input');
            box.type = 'checkbox'; box.name = 'roles'; box.value = role;
            box.checked = defaults.includes(role);
            label.append(box, document.createTextNode(role));
            grid.append(label);
        }
        return grid;
    }

    function enrollForm(workspaceId) {
        const form = document.createElement('form'); form.className = 'inline-form'; form.style.marginTop = '.75rem'; form.style.alignItems = 'start';
        const wrap = document.createElement('div'); wrap.style.flex = '1'; wrap.style.minWidth = '220px';
        const userSel = document.createElement('select'); userSel.name = 'user_id';
        userSel.append(new Option('Select a user…', '0'));
        for (const u of users.filter(x => x.is_active && !x.deleted_at)) userSel.append(new Option(`${u.full_name} <${u.email}>`, String(u.id)));
        const actionSel = document.createElement('select'); actionSel.name = 'action';
        for (const a of ['enroll', 'suspend', 'revoke']) actionSel.append(new Option(a, a));
        const rolesGrid = buildRolesGrid(STANDARD_ROLES);
        userSel.addEventListener('change', () => {
            const u = users.find(x => Number(x.id) === Number(userSel.value));
            if (u) { rolesGrid.replaceChildren(); for (const node of buildRolesGrid(defaultRolesFor(u.role)).childNodes) rolesGrid.append(node); }
        });
        const submit = button('Enroll / update', () => {}, ''); submit.type = 'submit'; submit.textContent = 'Enroll / update';
        const row = document.createElement('div'); row.className = 'inline-form'; row.style.width = '100%';
        row.append(userSel, actionSel, submit);
        wrap.append(row, rolesGrid);
        form.append(wrap);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const roles = [...form.querySelectorAll('input[name="roles"]:checked')].map(x => x.value);
            await enroll(workspaceId, Number(userSel.value), actionSel.value, roles);
        });
        return form;
    }

    function renderMembers(workspace, host) {
        const panel = document.createElement('div'); panel.className = 'panel'; panel.style.marginTop = '1rem';
        panel.innerHTML = '<h3>Members</h3><div class="empty-state">Loading…</div>';
        host.append(panel);
        (async () => {
            const data = (await Harpp.fetch(`/api/v1/harpp/workspaces/${workspace.id}/members`)).data;
            const box = panel.querySelector('.empty-state');
            const list = document.createElement('div');
            for (const m of (data.memberships || [])) {
                const row = document.createElement('div'); row.className = 'member-row';
                const info = document.createElement('span');
                const chips = document.createElement('span'); chips.className = 'chips';
                chips.append(chip(m.status, m.status === 'active' ? 'active' : 'archived'), ...(m.roles || []).map(roleChip));
                info.append(document.createTextNode(m.full_name || m.email), chips);
                const acts = document.createElement('span'); acts.className = 'actions';
                if (m.status === 'active') {
                    acts.append(button('Suspend', () => enroll(workspace.id, m.user_id, 'suspend', m.roles || [])));
                    acts.append(button('Revoke', () => enroll(workspace.id, m.user_id, 'revoke', m.roles || [])));
                } else {
                    acts.append(button('Re-enroll', () => enroll(workspace.id, m.user_id, 'enroll', m.roles || [])));
                }
                row.append(info, acts); list.append(row);
            }
            box.replaceWith(list);
            panel.append(enrollForm(workspace.id));
        })().catch((e) => { panel.querySelector('.empty-state').textContent = 'Failed to load members: ' + e.message; });
    }

    // ── Projects ───────────────────────────────────────────────────────
    async function projectEnroll(projectId, userId, action, roles) {
        try {
            await Harpp.fetch(`/api/v1/harpp/projects/${projectId}/enroll`, { method: 'POST', body: { user_id: userId, action, roles } });
            show('Project membership updated.', 'ok');
        } catch (e) { show(e.message, 'error'); }
    }

    function projectMembers(project, host) {
        const panel = document.createElement('div'); panel.className = 'panel'; panel.style.marginTop = '.65rem';
        panel.innerHTML = '<h4>Project members</h4><div class="empty-state">Loading…</div>';
        host.append(panel);
        (async () => {
            const data = (await Harpp.fetch(`/api/v1/harpp/projects/${project.id}/members`)).data;
            const box = panel.querySelector('.empty-state');
            const list = document.createElement('div');
            for (const m of (data.memberships || [])) {
                const row = document.createElement('div'); row.className = 'member-row';
                const chips = document.createElement('span'); chips.className = 'chips';
                chips.append(chip(m.status, m.status === 'active' ? 'active' : 'archived'), ...(m.roles || []).map(roleChip));
                const info = document.createElement('span'); info.append(document.createTextNode(m.full_name || m.email), chips);
                const acts = document.createElement('span'); acts.className = 'actions';
                if (m.status === 'active') acts.append(button('Revoke', () => projectEnroll(project.id, m.user_id, 'revoke', [])));
                else acts.append(button('Re-enroll', () => projectEnroll(project.id, m.user_id, 'enroll', m.roles || [])));
                row.append(info, acts); list.append(row);
            }
            box.replaceWith(list);
        })().catch((e) => { panel.querySelector('.empty-state').textContent = 'Failed to load project members: ' + e.message; });
    }

    function renderProjects(workspace, host) {
        const panel = document.createElement('div'); panel.className = 'panel'; panel.style.marginTop = '1rem';
        panel.innerHTML = '<h3>Projects</h3><div class="empty-state">Loading…</div>';
        host.append(panel);
        (async () => {
            const data = (await Harpp.fetch(`/api/v1/harpp/workspaces/${workspace.id}/projects`)).data;
            const box = panel.querySelector('.empty-state');
            const list = document.createElement('div');
            for (const p of (data.projects || [])) {
                const row = document.createElement('div'); row.className = 'project-row';
                const info = document.createElement('span'); info.append(document.createTextNode(p.name), chip(`${p.project_key}`, ''));
                const acts = document.createElement('span'); acts.className = 'actions';
                if (p.status === 'active') {
                    acts.append(button('Members', () => projectMembers(p, panel)));
                    acts.append(button('Archive', () => archiveProject(p)));
                }
                row.append(info, acts); list.append(row);
            }
            box.replaceWith(list);
            const form = document.createElement('form'); form.className = 'inline-form'; form.style.marginTop = '.75rem';
            const key = document.createElement('input'); key.name = 'project_key'; key.placeholder = 'project key'; key.required = true; key.pattern = '[a-z][a-z0-9_-]{1,63}';
            const name = document.createElement('input'); name.name = 'name'; name.placeholder = 'Project name'; name.required = true;
            const btn = document.createElement('button'); btn.type = 'submit'; btn.className = 'button'; btn.textContent = 'Create project';
            form.append(key, name, btn);
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                try {
                    await Harpp.fetch(`/api/v1/harpp/workspaces/${workspace.id}/projects`, { method: 'POST', body: { project_key: key.value.trim(), name: name.value.trim() } });
                    show('Project created.', 'ok'); key.value = ''; name.value = '';
                    panel.querySelector('.empty-state') && panel.replaceChildren(); renderProjects(workspace, host);
                } catch (err) { show(err.message, 'error'); }
            });
            panel.append(form);
        })().catch((e) => { panel.querySelector('.empty-state').textContent = 'Failed to load projects: ' + e.message; });
    }

    async function archiveProject(p) {
        if (!confirm(`Archive project "${p.name}"?`)) return;
        try {
            await Harpp.fetch(`/api/v1/harpp/projects/${p.id}/archive`, { method: 'POST', body: { version: Number(p.version) || 1 } });
            show('Project archived.', 'ok'); await load();
        } catch (e) { show(e.message, 'error'); }
    }
    async function archiveWorkspace(w) {
        if (!confirm(`Archive workspace "${w.name}"?`)) return;
        try {
            await Harpp.fetch(`/api/v1/harpp/workspaces/${w.id}/archive`, { method: 'POST', body: { version: Number(w.version) || 1 } });
            show('Workspace archived.', 'ok'); await load();
        } catch (e) { show(e.message, 'error'); }
    }

    // ── Workspace cards ────────────────────────────────────────────────
    function workspaceCard(w) {
        const card = document.createElement('div'); card.className = 'ws-card';
        const head = document.createElement('div'); head.className = 'head';
        const title = document.createElement('strong'); title.textContent = w.name;
        const chips = document.createElement('span'); chips.className = 'chips';
        chips.append(chip(w.workspace_key), chip(w.status === 'active' ? 'active' : 'archived', w.status === 'active' ? 'active' : 'archived'), chip(`${w.membership_count || 0} members`));
        head.append(title, chips);
        const actions = document.createElement('div'); actions.className = 'actions';
        actions.append(button('Members', () => renderMembers(w, card)), button('Projects', () => renderProjects(w, card)));
        if (w.status === 'active') actions.append(button('Archive', () => archiveWorkspace(w)));
        card.append(head, actions);
        return card;
    }

    async function load() {
        try {
            const data = (await Harpp.fetch('/api/v1/harpp/workspaces')).data;
            const workspaces = data.workspaces || [];
            renderActiveSelect(workspaces);
            listEl.replaceChildren();
            listEl.className = 'ws-list';
            if (!workspaces.length) { listEl.className = 'empty-state'; listEl.textContent = 'No workspaces yet.'; return; }
            for (const w of workspaces) listEl.append(workspaceCard(w));
        } catch (e) { listEl.textContent = 'Failed to load workspaces: ' + e.message; }
    }

    // ── Create workspace ───────────────────────────────────────────────
    const createForm = el('workspace-create');
    createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = createForm.querySelector('button[type="submit"]'); btn.disabled = true;
        try {
            await Harpp.fetch('/api/v1/harpp/workspaces', { method: 'POST', body: { workspace_key: createForm.workspace_key.value.trim(), name: createForm.name.value.trim() } });
            createForm.reset(); show('Workspace created.', 'ok'); await load();
        } catch (err) { show(err.message, 'error'); }
        finally { btn.disabled = false; }
    });

    (async () => { await loadUsers(); await load(); })();
});
