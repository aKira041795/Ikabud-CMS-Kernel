document.addEventListener('DOMContentLoaded', () => {
    function badge(text, online) {
        const node = document.createElement('span');
        node.className = 'runner-status ' + (online ? 'online' : 'offline');
        node.textContent = text;
        return node;
    }

    function chip(text) {
        const node = document.createElement('span');
        node.className = 'chip';
        node.textContent = text;
        return node;
    }

    function renderDaemon(daemon) {
        const root = document.getElementById('daemon-health');
        if (!root) return;
        root.replaceChildren();
        root.className = '';
        if (!daemon) {
            root.className = 'empty-state';
            root.textContent = 'No daemon report yet — start the local watch daemon (harpp watch --wake).';
            return;
        }
        const heading = document.createElement('div');
        heading.className = 'status-row';
        const identity = document.createElement('strong');
        identity.textContent = daemon.runner_key || 'HARPP daemon';
        const health = document.createElement('span');
        health.append(badge(daemon.online ? 'online' : 'offline', Boolean(daemon.online)), document.createTextNode(` last seen ${Number(daemon.age_seconds || 0)}s ago`));
        heading.append(identity, health);
        const version = document.createElement('p');
        version.className = 'age';
        version.textContent = `Daemon version: ${daemon.daemon_version || 'unknown'}`;
        const counts = document.createElement('div');
        counts.className = 'chip-row';
        for (const [state, count] of Object.entries(daemon.workflow_counts || {})) counts.append(chip(`${state} ${count}`));
        const recent = document.createElement('div');
        recent.className = 'status-list';
        for (const workflow of Array.isArray(daemon.recent_workflows) ? daemon.recent_workflows : []) {
            const row = document.createElement('div');
            row.className = 'status-row';
            const label = document.createElement('span');
            label.textContent = `${workflow.id || 'workflow'} — ${workflow.title || 'Untitled'}`;
            row.append(label, chip(workflow.status || 'unknown'));
            recent.append(row);
        }
        root.append(heading, version, counts, recent);
    }

    function renderRunners(runners) {
        const root = document.getElementById('runner-fleet');
        if (!root) return;
        root.replaceChildren();
        if (!runners.length) {
            const empty = document.createElement('p');
            empty.className = 'empty-state';
            empty.textContent = 'No runners are registered yet.';
            root.append(empty);
            return;
        }
        for (const runner of runners) {
            const card = document.createElement('div');
            card.className = 'panel runner-card';
            const nameRow = document.createElement('div');
            nameRow.className = 'status-row';
            const name = document.createElement('h3');
            name.style.margin = '0';
            name.textContent = runner.display_name || runner.runner_key;
            nameRow.append(name, badge(runner.status === 'online' ? 'online' : 'offline', runner.status === 'online'));
            const key = document.createElement('p');
            key.className = 'muted';
            key.textContent = runner.runner_key;
            const caps = document.createElement('div');
            caps.className = 'chip-row';
            for (const capability of Array.isArray(runner.capabilities) && runner.capabilities.length ? runner.capabilities : ['desktop']) caps.append(chip(capability));
            const heartbeat = document.createElement('p');
            heartbeat.className = 'age';
            heartbeat.textContent = `Last heartbeat: ${runner.last_heartbeat_at || 'never'}`;
            card.append(nameRow, key, caps, heartbeat);
            root.append(card);
        }
    }

    function renderQueue(queue) {
        const root = document.getElementById('run-queue');
        if (!root) return;
        root.replaceChildren();
        root.className = '';
        const table = document.createElement('table');
        table.className = 'status-table';
        const body = document.createElement('tbody');
        for (const [state, count] of Object.entries(queue.by_state || {})) {
            const row = document.createElement('tr');
            const stateCell = document.createElement('td');
            const countCell = document.createElement('td');
            stateCell.textContent = state;
            countCell.textContent = String(count);
            row.append(stateCell, countCell);
            body.append(row);
        }
        table.append(body);
        const summary = document.createElement('p');
        summary.className = 'age';
        summary.textContent = `Claimable: ${Number(queue.claimable || 0)} · Total: ${Number(queue.total || 0)}`;
        root.append(table, summary);
    }

    function renderRows(id, rows, formatter) {
        const root = document.getElementById(id);
        if (!root) return;
        root.replaceChildren();
        root.className = rows.length ? 'status-list' : 'empty-state';
        if (!rows.length) {
            root.textContent = 'No recent activity.';
            return;
        }
        for (const item of rows) root.append(formatter(item));
    }

    function runRow(run) {
        const row = document.createElement('div');
        row.className = 'status-row';
        const text = document.createElement('span');
        text.textContent = `run #${run.id} — `;
        const state = document.createElement('strong');
        const terminal = ['SUCCEEDED', 'FAILED', 'CANCELLED'].includes(run.state);
        const active = ['CLAIMED', 'RUNNING', 'AWAITING_APPROVAL'].includes(run.state);
        state.className = 'run-state ' + (terminal ? 'terminal' : active ? 'active' : 'queued');
        state.textContent = run.state || 'UNKNOWN';
        const conversation = document.createElement('span');
        conversation.textContent = ` — conversation #${run.conversation_id || '—'}`;
        text.append(state, conversation);
        row.append(text);
        return row;
    }

    function decisionRow(decision) {
        const row = document.createElement('div');
        row.className = 'status-row';
        const title = document.createElement('span');
        title.textContent = decision.title || `decision #${decision.id}`;
        row.append(title, chip(decision.lifecycle_state || 'unknown'));
        return row;
    }

    function showError(message) {
        for (const id of ['daemon-health', 'runner-fleet', 'run-queue', 'recent-runs', 'recent-decisions']) {
            const root = document.getElementById(id);
            if (!root) continue;
            root.className = 'empty-state';
            root.textContent = message;
        }
    }

    async function load() {
        try {
            const data = (await Harpp.fetch('/api/v1/harpp/status')).data;
            renderDaemon(data.daemon);
            renderRunners(Array.isArray(data.runners) ? data.runners : []);
            renderQueue(data.run_queue || {});
            renderRows('recent-runs', Array.isArray(data.recent_runs) ? data.recent_runs : [], runRow);
            renderRows('recent-decisions', Array.isArray(data.recent_decisions) ? data.recent_decisions : [], decisionRow);
        } catch (error) {
            showError(error.message || 'Unable to load HARPP status.');
        }
    }

    load();
    setInterval(load, 30000);
});