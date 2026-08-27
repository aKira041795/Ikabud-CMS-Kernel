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
  async function registration() {
    if (!('serviceWorker' in navigator)) throw new Error('Service workers are unavailable.');
    return navigator.serviceWorker.register('/harpp/sw.js', { scope: '/harpp/' });
  }
  function applicationServerKey(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const bytes = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(bytes, c => c.charCodeAt(0));
  }
  async function subscribe() {
    if (!('PushManager' in window)) throw new Error('Web Push is unavailable on this browser.');
    let permission = Notification.permission;
    if (permission === 'denied') throw new Error('Notifications are blocked in your browser. Open the site settings for HARPP and set Notifications to Allow, then reload and tap Enable again.');
    if (permission !== 'granted') permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Notification permission was not granted.');
    const reg = await registration();
    const key = (await api('/api/v1/harpp/push/vapid-public-key')).data.public_key;
    let subscription = await reg.pushManager.getSubscription();
    if (!subscription) subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationServerKey(key) });
    await api('/api/v1/harpp/push/subscribe', { method: 'POST', body: subscription.toJSON() });
    return subscription;
  }
  async function unsubscribe() {
    const reg = await registration();
    const subscription = await reg.pushManager.getSubscription();
    if (!subscription) return false;
    await api('/api/v1/harpp/push/unsubscribe', { method: 'POST', body: { endpoint: subscription.endpoint } });
    return subscription.unsubscribe();
  }
  async function subscribed() { const reg = await registration(); return !!(await reg.pushManager.getSubscription()); }
  async function syncPush() {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try { await subscribe(); } catch (_) { /* Retry on the next authenticated HARPP page load. */ }
  }
  async function pollUnread() {
    const badge = document.getElementById('harpp-unread');
    if (!badge) return;
    try { const count = (await api('/api/v1/harpp/notifications/unread-count')).data.unread || 0; badge.textContent = String(count); badge.style.display = count ? 'block' : 'none'; } catch (_) { }
  }
  async function maybePromptPush() {
    // Option A: one-time onboarding banner — show only when push is possible,
    // this device is not yet subscribed, and the owner has not dismissed it.
    const banner = document.getElementById('push-banner');
    if (!banner) return;
    banner.hidden = true;
    if (!('PushManager' in window) || !('Notification' in window) || Notification.permission === 'denied' || localStorage.getItem('harpp-push-dismissed') === '1') return;
    try {
      const reg = await registration();
      if (await reg.pushManager.getSubscription()) return;
      banner.hidden = false;
      const enable = document.getElementById('push-banner-enable');
      const bannerStatus = document.getElementById('push-banner-status');
      enable.onclick = async () => {
        enable.disabled = true;
        if (bannerStatus) bannerStatus.textContent = '';
        try {
          await subscribe();
          banner.hidden = true;
          pollUnread();
        } catch (err) {
          if (bannerStatus) bannerStatus.textContent = err && err.message ? err.message : 'Could not enable push.';
        } finally {
          enable.disabled = false;
        }
      };
      const dismiss = document.getElementById('push-banner-dismiss');
      if (dismiss) dismiss.onclick = () => {
        localStorage.setItem('harpp-push-dismissed', '1');
        banner.hidden = true;
      };
    } catch (_) { }
  }
  window.Harpp = { fetch: api, register: registration, subscribe, unsubscribe, subscribed, pollUnread };
  document.addEventListener('DOMContentLoaded', () => {
    registration().catch(() => { });
    syncPush();
    maybePromptPush();
    pollUnread();
    window.setInterval(pollUnread, 30000);
    document.getElementById('harpp-logout')?.addEventListener('click', async () => { try { await api('/api/v1/harpp/auth/logout', { method: 'POST' }); } finally { location.href = '/harpp/login'; } });
  });
})();
