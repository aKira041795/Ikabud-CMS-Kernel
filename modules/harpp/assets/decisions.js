document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('decision-filters');
  const list = document.getElementById('decision-list');
  const status = document.getElementById('decision-status');
  const archiveAllBtn = document.getElementById('archive-all-closed');

  function priorityColor(priority) {
    switch (String(priority || '').toLowerCase()) {
      case 'critical':
        return '#7f1d1d';
      case 'high':
        return '#78350f';
      case 'low':
        return '#334155';
      case 'normal':
      default:
        return '#1e3a8a';
    }
  }

  async function load() {
    const query = new URLSearchParams(new FormData(form));
    for (const [key, value] of [...query]) {
      if (!value) query.delete(key);
    }
    status.textContent = '';
    try {
      const rows = (await Harpp.fetch('/api/v1/harpp/decisions?' + query)).data.decisions || [];
      list.replaceChildren();
      for (const row of rows) {
        const card = document.createElement('a');
        card.className = 'panel';
        card.href = `/harpp/decisions/${row.id}`;
        const title = document.createElement('h3');
        title.textContent = row.title;
        const chips = document.createElement('p');
        chips.className = 'decision-card-meta';
        if (row.lifecycle_state === 'PENDING') {
          const actionBadge = document.createElement('span');
          actionBadge.className = 'pill pill-action';
          actionBadge.textContent = 'action required';
          chips.append(actionBadge);
        }
        const priority = document.createElement('span');
        priority.className = `pill pill-priority pill-${String(row.priority || 'normal').toLowerCase()}`;
        priority.style.background = priorityColor(row.priority);
        priority.style.color = '#fff';
        priority.textContent = String(row.priority || 'normal');
        chips.append(priority);
        const meta = document.createElement('p');
        meta.className = 'muted';
        meta.textContent = `${row.lifecycle_state} · ${row.decision_key}`;
        card.append(title, chips, meta);
        list.append(card);
      }
      if (!rows.length) list.textContent = 'No decisions match these filters.';
    } catch (error) {
      status.textContent = error.message;
    }
  }

  form.onsubmit = event => {
    event.preventDefault();
    load();
  };

  if (archiveAllBtn) {
    archiveAllBtn.onclick = async () => {
      if (!window.confirm('Archive all terminal decisions? Their lifecycle audits and linked ADRs remain retrievable.')) return;
      archiveAllBtn.disabled = true;
      try {
        const result = await Harpp.fetch('/api/v1/harpp/decisions/closed', { method: 'DELETE' });
        status.textContent = `Archived ${(result.data && result.data.archived) || 0} terminal decision(s).`;
        await load();
      } catch (error) {
        status.textContent = error.message;
      } finally {
        archiveAllBtn.disabled = false;
      }
    };
  }

  load();
});
