document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('runner-fleet');
    const status = document.getElementById('runner-status');
    if (!root) return;

    async function load() {
        status.textContent = '';
        try {
            const rows = (await Harpp.fetch('/api/v1/harpp/runners')).data.runners || [];
            root.replaceChildren();
            if (!rows.length) {
                const empty = document.createElement('p');
                empty.className = 'empty-state';
                empty.textContent = 'No runners are registered yet.';
                root.append(empty);
                return;
            }
            for (const runner of rows) {
                const card = document.createElement('div');
                card.className = 'panel runner-card';

                const nameRow = document.createElement('div');
                nameRow.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap';
                const name = document.createElement('h3');
                name.style.cssText = 'margin:0';
                name.textContent = runner.display_name || runner.runner_key;
                const online = runner.status === 'online';
                const badge = document.createElement('span');
                badge.className = 'runner-status ' + (online ? 'online' : 'offline');
                badge.textContent = online ? 'online' : 'offline';
                nameRow.append(name, badge);

                const key = document.createElement('p');
                key.className = 'muted';
                key.textContent = runner.runner_key;

                const caps = document.createElement('p');
                caps.style.cssText = 'display:flex;flex-wrap:wrap;gap:.35rem;margin:0';
                const capList = Array.isArray(runner.capabilities) ? runner.capabilities : [];
                const list = capList.length ? capList : ['desktop'];
                for (const c of list) {
                    const chip = document.createElement('span');
                    chip.className = 'runner-cap';
                    chip.textContent = c;
                    caps.append(chip);
                }

                const meta = document.createElement('p');
                meta.className = 'runner-meta';
                meta.textContent = `Last heartbeat: ${runner.last_heartbeat_at || 'never'} · Created: ${runner.created_at || '—'}`;

                card.append(nameRow, key, caps, meta);
                root.append(card);
            }
        } catch (error) {
            status.textContent = error.message;
        }
    }

    load();
});
