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
  const errorMessage = (error, fallback) => error && error.message ? error.message : fallback;

  async function conversations() {
    try {
      const q = showArchived ? '?archived=1' : '';
      // Bounded retry: a transient first-request failure (cold FPM / shared-hosting
      // latency / aborted request) must not leave the sidebar blank until a manual
      // refresh. The 30s interval and manual Refresh still act as a backstop.
      let rows = null;
      for (let attempt = 0; attempt < 3; attempt++) {
        try {
          rows = (await Harpp.fetch('/api/v1/harpp/conversations' + q)).data.conversations || [];
          break;
        } catch (err) {
          if (attempt === 2) throw err;
          await new Promise((res) => setTimeout(res, 600 * (attempt + 1)));
        }
      }
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
            if (!window.confirm('Delete this archived conversation? Its messages and linked history are retained but hidden.')) {
              escText(status, 'Conversation deletion cancelled.');
              return;
            }
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
              escText(status, errorMessage(x, 'Unable to delete the conversation.'));
              del.disabled = false;
            }
          };
          rowBox.append(del);
        }
        list.append(rowBox);
      });
      if (archiveToggle) archiveToggle.textContent = showArchived ? 'Show active' : 'Show archived';
      if (!active && rows.length) { active = Number(rows[0].id); load(false); }
    } catch (e) { escText(status, errorMessage(e, 'Unable to load conversations.')); }
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
        if (row.created_at) {
          const time = document.createElement('time');
          time.className = 'message-time';
          time.dateTime = row.created_at;
          time.textContent = row.created_at_local || row.created_at;
          box.append(time);
        }
        messages.append(box);
        last = Math.max(last, Number(row.id));
      }
      await Harpp.fetch(`/api/v1/harpp/conversations/${active}/read`, { method: 'POST', body: { through_id: last } });
      title.textContent = `Conversation #${active}`;
      if (!incremental || nearBottom) {
        messages.scrollTop = messages.scrollHeight;
      }
      return true;
    } catch (e) { escText(status, errorMessage(e, 'Unable to load the conversation.')); return false; }
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
      const loaded = await load();
      if (loaded) escText(status, 'Sent.');
    } catch (x) { escText(status, errorMessage(x, 'Unable to send the message.')); }
  };

  document.getElementById('refresh-thread').onclick = async () => {
    if (!requireActive()) return;
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
    } catch (e) { escText(status, errorMessage(e, 'Unable to close the conversation.')); }
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
    } catch (e) { escText(status, errorMessage(e, 'Unable to archive the conversation.')); }
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

  const createConversation = () => {
    const existing = document.getElementById('new-conversation-dialog');
    if (existing) {
      existing.querySelector('#new-conversation-title').focus();
      escText(status, 'New conversation dialog is already open.');
      return;
    }

    const previousFocus = document.activeElement;
    const overlay = document.createElement('div');
    overlay.id = 'new-conversation-dialog';
    overlay.className = 'messenger-dialog-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'new-conversation-heading');
    overlay.innerHTML = `
      <form class="messenger-dialog">
        <header class="messenger-dialog-header"><h3 id="new-conversation-heading">New conversation</h3></header>
        <div class="messenger-dialog-body">
          <div class="messenger-dialog-field">
            <label for="new-conversation-title">Title</label>
            <input id="new-conversation-title" name="title" value="New conversation" required>
          </div>
          <div class="messenger-dialog-field">
            <label for="new-conversation-session">Harness session</label>
            <input id="new-conversation-session" name="session" value="operator-${Date.now()}" aria-describedby="new-conversation-session-help new-conversation-error" required>
            <p id="new-conversation-session-help" class="messenger-dialog-help">Use letters, numbers, dots, underscores, colons, or hyphens.</p>
            <p id="new-conversation-error" class="messenger-dialog-error" aria-live="polite"></p>
          </div>
        </div>
        <footer class="messenger-dialog-actions">
          <button id="new-conversation-cancel" class="button" type="button">Cancel</button>
          <button id="new-conversation-create" class="button" type="submit">Create</button>
        </footer>
      </form>`;
    document.body.append(overlay);

    const form = overlay.querySelector('form');
    const titleInput = overlay.querySelector('#new-conversation-title');
    const sessionInput = overlay.querySelector('#new-conversation-session');
    const createBtn = overlay.querySelector('#new-conversation-create');
    const cancelBtn = overlay.querySelector('#new-conversation-cancel');
    const dialogError = overlay.querySelector('#new-conversation-error');
    const validSession = /^[A-Za-z0-9._:-]+$/;

    const validate = () => {
      let reason = '';
      if (!sessionInput.value) reason = 'Harness session is required.';
      else if (!validSession.test(sessionInput.value)) reason = 'Use only letters, numbers, dots, underscores, colons, or hyphens; spaces are not allowed.';
      else if (!titleInput.value.trim()) reason = 'Title is required.';
      escText(dialogError, reason);
      createBtn.disabled = Boolean(reason);
      return !reason;
    };
    const closeDialog = () => {
      document.removeEventListener('keydown', onKeydown);
      overlay.remove();
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    };
    const cancel = () => {
      closeDialog();
      escText(status, 'New conversation cancelled.');
    };
    const onKeydown = event => {
      if (event.key === 'Escape') cancel();
    };

    titleInput.addEventListener('input', validate);
    sessionInput.addEventListener('input', validate);
    cancelBtn.addEventListener('click', cancel);
    overlay.addEventListener('click', event => { if (event.target === overlay) cancel(); });
    document.addEventListener('keydown', onKeydown);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!validate()) {
        sessionInput.focus();
        return;
      }
      createBtn.disabled = true;
      escText(dialogError, 'Creating…');
      try {
        const activeWorkspace = Number(window.localStorage.getItem('HARPP_ACTIVE_WORKSPACE') || 0);
        const scope = activeWorkspace > 0 ? { workspace_id: activeWorkspace } : {};
        active = Number((await Harpp.fetch('/api/v1/harpp/conversations', { method: 'POST', body: { title: titleInput.value.trim(), harness_session_id: sessionInput.value, ...scope } })).data.conversation_id);
        last = 0;
        closeDialog();
        history.replaceState(null, '', `/harpp?conversation=${active}`);
        await conversations();
        await load(false);
      } catch (error) {
        const message = errorMessage(error, 'Unable to create the conversation.');
        escText(dialogError, message);
        escText(status, message);
        createBtn.disabled = false;
      }
    });

    validate();
    titleInput.select();
  };

  document.getElementById('new-conversation').onclick = createConversation;
  conversations();
  // New messages also arrive via Web Push, so polling is a fallback rather than
  // the primary delivery path — refresh at a pace that does not interrupt
  // backreading in the thread.
  setInterval(() => { conversations(); load(); }, 30000);
});
