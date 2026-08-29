document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('runner-fleet');
    const status = document.getElementById('runner-status');
    if (!root) return;

    async function wakeLine(key) {
        try {
            const requests = (await Harpp.fetch('/api/v1/harpp/runners/' + encodeURIComponent(key) + '/wake-requests')).data.requests || [];
            if (!requests.length) return null;
            const line = document.createElement('p');
            line.className = 'runner-meta';
            line.textContent = 'Last wake: ' + requests[0].status + ' @ ' + (requests[0].delivered_at || requests[0].requested_at);
            return line;
        } catch (error) {
            return null;
        }
    }

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
                const wake = await wakeLine(runner.runner_key);
                if (wake) card.append(wake);
                const actions = document.createElement('div');
                actions.className = 'runner-actions';
                actions.style.cssText = 'display:flex;gap:.5rem;margin-top:.5rem';
                const nudge = document.createElement('button');
                nudge.className = 'button';
                nudge.type = 'button';
                nudge.textContent = 'Nudge';
                nudge.disabled = runner.status === 'online';
                nudge.addEventListener('click', async () => {
                    nudge.disabled = true;
                    try {
                        await Harpp.fetch('/api/v1/harpp/runners/' + encodeURIComponent(runner.runner_key) + '/nudge', {method: 'POST', body: {}});
                        status.textContent = 'Wake requested.';
                        await load();
                    } catch (error) {
                        status.textContent = error.message;
                        nudge.disabled = false;
                    }
                });
                actions.append(nudge);
                card.append(actions);
                root.append(card);
            }
        } catch (error) {
            status.textContent = error.message;
        }
    }

    load();
});
