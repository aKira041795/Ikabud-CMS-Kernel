document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('settings-form');
  const profile = document.getElementById('profile-form');
  const status = document.getElementById('settings-status');
  const push = document.getElementById('push-toggle');
  const pushStatus = document.getElementById('push-device-status');
  const bStatus = document.getElementById('bridge-status');
  const bKey = document.getElementById('bridge-key-value');
  const bGen = document.getElementById('bridge-generate');
  const bRot = document.getElementById('bridge-rotate');

  function showKey(key) {
    if (!key) return;
    bKey.textContent = key;
    bKey.style.display = 'inline';
  }

  function bridgeState(data) {
    if (data.generated === true) {
      bStatus.textContent = 'Generated — store it now';
      bStatus.style.color = '#b45309';
      showKey(data.key);
    } else {
      bStatus.textContent = 'Configured (fingerprint ' + data.fingerprint + ')' + (data.rotated_at ? ' · rotated ' + data.rotated_at : '');
    }
    bRot.style.display = 'inline-block';
    bGen.style.display = data.generated ? 'none' : 'inline-block';
  }

  async function loadBridge() {
    try {
      bridgeState((await Harpp.fetch('/api/v1/harpp/bridge/key')).data);
    } catch (error) {
      bStatus.textContent = error.message || 'Bridge key unavailable';
    }
  }

  function showPushCapability(capability, subscribed) {
    if (!capability.available) {
      push.disabled = true;
      push.textContent = 'Enable push on this device';
      pushStatus.dataset.state = capability.reason;
      pushStatus.textContent = capability.message;
      return;
    }
    push.disabled = false;
    push.textContent = subscribed ? 'Disable push on this device' : 'Enable push on this device';
    pushStatus.dataset.state = 'ready';
    pushStatus.textContent = subscribed
      ? 'Push is enabled on this device.'
      : 'Push is available on this device. Select Enable push on this device to allow notifications.';
  }

  async function loadPushState() {
    const capability = Harpp.pushCapability();
    if (!capability.available) {
      showPushCapability(capability, false);
      return;
    }
    try {
      showPushCapability(capability, await Harpp.subscribed());
    } catch (error) {
      push.disabled = true;
      pushStatus.dataset.state = 'check-failed';
      pushStatus.textContent = error.message || 'Push setup could not be checked. Reload and try again.';
      console.warn('[HARPP push] settings check failed: ' + pushStatus.textContent);
    }
  }

  async function load() {
    try {
      const settings = (await Harpp.fetch('/api/v1/harpp/settings')).data.settings;
      for (const element of form.elements) {
        if (element.name && settings[element.name] !== undefined) element.value = settings[element.name];
      }
      const key = (await Harpp.fetch('/api/v1/harpp/push/vapid-public-key')).data.public_key;
      document.getElementById('vapid-status').textContent = key ? 'Configured' : 'Unavailable';
    } catch (error) {
      status.textContent = error.message;
    }
    await loadPushState();
  }

  form.onsubmit = async event => {
    event.preventDefault();
    try {
      await Harpp.fetch('/api/v1/harpp/settings', { method: 'POST', body: { settings: Object.fromEntries(new FormData(form)) } });
      status.textContent = 'Tenant settings saved.';
    } catch (error) { status.textContent = error.message; }
  };

  profile.onsubmit = async event => {
    event.preventDefault();
    try {
      await Harpp.fetch('/api/v1/harpp/auth/profile', { method: 'POST', body: Object.fromEntries(new FormData(profile)) });
      status.textContent = 'Profile saved.';
    } catch (error) { status.textContent = error.message; }
  };

  push.onclick = async () => {
    push.disabled = true;
    try {
      if (await Harpp.subscribed()) await Harpp.unsubscribe();
      else await Harpp.subscribe();
      status.textContent = 'Push preference updated.';
    } catch (error) {
      status.textContent = error.message;
      console.warn('[HARPP push] settings update failed: ' + error.message);
    } finally {
      await loadPushState();
    }
  };

  bGen.onclick = async () => {
    bGen.disabled = true;
    try {
      const data = (await Harpp.fetch('/api/v1/harpp/bridge/key/generate', { method: 'POST' })).data;
      bridgeState(data);
      status.textContent = data.generated ? 'Bridge key generated — store it now (shown once).' : 'Bridge key already configured.';
    } catch (error) { status.textContent = error.message; }
    finally { bGen.disabled = false; }
  };

  bRot.onclick = async () => {
    if (!confirm('Rotating invalidates the current bridge key immediately. All harness clients must be updated. Continue?')) return;
    bRot.disabled = true;
    try {
      const data = (await Harpp.fetch('/api/v1/harpp/bridge/key', { method: 'POST' })).data;
      bridgeState(data);
      status.textContent = 'Bridge key rotated — store the new key now (shown once).';
    } catch (error) { status.textContent = error.message; }
    finally { bRot.disabled = false; }
  };

  const pwForm = document.getElementById('change-password-form');
  if (pwForm) pwForm.onsubmit = async event => {
    event.preventDefault();
    const button = pwForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      await Harpp.fetch('/api/v1/harpp/auth/change-password', { method: 'POST', body: Object.fromEntries(new FormData(pwForm)) });
      pwForm.reset();
      status.textContent = 'Password changed.';
    } catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
  };

  load();
  loadBridge();
});
