(() => {
  'use strict';
  async function api(url, options = {}) {
    const init = {...options, credentials: 'same-origin', headers: {'Accept': 'application/json', ...(options.headers || {})}};
    if (init.body && typeof init.body !== 'string') {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(init.body);
    }
    const response = await fetch(url, init);
    let payload = null;
    try { payload = await response.json(); } catch (_) { payload = {ok: false, error: `HTTP ${response.status}`}; }
    if (response.status === 401 && location.pathname !== '/harpp/login') location.href = '/harpp/login';
    if (!response.ok || !payload.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  }
  async function registration() {
    if (!('serviceWorker' in navigator)) throw new Error('Service workers are unavailable.');
    return navigator.serviceWorker.register('/harpp/sw.js', {scope: '/harpp/'});
  }
  function applicationServerKey(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const bytes = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(bytes, c => c.charCodeAt(0));
  }
  async function subscribe() {
    if (!('PushManager' in window)) throw new Error('Web Push is unavailable.');
    if (await Notification.requestPermission() !== 'granted') throw new Error('Notification permission was not granted.');
    const reg = await registration();
    const key = (await api('/api/v1/harpp/push/vapid-public-key')).data.public_key;
    let subscription = await reg.pushManager.getSubscription();
    if (!subscription) subscription = await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: applicationServerKey(key)});
    await api('/api/v1/harpp/push/subscribe', {method: 'POST', body: subscription.toJSON()});
    return subscription;
  }
  async function unsubscribe() {
    const reg = await registration();
    const subscription = await reg.pushManager.getSubscription();
    if (!subscription) return false;
    await api('/api/v1/harpp/push/unsubscribe', {method: 'POST', body: {endpoint: subscription.endpoint}});
    return subscription.unsubscribe();
  }
  async function subscribed() { const reg = await registration(); return !!(await reg.pushManager.getSubscription()); }
  async function pollUnread() {
    const badge = document.getElementById('harpp-unread');
    if (!badge) return;
    try { const count = (await api('/api/v1/harpp/notifications/unread-count')).data.unread || 0; badge.textContent = String(count); badge.style.display = count ? 'block' : 'none'; } catch (_) {}
  }
  window.Harpp = {fetch: api, register: registration, subscribe, unsubscribe, subscribed, pollUnread};
  document.addEventListener('DOMContentLoaded', () => {
    registration().catch(() => {});
    pollUnread();
    window.setInterval(pollUnread, 30000);
    document.getElementById('harpp-logout')?.addEventListener('click', async () => { try { await api('/api/v1/harpp/auth/logout', {method: 'POST'}); } finally { location.href = '/harpp/login'; } });
  });
})();
