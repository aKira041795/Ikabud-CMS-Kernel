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
  const advanced = document.getElementById('decision-advanced');

  // Manual transition matrix for the advanced form. Mirrors
  // HarppDecisionService::TRANSITIONS. The one-click Apply-and-close action is
  // intentionally stricter: it is only offered for ACKNOWLEDGED (or retry-safe
  // APPLIED) decisions.
  const transitions = {
    CREATED: ['PENDING', 'DECIDED', 'CANCELLED'],
    PENDING: ['NOTIFIED', 'VIEWED', 'DECIDED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
    NOTIFIED: ['VIEWED', 'DECIDED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
    VIEWED: ['DECIDED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
    DECIDED: ['ACKNOWLEDGED', 'CLOSED', 'SUPERSEDED', 'CANCELLED'],
    ACKNOWLEDGED: ['APPLIED', 'CLOSED', 'SUPERSEDED', 'CANCELLED'],
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

    const terminal = ['CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'];
    const applyReady = ['ACKNOWLEDGED', 'APPLIED'];
    if (applyForm) {
      applyForm.hidden = !(isOwnerOrAdmin && applyReady.includes(state));
    }
    if (deleteForm) {
      deleteForm.hidden = !(isOwnerOrAdmin && terminal.includes(state));
    }
    // Keep the status transition form open for any open (non-terminal) state so
    // owners can move straight to a target state (e.g. Decide or Close) from
    // PENDING without hunting through a collapsed section.
    if (advanced) {
      advanced.open = !terminal.includes(state);
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
      const currentForm = event.currentTarget;
      const body = Object.fromEntries(new FormData(currentForm));
      const applyRationale = String(body.apply_rationale || '').trim();
      const closeRationale = String(body.close_rationale || '').trim();
      if (!applyRationale || !closeRationale) {
        status.textContent = 'Apply and close rationales are required.';
        return;
      }
      const button = currentForm.querySelector('button');
      button.disabled = true;
      try {
        await Harpp.fetch(`/api/v1/harpp/decisions/${id}/apply-and-close`, {
          method: 'POST',
          body: { apply_rationale: applyRationale, close_rationale: closeRationale }
        });
        status.textContent = 'Decision applied & closed. Returning to inbox…';
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

  // Reviewable artifacts: full approved-task detail + shareable/downloadable files.
  const artifactOpen = document.getElementById('artifact-open');
  const artifactPanel = document.getElementById('artifact-panel');
  const artifactList = document.getElementById('artifact-list');
  const artifactShare = document.getElementById('artifact-share');
  const artifactShareLink = document.getElementById('artifact-share-link');
  const artifactReviewer = artifactPanel && artifactPanel.querySelector('input[name="artifact_reviewer_id"]');
  const artifactTtl = artifactPanel && artifactPanel.querySelector('input[name="artifact_ttl_hours"]');

  async function loadArtifacts() {
    if (!artifactOpen || !artifactPanel) return;
    let bundleId = 0;
    try {
      const build = await Harpp.fetch(`/api/v1/harpp/artifacts/bundles/decision/${id}/build`, { method: 'POST' });
      bundleId = Number((build && build.data && build.data.bundle_id) || 0);
    } catch (error) {
      artifactList.textContent = 'No artifact bundle available.';
      return;
    }
    if (!bundleId) { artifactList.textContent = 'No artifact bundle available.'; return; }
    const view = await Harpp.fetch(`/api/v1/harpp/artifacts/bundles/${bundleId}`);
    artifactList.replaceChildren();
    const artifacts = (view && view.data && view.data.artifacts) || [];
    for (const art of artifacts) {
      const p = document.createElement('p');
      p.style.whiteSpace = 'pre-line';
      if (art.artifact_type === 'file') {
        const link = document.createElement('a');
        link.href = `/api/v1/harpp/artifacts/${art.id}/download`;
        link.textContent = `⬇ ${art.filename || ('artifact-' + art.id)}`;
        link.setAttribute('download', art.filename || ('artifact-' + art.id));
        p.append(link);
      } else {
        p.textContent = `[${art.artifact_type}] ${String(art.payload || '').slice(0, 500)}`;
      }
      artifactList.append(p);
    }
    if (artifactShare) {
      artifactShare.onclick = async () => {
        if (!artifactReviewer || !artifactReviewer.value) {
          artifactShareLink.textContent = 'Enter a reviewer user id.';
          return;
        }
        try {
          const res = await Harpp.fetch(`/api/v1/harpp/artifacts/bundles/${bundleId}/shares`, {
            method: 'POST',
            body: { reviewer_user_id: Number(artifactReviewer.value), ttl_hours: Number(artifactTtl.value || 720) },
          });
          artifactShareLink.textContent = res && res.data && res.data.token
            ? 'Share link token: ' + res.data.token + ' (for reviewer user id ' + res.data.reviewer_user_id + ')'
            : 'Share not created.';
        } catch (error) {
          artifactShareLink.textContent = error.message;
        }
      };
    }
  }

  if (artifactOpen) {
    artifactOpen.onclick = async () => {
      artifactPanel.hidden = false;
      await loadArtifacts();
    };
  }

  load();
});
