document.addEventListener('DOMContentLoaded', () => {
  let active = Number(new URLSearchParams(location.search).get('conversation') || 0);
  let last = 0;
  let showArchived = false;
  const list = document.getElementById('conversation-list');
  const messages = document.getElementById('messages');
  const title = document.getElementById('thread-title');
  const status = document.getElementById('messenger-status');
  const archiveToggle = document.getElementById('archive-toggle');
  const closeBtn = document.getElementById('close-conversation');
  const archiveBtn = document.getElementById('archive-conversation');

  const escText = (el, text) => { el.textContent = text ?? ''; };

  async function conversations() {
    try {
      const q = showArchived ? '?archived=1' : '';
      const rows = (await Harpp.fetch('/api/v1/harpp/conversations' + q)).data.conversations || [];
      list.replaceChildren();
      rows.forEach(row => {
        const rowBox = document.createElement('div');
        rowBox.className = 'conversation-row';
        const b = document.createElement('button');
        b.className = 'conversation' + (Number(row.id) === active ? ' selected' : '');
        const unread = Number(row.unread || 0);
        b.textContent = row.title + (unread ? ` (${unread} unread)` : '') + (showArchived ? ' · archived' : '');
        b.onclick = () => {
          active = Number(row.id);
          last = 0;
          history.replaceState(null, '', `/harpp?conversation=${active}`);
          load(false);
          conversations();
        };
        rowBox.append(b);
        if (showArchived) {
          const del = document.createElement('button');
          del.className = 'button danger conversation-delete';
          del.type = 'button';
          del.textContent = 'Delete';
          del.onclick = async (e) => {
            e.stopPropagation();
            if (!window.confirm('Delete this archived conversation? Its messages, decisions, and history are retained but hidden.')) return;
            del.disabled = true;
            try {
              await Harpp.fetch(`/api/v1/harpp/conversations/${row.id}`, { method: 'DELETE' });
              if (active === Number(row.id)) {
                active = 0;
                last = 0;
                messages.replaceChildren();
                title.textContent = 'Select a conversation';
                history.replaceState(null, '', '/harpp');
              }
              await conversations();
              escText(status, 'Conversation deleted.');
            } catch (x) {
              escText(status, x.message);
              del.disabled = false;
            }
          };
          rowBox.append(del);
        }
        list.append(rowBox);
      });
      if (archiveToggle) archiveToggle.textContent = showArchived ? 'Show active' : 'Show archived';
      if (!active && rows.length) { active = Number(rows[0].id); load(false); }
    } catch (e) { escText(status, e.message); }
  }

  async function load(incremental = true) {
    if (!active) return false;
    try {
      // While backreading, never yank the thread to the bottom: remember whether
      // the user is near the bottom, and only auto-scroll to follow new messages
      // when they are (or on a full reload / conversation switch).
      const nearBottom = messages.scrollTop + messages.clientHeight >= messages.scrollHeight - 80;
      const data = (await Harpp.fetch(`/api/v1/harpp/conversations/${active}/messages?after_id=${incremental ? last : 0}&limit=100`)).data;
      if (!incremental) messages.replaceChildren();
      for (const row of data.messages || []) {
        const box = document.createElement('div');
        box.className = `message ${row.sender_type}`;
        box.textContent = row.body;
        messages.append(box);
        last = Math.max(last, Number(row.id));
      }
      await Harpp.fetch(`/api/v1/harpp/conversations/${active}/read`, { method: 'POST', body: { through_id: last } });
      title.textContent = `Conversation #${active}`;
      if (!incremental || nearBottom) {
        messages.scrollTop = messages.scrollHeight;
      }
      return true;
    } catch (e) { escText(status, e.message); return false; }
  }

  const requireActive = () => {
    if (!active) { escText(status, 'Select a conversation first.'); return false; }
    return true;
  };

  document.getElementById('compose').onsubmit = async e => {
    e.preventDefault();
    if (!requireActive()) return;
    const form = e.currentTarget;
    const body = form.body.value;
    try {
      await Harpp.fetch(`/api/v1/harpp/conversations/${active}/messages`, { method: 'POST', body: { body } });
      form.reset();
      try { await load(); } catch (_) {}
      escText(status, 'Sent.');
    } catch (x) { escText(status, x.message); }
  };

  document.getElementById('refresh-thread').onclick = async () => {
    last = 0;
    const loaded = await load(false);
    await conversations();
    if (loaded) escText(status, 'Conversation refreshed.');
  };

  if (closeBtn) closeBtn.onclick = async () => {
    if (!requireActive()) return;
    try {
      await Harpp.fetch(`/api/v1/harpp/conversations/${active}/close`, { method: 'POST' });
      escText(status, 'Conversation marked done.');
      await conversations();
    } catch (e) { escText(status, e.message); }
  };

  if (archiveBtn) archiveBtn.onclick = async () => {
    if (!requireActive()) return;
    try {
      await Harpp.fetch(`/api/v1/harpp/conversations/${active}/archive`, { method: 'POST', body: { archived: true } });
      escText(status, 'Conversation archived.');
      showArchived = false;
      active = 0;
      last = 0;
      messages.replaceChildren();
      title.textContent = 'Select a conversation';
      history.replaceState(null, '', '/harpp');
      await conversations();
    } catch (e) { escText(status, e.message); }
  };

  if (archiveToggle) archiveToggle.onclick = async () => {
    showArchived = !showArchived;
    active = 0;
    last = 0;
    messages.replaceChildren();
    title.textContent = showArchived ? 'Archived conversations' : 'Select a conversation';
    history.replaceState(null, '', '/harpp');
    await conversations();
  };

  const createConversation = async () => {
    const conversationTitle = prompt('Conversation title');
    if (!conversationTitle) return;
    const session = prompt('Harness session ID', `operator-${Date.now()}`);
    if (!session) return;
    try {
      active = Number((await Harpp.fetch('/api/v1/harpp/conversations', { method: 'POST', body: { title: conversationTitle, harness_session_id: session } })).data.conversation_id);
      last = 0;
      history.replaceState(null, '', `/harpp?conversation=${active}`);
      await conversations();
      await load(false);
    } catch (e) { escText(status, e.message); }
  };

  document.getElementById('new-conversation').onclick = createConversation;
  document.getElementById('new-conversation-plus').onclick = createConversation;
  conversations();
  // New messages also arrive via Web Push, so polling is a fallback rather than
  // the primary delivery path — refresh at a pace that does not interrupt
  // backreading in the thread.
  setInterval(() => { conversations(); load(); }, 30000);
});
