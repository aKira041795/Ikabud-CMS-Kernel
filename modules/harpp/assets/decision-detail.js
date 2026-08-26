document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('decision-detail');
  const id = Number(root.dataset.decisionId);
  const role = (root.dataset.userRole || '').trim();
  const isOwnerOrAdmin = role === 'owner' || role === 'admin';
  const content = document.getElementById('decision-content');
  const audit = document.getElementById('decision-audit');
  const status = document.getElementById('detail-status');
  const form = document.getElementById('decision-action');
  const select = form.elements.state;
  const applyForm = document.getElementById('decision-apply-close');
  const deleteForm = document.getElementById('decision-delete');

  const transitions = {
    CREATED: ['PENDING', 'CANCELLED'],
    PENDING: ['NOTIFIED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
    NOTIFIED: ['VIEWED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
    VIEWED: ['DECIDED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
    DECIDED: ['ACKNOWLEDGED', 'SUPERSEDED', 'CANCELLED'],
    ACKNOWLEDGED: ['APPLIED', 'SUPERSEDED', 'CANCELLED'],
    APPLIED: ['CLOSED'],
    CLOSED: [],
    EXPIRED: [],
    SUPERSEDED: [],
    CANCELLED: []
  };

  function actions(state) {
    const allowed = transitions[state] || [];
    for (const option of select.options) {
      option.hidden = !allowed.includes(option.value);
    }
    const first = [...select.options].find(option => !option.hidden);
    select.value = first ? first.value : '';
    const button = form.querySelector('button');
    button.disabled = !first;

    if (applyForm) {
      applyForm.hidden = !(isOwnerOrAdmin && ['ACKNOWLEDGED', 'APPLIED'].includes(state));
    }

    if (deleteForm) {
      const terminal = ['CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'];
      deleteForm.hidden = !(isOwnerOrAdmin && terminal.includes(state));
    }
  }

  async function load() {
    try {
      const data = (await Harpp.fetch(`/api/v1/harpp/decisions/${id}`)).data;
      const decision = data.decision;
      document.getElementById('decision-title').textContent = decision.title;
      actions(decision.lifecycle_state);

      content.replaceChildren();
      for (const [label, value] of [
        ['State', decision.lifecycle_state],
        ['Priority', decision.priority],
        ['Request', decision.requested_decision],
        ['Context', decision.context],
        ['Full request', decision.body],
        ['Decision', decision.decision]
      ]) {
        const paragraph = document.createElement('p');
        const bold = document.createElement('strong');
        bold.textContent = `${label}: `;
        paragraph.style.whiteSpace = 'pre-line';
        paragraph.append(bold, document.createTextNode(String(value || '—').replace(/\\n/g, '\n')));
        content.append(paragraph);
      }

      audit.replaceChildren();
      for (const row of data.audit_trail || []) {
        const paragraph = document.createElement('p');
        paragraph.textContent = `${row.created_at}: ${row.from_state || 'START'} → ${row.to_state} — ${row.rationale}`;
        audit.append(paragraph);
      }
    } catch (error) {
      status.textContent = error.message;
    }
  }

  form.onsubmit = async event => {
    event.preventDefault();
    const body = Object.fromEntries(new FormData(event.currentTarget));
    try {
      await Harpp.fetch(`/api/v1/harpp/decisions/${id}/transition`, { method: 'POST', body });
      status.textContent = 'Decision updated.';
      await load();
    } catch (error) {
      status.textContent = error.message;
    }
  };

  if (applyForm) {
    applyForm.onsubmit = async event => {
      event.preventDefault();
      const body = Object.fromEntries(new FormData(event.currentTarget));
      const rationale = String(body.rationale || '').trim();
      if (!rationale) {
        status.textContent = 'Rationale is required.';
        return;
      }
      const button = applyForm.querySelector('button');
      button.disabled = true;
      try {
        await Harpp.fetch(`/api/v1/harpp/decisions/${id}/apply-and-close`, {
          method: 'POST',
          body: { apply_rationale: rationale, close_rationale: rationale }
        });
        status.textContent = 'Decision applied and closed. Returning to inbox…';
        window.setTimeout(() => { window.location.href = '/harpp/decisions'; }, 500);
      } catch (error) {
        status.textContent = error.message;
        button.disabled = false;
      }
    };
  }

  if (deleteForm) {
    deleteForm.onsubmit = async event => {
      event.preventDefault();
      if (!window.confirm('Archive this terminal decision? Its lifecycle audit and linked ADR remain retrievable.')) return;
      const button = deleteForm.querySelector('button');
      button.disabled = true;
      try {
        await Harpp.fetch(`/api/v1/harpp/decisions/${id}`, { method: 'DELETE' });
        status.textContent = 'Decision archived. Returning to inbox…';
        window.setTimeout(() => { window.location.href = '/harpp/decisions'; }, 500);
      } catch (error) {
        status.textContent = error.message;
        button.disabled = false;
      }
    };
  }

  load();
});
