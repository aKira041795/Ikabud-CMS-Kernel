document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('notification-list');
  const status = document.getElementById('notification-status');
  const showHistoryBtn = document.getElementById('show-history');
  const deleteAllMessagesBtn = document.getElementById('delete-all-messages');
  let rows = [];
  let includeRead = false;

  function target(n) {
    return n.decision_id ? `/harpp/decisions/${n.decision_id}` : (n.conversation_id ? `/harpp?conversation=${n.conversation_id}` : '/harpp/notifications');
  }

  async function load() {
    try {
      const q = includeRead ? '?include_read=1&limit=100' : '?limit=100';
      rows = (await Harpp.fetch('/api/v1/harpp/notifications' + q)).data.notifications || [];
      list.replaceChildren();
      for (const n of rows) {
        const card = document.createElement('div');
        card.className = 'panel';
        card.style.display = 'flex';
        card.style.justifyContent = 'space-between';
        card.style.alignItems = 'center';
        card.style.gap = '1rem';
        const link = document.createElement('a');
        link.href = target(n);
        link.style.flex = '1';
        let payload = {};
        try { payload = JSON.parse(n.payload || '{}'); } catch (_) {}
        link.textContent = `${n.read_at ? '' : '● '}${payload.title || n.notification_type} — ${payload.event || n.status} (${n.created_at})`;
        link.onclick = () => {
          if (!n.read_at) {
            Harpp.fetch(`/api/v1/harpp/notifications/${n.id}/read`, { method: 'POST' })
              .then(() => load()).catch(() => {});
          }
        };
        const del = document.createElement('button');
        del.className = 'button danger';
        del.type = 'button';
        del.textContent = 'Delete';
        del.onclick = async () => {
          if (!window.confirm('Delete this notification?')) return;
          del.disabled = true;
          try {
            await Harpp.fetch(`/api/v1/harpp/notifications/${n.id}`, { method: 'DELETE' });
            await load();
            Harpp.pollUnread();
          } catch (e) {
            status.textContent = e.message;
            del.disabled = false;
          }
        };
        card.append(link, del);
        list.append(card);
      }
      if (!rows.length) list.textContent = includeRead ? 'No notifications.' : 'No unread notifications.';
    } catch (e) { status.textContent = e.message; }
  }

  if (showHistoryBtn) showHistoryBtn.onclick = () => {
    includeRead = !includeRead;
    showHistoryBtn.textContent = includeRead ? 'Hide history' : 'Show history';
    load();
  };

  if (deleteAllMessagesBtn) deleteAllMessagesBtn.onclick = async () => {
    if (!window.confirm('Delete all message notifications? This cannot be undone.')) return;
    deleteAllMessagesBtn.disabled = true;
    try {
      const result = await Harpp.fetch('/api/v1/harpp/notifications/messages', { method: 'DELETE' });
      status.textContent = `Deleted ${(result.data && result.data.deleted) || 0} message notification(s).`;
      await load();
      Harpp.pollUnread();
    } catch (e) {
      status.textContent = e.message;
    } finally {
      deleteAllMessagesBtn.disabled = false;
    }
  };

  document.getElementById('mark-all').onclick = async () => {
    try {
      await Promise.all(rows.filter(n => !n.read_at).map(n => Harpp.fetch(`/api/v1/harpp/notifications/${n.id}/read`, { method: 'POST' })));
      await load();
      Harpp.pollUnread();
    } catch (e) { status.textContent = e.message; }
  };

  load();
});
