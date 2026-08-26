document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('decision-filters');
  const list = document.getElementById('decision-list');
  const status = document.getElementById('decision-status');
  const deleteAllBtn = document.getElementById('delete-all-closed');

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
        const meta = document.createElement('p');
        meta.className = 'muted';
        meta.textContent = `${row.lifecycle_state} · ${row.priority} · ${row.decision_key}`;
        card.append(title, meta);
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

  if (deleteAllBtn) {
    deleteAllBtn.onclick = async () => {
      if (!window.confirm('Permanently delete ALL closed decisions? This cannot be undone.')) return;
      deleteAllBtn.disabled = true;
      try {
        const result = await Harpp.fetch('/api/v1/harpp/decisions/closed', { method: 'DELETE' });
        status.textContent = `Deleted ${(result.data && result.data.deleted) || 0} closed decision(s).`;
        await load();
      } catch (error) {
        status.textContent = error.message;
      } finally {
        deleteAllBtn.disabled = false;
      }
    };
  }

  load();
});
