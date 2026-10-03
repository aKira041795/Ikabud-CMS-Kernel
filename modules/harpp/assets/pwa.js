(() => {
  'use strict';
  async function api(url, options = {}) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 20000);
    const init = { ...options, credentials: 'same-origin', signal: controller.signal, headers: { 'Accept': 'application/json', ...(options.headers || {}) } };
    const method = String(init.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
      const csrf = (window.HARPP_CSRF || (document.querySelector('meta[name="csrf-token"]') || {}).content || '').trim();
      if (csrf) init.headers['X-CSRF-TOKEN'] = csrf;
    }
    try {
      if (init.body && typeof init.body !== 'string') {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(init.body);
      }
      const response = await fetch(url, init);
      let payload = null;
      try { payload = await response.json(); } catch (error) { if (error && error.name === 'AbortError') throw error; payload = { ok: false, error: `HTTP ${response.status}` }; }
      if (response.status === 401 && location.pathname !== '/harpp/login') location.href = '/harpp/login';
      if (!response.ok || !payload.ok) throw new Error(payload.error || `HTTP ${response.status}`);
      return payload;
    } catch (error) {
      if (error && error.name === 'AbortError') throw new Error('Request timed out after 20 seconds.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  const INSECURE_ORIGIN_MESSAGE = 'Push cannot work on this page because it is served over plain HTTP. Use HTTPS, or open HARPP on localhost/127.0.0.1, then reload.';
  const BLOCKED_MESSAGE = 'Notifications are blocked in your browser. Open the site settings for HARPP and set Notifications to Allow, then reload.';
  const UNSUPPORTED_MESSAGE = 'Push cannot work in this browser because the required notification, service worker, or Push API is unavailable. Use a browser that supports Web Push over HTTPS, then reload.';

  function pushCapability() {
    if (!window.isSecureContext && location.protocol === 'http:') return { available: false, reason: 'insecure-origin', message: INSECURE_ORIGIN_MESSAGE };
    if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) return { available: false, reason: 'unsupported-browser', message: UNSUPPORTED_MESSAGE };
    if (Notification.permission === 'denied') return { available: false, reason: 'permission-denied', message: BLOCKED_MESSAGE };
    return { available: true, reason: Notification.permission === 'granted' ? 'ready' : 'permission-required', message: '' };
  }
  function reportPushAbandoned(where, stateOrError) {
    const message = stateOrError && stateOrError.message ? stateOrError.message : String(stateOrError || 'Unknown reason');
    console.warn(`[HARPP push] ${where}: ${message}`);
  }
  async function registration() {
    if (!window.isSecureContext && location.protocol === 'http:') throw new Error(INSECURE_ORIGIN_MESSAGE);
    if (!('serviceWorker' in navigator)) throw new Error(UNSUPPORTED_MESSAGE);
    return navigator.serviceWorker.register('/harpp/sw.js', { scope: '/harpp/' });
  }
  function applicationServerKey(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const bytes = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(bytes, c => c.charCodeAt(0));
  }
  async function subscribe() {
    const capability = pushCapability();
    if (!capability.available) throw new Error(capability.message);
    let permission = Notification.permission;
    if (permission !== 'granted') permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Notification permission was not granted.');
    const reg = await registration();
    const key = (await api('/api/v1/harpp/push/vapid-public-key')).data.public_key;
    // Bump this whenever existing push subscriptions must be re-registered
    // (e.g. the server switched from ephemeral per-worker VAPID keys to stable
    // configured keys). A stored subscription is bound to the applicationServerKey
    // that was active when it was created; if the key changed, the old
    // subscription can never be authorized and every push is rejected with 403.
    // The generation marker forces exactly one drop + recreate, then self-heals.
    const PUSH_GENERATION = 'v2';
    let subscription = await reg.pushManager.getSubscription();
    if (subscription) {
      try {
        const lastKey = localStorage.getItem('harpp-vapid-key') || '';
        const lastGen = localStorage.getItem('harpp-push-generation') || '';
        if (lastGen !== PUSH_GENERATION || (lastKey && lastKey !== key)) {
          try { await subscription.unsubscribe(); } catch (error) { /* best-effort; recreate below */ }
          subscription = null;
        }
      } catch (error) { /* localStorage unavailable; keep existing subscription */ }
    }
    if (!subscription) {
      subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationServerKey(key) });
    }
    try {
      localStorage.setItem('harpp-vapid-key', key);
      localStorage.setItem('harpp-push-generation', PUSH_GENERATION);
    } catch (error) { /* best-effort */ }
    await api('/api/v1/harpp/push/subscribe', { method: 'POST', body: subscription.toJSON() });
    return subscription;
  }
  async function unsubscribe() {
    const reg = await registration();
    const subscription = await reg.pushManager.getSubscription();
    if (!subscription) {
      reportPushAbandoned('unsubscribe skipped', 'This device has no push subscription.');
      return false;
    }
    await api('/api/v1/harpp/push/unsubscribe', { method: 'POST', body: { endpoint: subscription.endpoint } });
    return subscription.unsubscribe();
  }
  async function subscribed() { const reg = await registration(); return !!(await reg.pushManager.getSubscription()); }
  async function syncPush() {
    const capability = pushCapability();
    if (!capability.available) {
      reportPushAbandoned('automatic sync skipped', capability);
      return;
    }
    if (Notification.permission !== 'granted') {
      reportPushAbandoned('automatic sync skipped', 'Notification permission has not been requested yet; use an Enable push control to request it.');
      return;
    }
    try { await subscribe(); } catch (error) { reportPushAbandoned('automatic sync failed; it will retry on the next authenticated page load', error); }
  }
  async function pollUnread() {
    const badge = document.getElementById('harpp-unread');
    if (!badge) return;
    try { const count = (await api('/api/v1/harpp/notifications/unread-count')).data.unread || 0; badge.textContent = String(count); badge.style.display = count ? 'block' : 'none'; } catch (_) { }
  }
  async function maybePromptPush() {
    const banner = document.getElementById('push-banner');
    if (!banner) {
      reportPushAbandoned('banner not shown', 'This page has no push banner.');
      return;
    }
    const message = document.getElementById('push-banner-message');
    const enable = document.getElementById('push-banner-enable');
    const bannerStatus = document.getElementById('push-banner-status');
    const dismiss = document.getElementById('push-banner-dismiss');
    const capability = pushCapability();
    banner.hidden = true;

    if (!capability.available) {
      if (message) message.textContent = capability.message;
      if (bannerStatus) bannerStatus.textContent = '';
      if (enable) { enable.hidden = true; enable.style.display = 'none'; }
      if (dismiss) dismiss.textContent = 'Dismiss';
      banner.hidden = false;
      reportPushAbandoned('enable prompt replaced with an explanation', capability);
    } else {
      let dismissed = false;
      try { dismissed = localStorage.getItem('harpp-push-dismissed') === '1'; } catch (error) { reportPushAbandoned('could not read banner preference', error); }
      if (dismissed) {
        reportPushAbandoned('enable prompt skipped', 'The owner previously dismissed it.');
        return;
      }
      try {
        const reg = await registration();
        if (await reg.pushManager.getSubscription()) {
          reportPushAbandoned('enable prompt skipped', 'This device is already subscribed.');
          return;
        }
        if (message) message.textContent = 'Enable push so HARPP can alert you on your phone?';
        if (enable) {
          enable.hidden = false;
          enable.style.display = '';
          enable.onclick = async () => {
            enable.disabled = true;
            if (bannerStatus) bannerStatus.textContent = '';
            try {
              await subscribe();
              banner.hidden = true;
              pollUnread();
            } catch (error) {
              const text = error && error.message ? error.message : 'Could not enable push.';
              if (bannerStatus) bannerStatus.textContent = text;
              reportPushAbandoned('banner enable failed', text);
            } finally { enable.disabled = false; }
          };
        }
        banner.hidden = false;
      } catch (error) {
        if (message) message.textContent = error && error.message ? error.message : 'Push setup could not be checked.';
        if (enable) { enable.hidden = true; enable.style.display = 'none'; }
        banner.hidden = false;
        reportPushAbandoned('banner capability check failed', error);
      }
    }
    if (dismiss) dismiss.onclick = () => {
      try { localStorage.setItem('harpp-push-dismissed', '1'); } catch (error) { reportPushAbandoned('could not save banner preference', error); }
      banner.hidden = true;
    };
  }
  window.Harpp = { fetch: api, register: registration, subscribe, unsubscribe, subscribed, pushCapability, pollUnread };
  document.addEventListener('DOMContentLoaded', () => {
    registration().catch(error => reportPushAbandoned('service worker registration failed', error));
    syncPush();
    maybePromptPush();
    pollUnread();
    window.setInterval(pollUnread, 30000);
    document.getElementById('harpp-logout')?.addEventListener('click', async () => { try { await api('/api/v1/harpp/auth/logout', { method: 'POST' }); } finally { location.href = '/harpp/login'; } });
  });
})();
