/**
 * Moto Inventory — shared client helpers (same-origin, no CDN dependency).
 * Focused DOM helpers + modal/toast primitives. All mutations go through the
 * /api/v1/moto-inventory/* endpoints; the client never decrements stock or
 * confirms an offline write as completed.
 */
(function () {
    'use strict';

    const S = window.MOTO_INVENTORY = window.MOTO_INVENTORY || {};

    S.esc = function (value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    S.fmtMoney = function (value) {
        const n = Number(value || 0);
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    // Render a small object as a readable key/value list (used by audit detail)
    // instead of dumping raw JSON.
    S.fmtDetail = function (obj) {
        if (!obj || typeof obj !== 'object') return '';
        const moneyKeys = ['total', 'cost', 'price', 'revenue', 'profit', 'line_total', 'amount', 'qty', 'stock_value', 'low_stock_threshold', 'undo_window_minutes'];
        return Object.entries(obj).map(([k, v]) => {
            let val;
            if (v === null || v === undefined || v === '') val = '—';
            else if (typeof v === 'boolean') val = v ? 'Yes' : 'No';
            else if (moneyKeys.indexOf(k) !== -1) val = S.fmtMoney(v);
            else if (typeof v === 'object') val = JSON.stringify(v);
            else val = String(v);
            return `<div class="mi-detail-row"><span class="mi-detail-key">${S.esc(k)}</span><span class="mi-detail-val">${S.esc(val)}</span></div>`;
        }).join('');
    };

    S.uuid = function () {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    };

    S.toast = function (message, isError, tone) {
        const el = document.getElementById('mi-toast');
        if (!el) { alert(message); return; }
        el.textContent = message;
        el.className = 'mi-toast show' + (isError ? ' error' : (tone ? ' ' + tone : ''));
        clearTimeout(S._toastTimer);
        S._toastTimer = setTimeout(() => { el.className = 'mi-toast'; }, 3200);
    };

    S.modal = function (title, bodyHtml, actionsHtml) {
        const backdrop = document.getElementById('mi-modal-backdrop');
        const modal = backdrop ? backdrop.querySelector('.mi-modal') : null;
        if (!backdrop || !modal) return null;
        modal.innerHTML = `<h2>${S.esc(title)}</h2>${bodyHtml}<div class="mi-modal-actions">${actionsHtml || ''}</div>`;
        backdrop.classList.add('open');
        const close = () => backdrop.classList.remove('open');
        backdrop.onclick = (e) => { if (e.target === backdrop) close(); };
        return { close };
    };

    S.api = async function (url, opts) {
        opts = opts || {};
        if (opts.method && opts.method !== 'GET') {
            opts.headers = Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf }, opts.headers || {});
            opts.body = JSON.stringify(opts.body || {});
        }
        const r = await fetch(url, opts);
        return r.json();
    };

    // ── Brand context menu ────────────────────────────────────────────
    S.brandMenu = function (brand) {
        if (!S.me || S.me.permissions.indexOf('moto_inventory.manage') === -1) return;
        const rows = [
            `<div class="mi-row" style="margin-bottom:8px"><strong>${S.esc(brand.name)}</strong></div>`,
            `<button class="mi-btn" data-ba="rename">Rename</button>`,
            brand.archived
                ? `<button class="mi-btn" data-ba="restore">Un-archive</button>`
                : `<button class="mi-btn" data-ba="archive">Archive</button>`,
            brand.trashed
                ? `<button class="mi-btn" data-ba="restore_trash">Restore from trash</button>`
                : `<button class="mi-btn" data-ba="trash">Trash</button>`,
            brand.trashed ? `<button class="mi-btn danger" data-ba="purge">Purge permanently</button>` : '',
        ].join('');
        const m = S.modal('Brand — ' + brand.name, rows, '<button class="mi-btn" data-mclose="1">Close</button>');
        const q = document.getElementById('mi-modal-backdrop');
        q.querySelector('[data-mclose]').onclick = m.close;

        q.querySelectorAll('[data-ba]').forEach(btn => btn.addEventListener('click', async () => {
            const action = btn.dataset.ba;
            let body = { action };
            if (action === 'rename') {
                const name = prompt('New brand name', brand.name);
                if (!name) return;
                body.name = name.trim();
            }
            const json = await S.api('/api/v1/moto-inventory/brands/' + brand.id, { method: 'POST', body });
            if (json.ok) { S.toast('Brand updated', false, 'good'); m.close(); location.reload(); }
            else S.toast(json.error, true);
        }));
    };

    // ── Product editor ────────────────────────────────────────────────
    S.brands = S.brands || [];

    S.loadBrands = async function () {
        try {
            const json = await S.api('/api/v1/moto-inventory/brands?include_archived=1&include_trashed=1');
            if (json.ok) S.brands = json.data.rows || [];
        } catch (e) {
            S.brands = [];
        }
        return S.brands;
    };

    S.productForm = function (product) {
        const branch = product ? product.branch_id : (S.me.branches[0] ? S.me.branches[0].id : '');
        const isEdit = !!product;
        const available = (S.brands || []).filter(b => !b.trashed);
        const currentBrandId = product ? (product.brand_id || '') : (available[0] ? available[0].id : '');
        const brandOptions = available.map(b =>
            `<option value="${b.id}"${String(b.id) === String(currentBrandId) ? ' selected' : ''}>${S.esc(b.name)}${b.archived ? ' (archived)' : ''}</option>`
        ).join('');
        const extraHtml = (product && product.extra && Object.keys(product.extra).length)
            ? Object.entries(product.extra).map(([k, v]) =>
                `<div class="mi-field"><label>${S.esc(k)}</label><input class="mi-input" data-extra="${S.esc(k)}" value="${S.esc(v)}"></div>`).join('')
            : '';
        return `
            <input type="hidden" id="mi-f-branch" value="${S.esc(branch)}">
            <div class="mi-field"><label>Brand</label><select class="mi-input" id="mi-f-brand">${brandOptions || '<option value="">No brand — add a brand first</option>'}</select></div>
            <div class="mi-field"><label>Part No.</label><input class="mi-input" id="mi-f-part" value="${S.esc(product ? product.part_number : '')}" ${isEdit ? 'disabled' : ''}></div>
            <div class="mi-field"><label>Description</label><input class="mi-input" id="mi-f-desc" value="${S.esc(product ? product.description : '')}"></div>
            <div class="mi-grid cols-2">
                <div class="mi-field"><label>Cost ₱</label><input class="mi-input" id="mi-f-cost" type="number" step="0.01" value="${product ? product.cost : '300'}"></div>
                <div class="mi-field"><label>Sell ₱</label><input class="mi-input" id="mi-f-price" type="number" step="0.01" value="${product ? product.price : '425'}"></div>
            </div>
            <div class="mi-grid cols-2">
                <div class="mi-field"><label>Code (MICHAELSON)</label><input class="mi-input" id="mi-f-code" value="${S.esc(product ? product.code : '')}"></div>
                <div class="mi-field"><label>Qty (new products only)</label><input class="mi-input" id="mi-f-qty" type="number" step="0.01" value="10" ${isEdit ? 'disabled' : ''}></div>
            </div>
            ${extraHtml}
        `;
    };

    S.addProduct = async function (onDone) {
        if (!S.me.branches.length) return S.toast('No branch assigned', true);
        if (!(S.brands && S.brands.length)) await S.loadBrands();
        const m = S.modal('Add part', S.productForm(null),
            '<button class="mi-btn" data-mclose="1">Cancel</button><button class="mi-btn primary" data-save="1">Save</button>');
        const q = document.getElementById('mi-modal-backdrop');
        q.querySelector('[data-mclose]').onclick = m.close;
        q.querySelector('[data-save]').onclick = async () => {
            const extra = {};
            q.querySelectorAll('[data-extra]').forEach(i => { extra[i.dataset.extra] = i.value; });
            const brandId = +q.querySelector('#mi-f-brand').value;
            const body = {
                branch_id: +q.querySelector('#mi-f-branch').value,
                brand_id: brandId,
                part_number: q.querySelector('#mi-f-part').value.trim(),
                description: q.querySelector('#mi-f-desc').value.trim(),
                cost: q.querySelector('#mi-f-cost').value,
                price: q.querySelector('#mi-f-price').value,
                code: q.querySelector('#mi-f-code').value,
                qty: q.querySelector('#mi-f-qty').value,
                extra,
            };
            if (!brandId) return S.toast('Please add a brand first', true);
            if (!body.part_number) return S.toast('Part number is required', true);
            const json = await S.api('/api/v1/moto-inventory/products', { method: 'POST', body });
            if (json.ok) { S.toast('Part added', false, 'good'); m.close(); onDone && onDone(); }
            else S.toast(json.error, true);
        };
    };

    S.editProduct = function (id, branch, onDone) {
        S.api('/api/v1/moto-inventory/products/' + id + '?branch_id=' + branch).then(async json => {
            if (!json.ok) return S.toast(json.error, true);
            if (!(S.brands && S.brands.length)) await S.loadBrands();
            const product = json.data.product;
            const m = S.modal('Edit part — ' + product.part_number, S.productForm(product),
                '<button class="mi-btn" data-mclose="1">Cancel</button><button class="mi-btn primary" data-save="1">Save</button>');
            const q = document.getElementById('mi-modal-backdrop');
            q.querySelector('[data-mclose]').onclick = m.close;
            q.querySelector('[data-save]').onclick = async () => {
                const extra = {};
                q.querySelectorAll('[data-extra]').forEach(i => { extra[i.dataset.extra] = i.value; });
                const body = {
                    branch_id: +q.querySelector('#mi-f-branch').value,
                    brand_id: +q.querySelector('#mi-f-brand').value,
                    description: q.querySelector('#mi-f-desc').value.trim(),
                    cost: q.querySelector('#mi-f-cost').value,
                    price: q.querySelector('#mi-f-price').value,
                    code: q.querySelector('#mi-f-code').value,
                    extra,
                };
                const r = await S.api('/api/v1/moto-inventory/products/' + id, { method: 'POST', body });
                if (r.ok) { S.toast('Part updated', false, 'good'); m.close(); onDone && onDone(); }
                else S.toast(r.error, true);
            };
        });
    };

    S.adjustStock = function (id, branch, onDone) {
        const m = S.modal('Adjust stock', `
            <div class="mi-field"><label>Delta (positive adds, negative removes)</label><input class="mi-input" id="mi-a-delta" type="number" step="0.01"></div>
            <div class="mi-field"><label>Reason</label><input class="mi-input" id="mi-a-reason" placeholder="damage, correction, cycle count…"></div>
        `, '<button class="mi-btn" data-mclose="1">Cancel</button><button class="mi-btn primary" data-save="1">Adjust</button>');
        const q = document.getElementById('mi-modal-backdrop');
        q.querySelector('[data-mclose]').onclick = m.close;
        q.querySelector('[data-save]').onclick = async () => {
            const delta = parseFloat(q.querySelector('#mi-a-delta').value);
            const reason = q.querySelector('#mi-a-reason').value.trim();
            if (isNaN(delta) || delta === 0) return S.toast('Enter a non-zero delta', true);
            if (!reason) return S.toast('A reason is required', true);
            const r = await S.api('/api/v1/moto-inventory/stock/adjust', { method: 'POST', body: { branch_id: +branch, product_id: +id, delta, reason, idempotency_key: S.uuid() } });
            if (r.ok) { S.toast('Stock adjusted', false, 'good'); m.close(); onDone && onDone(); }
            else S.toast(r.error, true);
        };
    };

    S.archiveProduct = function (id, branch, onDone) {
        if (!confirm('Archive this part? It will be hidden from the active inventory.')) return;
        S.api('/api/v1/moto-inventory/products/' + id + '/archive', { method: 'POST', body: { branch_id: +branch } })
            .then(j => { if (j.ok) { S.toast('Part archived'); onDone && onDone(); } else S.toast(j.error, true); });
    };
    S.restoreProduct = function (id, branch, onDone) {
        S.api('/api/v1/moto-inventory/products/' + id + '/restore', { method: 'POST', body: { branch_id: +branch } })
            .then(j => { if (j.ok) { S.toast('Part restored', false, 'good'); onDone && onDone(); } else S.toast(j.error, true); });
    };
    S.deleteProduct = function (id, branch, onDone) {
        if (!confirm('Delete this part permanently? This cannot be undone — archive instead where history exists.')) return;
        S.api('/api/v1/moto-inventory/products/' + id + '/delete', { method: 'POST', body: { branch_id: +branch } })
            .then(j => { if (j.ok) { S.toast('Part deleted'); onDone && onDone(); } else S.toast(j.error, true); });
    };

    S.bulkPrice = function (onDone) {
        const m = S.modal('Bulk adjust price (active brand)', `
            <div class="mi-field"><label>Percent change (e.g. 10 to raise, -5 to lower)</label><input class="mi-input" id="mi-bp-pct" type="number" step="0.01"></div>
        `, '<button class="mi-btn" data-mclose="1">Cancel</button><button class="mi-btn primary" data-save="1">Apply</button>');
        const q = document.getElementById('mi-modal-backdrop');
        q.querySelector('[data-mclose]').onclick = m.close;
        q.querySelector('[data-save]').onclick = () => S.toast('Bulk price adjustment requires a selected brand filter; apply per-product edits instead.', true);
    };

    // ── Versioned IndexedDB offline cache (read-only catalog + cart draft) ──
    // The database NAME is versioned; bumping it invalidates the cache. The
    // meta store additionally records the cache version so a partial upgrade
    // can never serve stale rows. The catalog is a READ-ONLY cache: it is only
    // written from successful online API responses, and stock is never
    // decremented client-side. The cart draft lets an in-progress sale survive
    // reloads/offline, but completing a sale still requires the server.
    const OFFLINE_DB_NAME = 'moto-inventory-offline-v1';
    const OFFLINE_CACHE_VERSION = 'v1';
    const OFFLINE_STORES = { catalog: 'catalog', cart: 'cart', meta: 'meta' };

    S.offline = {
        supported() {
            return typeof indexedDB !== 'undefined';
        },

        open() {
            if (!this.supported()) return Promise.resolve(null);
            return new Promise((resolve, reject) => {
                const req = indexedDB.open(OFFLINE_DB_NAME, 1);
                req.onupgradeneeded = () => {
                    const db = req.result;
                    if (!db.objectStoreNames.contains(OFFLINE_STORES.catalog)) {
                        db.createObjectStore(OFFLINE_STORES.catalog, { keyPath: 'id' });
                    }
                    if (!db.objectStoreNames.contains(OFFLINE_STORES.cart)) {
                        db.createObjectStore(OFFLINE_STORES.cart, { keyPath: 'key' });
                    }
                    if (!db.objectStoreNames.contains(OFFLINE_STORES.meta)) {
                        db.createObjectStore(OFFLINE_STORES.meta, { keyPath: 'key' });
                    }
                };
                req.onsuccess = () => {
                    const db = req.result;
                    // Version check: a mismatch invalidates all cached data so a
                    // bumped cache version never serves stale catalog/draft.
                    this.checkVersion(db).then(() => resolve(db));
                };
                req.onerror = () => reject(req.error);
            });
        },

        checkVersion(db) {
            return new Promise((resolve) => {
                try {
                    const tx = db.transaction(OFFLINE_STORES.meta, 'readwrite');
                    const store = tx.objectStore(OFFLINE_STORES.meta);
                    const get = store.get('cacheVersion');
                    get.onsuccess = () => {
                        if (!get.result || get.result.value !== OFFLINE_CACHE_VERSION) {
                            store.put({ key: 'cacheVersion', value: OFFLINE_CACHE_VERSION });
                            // Wipe stale catalog + cart so old data never resurfaces.
                            this.clearAllData(db).then(resolve, () => resolve());
                        } else {
                            resolve();
                        }
                    };
                    get.onerror = () => resolve();
                } catch (e) {
                    resolve();
                }
            });
        },

        clearAllData(db) {
            return new Promise((resolve) => {
                try {
                    const tx = db.transaction([OFFLINE_STORES.catalog, OFFLINE_STORES.cart], 'readwrite');
                    tx.objectStore(OFFLINE_STORES.catalog).clear();
                    tx.objectStore(OFFLINE_STORES.cart).clear();
                    tx.oncomplete = () => resolve();
                    tx.onerror = () => resolve();
                } catch (e) {
                    resolve();
                }
            });
        },

        /** Upsert product rows fetched from a successful online API response. */
        cacheCatalog(rows) {
            if (!rows || !rows.length) return Promise.resolve();
            return this.open().then((db) => {
                if (!db) return;
                return new Promise((resolve) => {
                    try {
                        const tx = db.transaction(OFFLINE_STORES.catalog, 'readwrite');
                        const store = tx.objectStore(OFFLINE_STORES.catalog);
                        rows.forEach((row) => store.put(row));
                        tx.oncomplete = () => resolve();
                        tx.onerror = () => resolve();
                    } catch (e) {
                        resolve();
                    }
                });
            });
        },

        /** Read the cached catalog (offline read-only search). */
        loadCatalog() {
            return this.open().then((db) => {
                if (!db) return Promise.resolve([]);
                return new Promise((resolve) => {
                    try {
                        const tx = db.transaction(OFFLINE_STORES.catalog, 'readonly');
                        const getAll = tx.objectStore(OFFLINE_STORES.catalog).getAll();
                        getAll.onsuccess = () => resolve(getAll.result || []);
                        getAll.onerror = () => resolve([]);
                    } catch (e) {
                        resolve([]);
                    }
                });
            });
        },

        /** Persist the in-progress cart draft so it survives reloads/offline. */
        saveCartDraft(lines) {
            return this.open().then((db) => {
                if (!db) return;
                return new Promise((resolve) => {
                    try {
                        const tx = db.transaction(OFFLINE_STORES.cart, 'readwrite');
                        const store = tx.objectStore(OFFLINE_STORES.cart);
                        store.put({ key: 'current', lines: lines || [], saved_at: Date.now() });
                        tx.oncomplete = () => resolve();
                        tx.onerror = () => resolve();
                    } catch (e) {
                        resolve();
                    }
                });
            });
        },

        /** Restore the persisted cart draft, if any. */
        loadCartDraft() {
            return this.open().then((db) => {
                if (!db) return Promise.resolve(null);
                return new Promise((resolve) => {
                    try {
                        const tx = db.transaction(OFFLINE_STORES.cart, 'readonly');
                        const get = tx.objectStore(OFFLINE_STORES.cart).get('current');
                        get.onsuccess = () => resolve(get.result ? get.result.lines : null);
                        get.onerror = () => resolve(null);
                    } catch (e) {
                        resolve(null);
                    }
                });
            });
        },

        /** Clear the cart draft after a completed (server-acknowledged) sale. */
        clearCartDraft() {
            return this.open().then((db) => {
                if (!db) return;
                return new Promise((resolve) => {
                    try {
                        const tx = db.transaction(OFFLINE_STORES.cart, 'readwrite');
                        tx.objectStore(OFFLINE_STORES.cart).delete('current');
                        tx.oncomplete = () => resolve();
                        tx.onerror = () => resolve();
                    } catch (e) {
                        resolve();
                    }
                });
            });
        },

        isOffline() {
            return navigator.onLine === false;
        },
    };

    // ── Offline / read-only awareness ────────────────────────────────
    function updateOfflineNote() {
        const el = document.getElementById('mi-offline-note');
        const offline = S.offline.isOffline();
        if (el) {
            if (offline) el.classList.add('show');
            else el.classList.remove('show');
        }
        // Let pages disable mutation controls (e.g. Complete sale) when offline.
        window.dispatchEvent(new CustomEvent('mi-offline-change', { detail: { offline } }));
    }
    window.addEventListener('online', updateOfflineNote);
    window.addEventListener('offline', updateOfflineNote);
    updateOfflineNote();
})();
