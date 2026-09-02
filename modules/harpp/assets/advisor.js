document.addEventListener('DOMContentLoaded', () => {
    const ADVISOR_TITLE = 'ChatGPT Advisor';
    const ADVISOR_SESSION = 'chatgpt-advisor';
    const convStatus = document.getElementById('advisor-conv-status');
    const result = document.getElementById('advisor-result');
    const form = document.getElementById('advisor-ask');
    const plan = document.getElementById('advisor-plan');
    const sendBtn = document.getElementById('advisor-send');
    const convList = document.getElementById('advisor-conversations');

    const setStatus = (el, text) => { if (el) el.textContent = text; };

    async function findConversation() {
        const rows = (await Harpp.fetch('/api/v1/harpp/conversations')).data.conversations || [];
        return rows.find(r => String(r.title || '').trim().toLowerCase() === ADVISOR_TITLE.toLowerCase());
    }

    async function ensureConversation() {
        const existing = await findConversation();
        if (existing) return existing.id;
        const resp = await Harpp.fetch('/api/v1/harpp/conversations', {
            method: 'POST',
            body: { title: ADVISOR_TITLE, harness_session_id: ADVISOR_SESSION },
        });
        const id = resp && resp.data && resp.data.conversation_id;
        if (!id) throw new Error('Conversation could not be created.');
        return id;
    }

    async function renderConversations() {
        if (!convList) return;
        try {
            const rows = (await Harpp.fetch('/api/v1/harpp/conversations')).data.conversations || [];
            convList.replaceChildren();
            if (!rows.length) {
                convList.className = 'empty-state';
                convList.textContent = 'No conversations yet.';
                return;
            }
            convList.className = 'status-list';
            rows.forEach(row => {
                const el = document.createElement('div');
                el.className = 'status-row';
                const label = document.createElement('span');
                label.textContent = row.title || 'Untitled';
                const count = document.createElement('span');
                count.className = 'pill';
                count.textContent = 'open · ' + (Number(row.unread || 0)) + ' unread';
                el.append(label, count);
                convList.append(el);
            });
        } catch (e) {
            convList.className = 'empty-state';
            convList.textContent = e.message || 'Could not load conversations.';
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = plan.value.trim();
        if (!body) return;
        sendBtn.disabled = true;
        setStatus(result, 'Sending…');
        try {
            const conversationId = await ensureConversation();
            await Harpp.fetch(`/api/v1/harpp/conversations/${conversationId}/messages`, {
                method: 'POST',
                body: { body },
            });
            plan.value = '';
            setStatus(convStatus, 'Plan sent to the ChatGPT Advisor conversation — the harness will reply with a structured second opinion.');
            setStatus(result, '');
            await renderConversations();
        } catch (e) {
            setStatus(result, e.message || 'Could not send the plan.');
        } finally {
            sendBtn.disabled = false;
        }
    });

    (async () => {
        await renderConversations();
        try {
            const conv = await findConversation();
            if (conv) {
                setStatus(convStatus, 'ChatGPT Advisor conversation ready (id ' + conv.id + ').');
            } else {
                setStatus(convStatus, 'No ChatGPT Advisor conversation yet — your first plan will create it.');
            }
        } catch (e) {
            setStatus(convStatus, e.message || 'Conversation status unavailable.');
        }
    })();
});
