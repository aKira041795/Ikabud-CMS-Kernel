document.addEventListener('DOMContentLoaded', () => {
  const ACTIVE_RUN_STATES = ['CLAIMED', 'RUNNING', 'AWAITING_APPROVAL'];
  const ACTIVE_DEPLOY_STATES = ['QUEUED', 'CLAIMED', 'UPLOADING', 'EXTRACTING', 'VERIFYING'];
  const statusEl = document.getElementById('overview-status');

  function setText(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = String(value);
  }

  function cardUnavailable(id, message) {
    const node = document.getElementById(id);
    if (node) {
      node.textContent = message || 'Unavailable';
      node.className = 'pill status-offline';
    }
  }

  function renderRunStates(queue) {
    const root = document.getElementById('overview-run-states');
    const states = Object.entries(queue.by_state || {});
    root.replaceChildren();
    root.className = states.length ? 'status-list' : 'empty-state';
    if (!states.length) {
      root.textContent = 'No runs are queued or active.';
      return;
    }
    for (const [state, count] of states) {
      const row = document.createElement('div');
      row.className = 'status-row';
      const label = document.createElement('span');
      label.textContent = state;
      const value = document.createElement('span');
      value.className = 'pill';
      value.textContent = String(count);
      row.append(label, value);
      root.append(row);
    }
  }

  function renderDeploy(jobs) {
    const root = document.getElementById('overview-deploy');
    root.replaceChildren();
    root.className = 'status-list';
    const job = jobs.find(item => ACTIVE_DEPLOY_STATES.includes(item.status)) || jobs[0];
    if (!job) {
      root.className = 'empty-state';
      root.textContent = 'No deploy history yet.';
      return;
    }
    const row = document.createElement('div');
    row.className = 'status-row';
    const name = document.createElement('span');
    name.textContent = `#${job.id} ${job.package_name || 'Deploy'}`;
    const state = document.createElement('span');
    state.className = 'pill ' + (job.status === 'SUCCEEDED' ? 'status-success' : job.status === 'FAILED' ? 'status-danger' : 'status-warning');
    state.textContent = job.status || 'UNKNOWN';
    row.append(name, state);
    root.append(row);
  }

  // Each card loads independently so one failing endpoint (e.g. the
  // owner/admin-only status aggregate) never blanks the rest of the dashboard.
  async function loadStatus() {
    try {
      const data = (await Harpp.fetch('/api/v1/harpp/status')).data || {};
      const queue = data.run_queue || {};
      const states = queue.by_state || {};
      const activeRuns = ACTIVE_RUN_STATES.reduce((total, state) => total + Number(states[state] || 0), 0);
      setText('overview-active-runs', activeRuns);
      setText('overview-queued-runs', Number(states.QUEUED || queue.claimable || 0));
      const daemon = data.daemon;
      const daemonNode = document.getElementById('overview-daemon');
      daemonNode.textContent = daemon ? (daemon.online ? 'Online' : 'Offline') : 'No report';
      daemonNode.className = 'pill ' + (daemon && daemon.online ? 'status-online' : 'status-offline');
      setText('overview-daemon-age', daemon ? `Last seen ${Number(daemon.age_seconds || 0)}s ago` : 'Start the local watch daemon.');
      renderRunStates(queue);
    } catch (error) {
      cardUnavailable('overview-daemon', 'Owner/admin only');
      setText('overview-daemon-age', 'Status requires owner/admin access.');
      const queueRoot = document.getElementById('overview-run-states');
      if (queueRoot) {
        queueRoot.textContent = 'Unavailable';
        queueRoot.className = 'empty-state';
      }
    }
  }

  async function loadDecisions() {
    try {
      const data = (await Harpp.fetch('/api/v1/harpp/decisions?state=PENDING')).data || {};
      setText('overview-decisions', Array.isArray(data.decisions) ? data.decisions.length : 0);
    } catch (error) {
      setText('overview-decisions', '—');
    }
  }

  async function loadNotifications() {
    try {
      const data = (await Harpp.fetch('/api/v1/harpp/notifications/unread-count')).data || {};
      setText('overview-unread', Number(data.unread || 0));
    } catch (error) {
      setText('overview-unread', '—');
    }
  }

  async function loadDeploys() {
    try {
      const data = (await Harpp.fetch('/api/v1/harpp/deploys')).data || {};
      renderDeploy(Array.isArray(data.jobs) ? data.jobs : []);
    } catch (error) {
      const root = document.getElementById('overview-deploy');
      if (root) {
        root.textContent = 'Unavailable';
        root.className = 'empty-state';
      }
    }
  }

  async function load() {
    if (statusEl) statusEl.textContent = '';
    await Promise.all([loadStatus(), loadDecisions(), loadNotifications(), loadDeploys()]);
  }

  load();
  window.setInterval(load, 30000);
});