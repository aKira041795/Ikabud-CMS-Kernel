document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('notification-list');
  const status = document.getElementById('notification-status');
  const showHistoryBtn = document.getElementById('show-history');
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
        const card = document.createElement('a');
        card.className = 'panel';
        card.href = target(n);
        let payload = {};
        try { payload = JSON.parse(n.payload || '{}'); } catch (_) {}
        card.textContent = `${n.read_at ? '' : '● '}${payload.title || n.notification_type} — ${payload.event || n.status} (${n.created_at})`;
        card.onclick = () => {
          if (!n.read_at) {
            Harpp.fetch(`/api/v1/harpp/notifications/${n.id}/read`, { method: 'POST' })
              .then(() => load()).catch(() => {});
          }
        };
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

  document.getElementById('mark-all').onclick = async () => {
    try {
      await Promise.all(rows.filter(n => !n.read_at).map(n => Harpp.fetch(`/api/v1/harpp/notifications/${n.id}/read`, { method: 'POST' })));
      await load();
      Harpp.pollUnread();
    } catch (e) { status.textContent = e.message; }
  };

  load();
});
