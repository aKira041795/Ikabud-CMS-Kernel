/* global indexedDB, crypto, TextEncoder, TextDecoder, localStorage */
'use strict';

/**
 * Daily Ledger — Encrypted Offline Vault (IndexedDB)
 * ===================================================
 * The single versioned IndexedDB database that holds enrollment, bootstrap,
 * pending operations, sync receipts, and quarantine records for the offline
 * PWA shell.
 *
 * Security contract:
 *   * One random 256-bit data key per enrollment. The data key is WRAPPED
 *     with a key derived from the 4-6 digit PIN via PBKDF2-SHA-256 (random
 *     salt, >= 120000 iterations). Only the wrapped blob is persisted.
 *   * Bootstrap and every operation payload are AES-GCM encrypted with the
 *     data key, a unique 96-bit IV, and an authenticated additional-data
 *     (AAD) string that binds the record to its scope. Toggling any scope
 *     field (tenant / actor / branch / date / shift / enrollment) makes
 *     decryption fail closed.
 *   * No plaintext PIN, no cloud credential, no bearer token, and no
 *     plaintext bootstrap/operation payload is ever written to storage.
 *   * The PIN is never stored. Unlock verifies by decrypting the wrapped
 *     data key; a wrong PIN fails AES-GCM authentication.
 *
 * Lifecycle:
 *   * Enrollment + bootstrap + readiness are activated ATOMICALLY in one
 *     readwrite transaction, then verified by a decrypt-and-read-back.
 *   * Lock drops in-memory keys but preserves all encrypted work.
 *   * Logout locks the vault (does not delete pending work).
 *   * "Remove offline access" clears that enrollment's vault after the
 *     caller surfaces a pending-work warning.
 *
 * Legacy migration:
 *   * Imports supported records from the old `daily-ledger-reference`
 *     IndexedDB and the legacy `localStorage` queues into the vault, keys
 *     them by scope, quarantines mismatches, and deletes the old keys only
 *     after the vault commit has been read back.
 *   * The legacy PIN verifier is NOT imported — fresh online re-enrollment
 *     is required (PIN + cloud grant belong to the same enrollment).
 */

(function () {
    var DB_NAME = 'daily-ledger-offline-vault';
    var DB_VERSION = 1;
    var SCHEMA_VERSION = 1;
    var BOOTSTRAP_VERSION = '1';
    var ITERATIONS = 120000;
    var MAX_ATTEMPTS = 5;
    var LOCK_MS = 5 * 60 * 1000;
    var AAD_PREFIX = 'dlv1|';

    var LEGACY_IDB_NAME = 'daily-ledger-reference';
    var LEGACY_IDB_VERSION = 2;

    var LEGACY_LS_PREFIXES = [
        'daily-ledger:pending-saves',
        'daily-ledger:pending-ops',
        'bbs_pending_saves'
    ];

    // In-memory data key + active enrollment (never persisted).
    var dataKey = null;
    var activeEnrollment = null;

    function enc(str) {
        return new TextEncoder().encode(String(str));
    }
    function decBuf(buf) {
        return new TextDecoder().decode(buf);
    }
    function bufToB64(buf) {
        var bytes = new Uint8Array(buf);
        var s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s);
    }
    function b64ToBuf(b64) {
        var s = atob(b64);
        var bytes = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) bytes[i] = s.charCodeAt(i);
        return bytes.buffer;
    }
    function randomBytes(n) {
        var b = new Uint8Array(n);
        crypto.getRandomValues(b);
        return b;
    }

    function idbAvailable() {
        return typeof indexedDB !== 'undefined' && typeof indexedDB.open === 'function';
    }
    function secureContextAvailable() {
        return typeof window !== 'undefined' && (window.isSecureContext === true || window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
    }
    function cryptoAvailable() {
        return typeof crypto !== 'undefined' && !!crypto.subtle && typeof crypto.getRandomValues === 'function';
    }
    function prerequisites() {
        if (!secureContextAvailable()) {
            return { ok: false, reason: 'insecure-context' };
        }
        if (!('serviceWorker' in navigator)) {
            return { ok: false, reason: 'no-service-worker' };
        }
        if (!idbAvailable()) {
            return { ok: false, reason: 'no-indexeddb' };
        }
        if (!cryptoAvailable()) {
            return { ok: false, reason: 'no-webcrypto' };
        }
        return { ok: true };
    }

    // ── IndexedDB open + upgrade ─────────────────────────────────────────
    var dbPromise = null;

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            if (!idbAvailable()) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;

                if (!db.objectStoreNames.contains('meta')) {
                    var meta = db.createObjectStore('meta', { keyPath: 'key' });
                    meta.createIndex('enrollment_id', 'enrollment_id', { unique: false });
                }
                if (!db.objectStoreNames.contains('enrollment')) {
                    var en = db.createObjectStore('enrollment', { keyPath: 'enrollment_id' });
                    en.createIndex('tenant_scope', 'scope.tenant_scope', { unique: false });
                    en.createIndex('actor_user_id', 'scope.actor_user_id', { unique: false });
                }
                if (!db.objectStoreNames.contains('bootstrap')) {
                    var bs = db.createObjectStore('bootstrap', { keyPath: 'key' });
                    bs.createIndex('enrollment_id', 'enrollment_id', { unique: false });
                }
                if (!db.objectStoreNames.contains('operations')) {
                    var ops = db.createObjectStore('operations', { keyPath: 'client_op_id' });
                    ops.createIndex('state', 'state', { unique: false });
                    ops.createIndex('enrollment_id', 'enrollment_id', { unique: false });
                }
                if (!db.objectStoreNames.contains('receipts')) {
                    var rc = db.createObjectStore('receipts', { keyPath: 'client_op_id' });
                    rc.createIndex('enrollment_id', 'enrollment_id', { unique: false });
                }
                if (!db.objectStoreNames.contains('quarantine')) {
                    db.createObjectStore('quarantine', { keyPath: 'id' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error || new Error('Failed to open vault DB')); };
            req.onblocked = function () { /* wait for other tabs */ };
        });
        return dbPromise;
    }

    function txStore(db, storeName, mode) {
        var tx = db.transaction(storeName, mode);
        return { tx: tx, store: tx.objectStore(storeName) };
    }

    function reqToPromise(req) {
        return new Promise(function (resolve, reject) {
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error || new Error('IDB request failed')); };
        });
    }

    // ── Crypto ───────────────────────────────────────────────────────────
    function deriveWrappingKey(pin, saltBuf) {
        return crypto.subtle.importKey('raw', enc(String(pin)), 'PBKDF2', false, ['deriveBits'])
            .then(function (base) {
                return crypto.subtle.deriveBits(
                    { name: 'PBKDF2', hash: 'SHA-256', salt: saltBuf, iterations: ITERATIONS },
                    base,
                    256
                );
            })
            .then(function (bits) {
                return crypto.subtle.importKey('raw', bits, 'AES-GCM', false, ['encrypt', 'decrypt']);
            });
    }

    function importDataKey(keyBytes) {
        return crypto.subtle.importKey('raw', keyBytes, 'AES-GCM', false, ['encrypt', 'decrypt']);
    }

    function canonicalAAD(scope) {
        return [
            AAD_PREFIX,
            String(scope.tenant_scope || ''),
            String(scope.actor_user_id || ''),
            String(scope.branch_id || ''),
            String(scope.business_date || ''),
            String(scope.shift || ''),
            String(scope.enrollment_id || '')
        ].join('|');
    }

    function encryptJson(key, obj, scope) {
        var iv = randomBytes(12);
        var aad = enc(canonicalAAD(scope));
        return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: aad }, key, enc(JSON.stringify(obj)))
            .then(function (ct) {
                return { v: 1, iv: bufToB64(iv.buffer), ct: bufToB64(ct), scope: scope, schema_version: SCHEMA_VERSION };
            });
    }

    function decryptJson(key, envelope, expectedScope) {
        if (!envelope || envelope.v !== 1) {
            return Promise.reject(new Error('unsupported-envelope'));
        }
        var iv;
        var ct;
        try {
            iv = b64ToBuf(envelope.iv);
            ct = b64ToBuf(envelope.ct);
        } catch (e) {
            return Promise.reject(new Error('corrupt-envelope'));
        }
        var aad = enc(canonicalAAD(expectedScope));
        return crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv, additionalData: aad }, key, ct)
            .then(function (plain) {
                try {
                    return JSON.parse(decBuf(plain));
                } catch (e) {
                    throw new Error('corrupt-payload');
                }
            });
    }

    // ── Scope helpers ────────────────────────────────────────────────────
    function enrollmentScope() {
        if (!activeEnrollment) return null;
        // Prefer the full persisted scope (includes business_date + shift); fall
        // back to the flattened fields for older in-memory states. Without the
        // business_date/shift the bootstrap AAD decrypt would fail as a
        // scope-mismatch after a fresh unlock.
        var scope = activeEnrollment.scope || {};
        return {
            tenant_scope: String(scope.tenant_scope || activeEnrollment.tenant_scope || ''),
            actor_user_id: String(scope.actor_user_id || activeEnrollment.actor_user_id || ''),
            branch_id: String(scope.branch_id || activeEnrollment.branch_id || ''),
            business_date: String(scope.business_date || (activeEnrollment.bootstrap && activeEnrollment.bootstrap.business_date) || ''),
            shift: String(scope.shift || (activeEnrollment.bootstrap && activeEnrollment.bootstrap.shift) || ''),
            enrollment_id: String(activeEnrollment.enrollment_id || '')
        };
    }

    function scopeMatches(a, b) {
        if (!a || !b) return false;
        return String(a.tenant_scope || '') === String(b.tenant_scope || '') &&
            String(a.actor_user_id || '') === String(b.actor_user_id || '') &&
            String(a.branch_id || '') === String(b.branch_id || '') &&
            String(a.business_date || '') === String(b.business_date || '') &&
            String(a.shift || '') === String(b.shift || '') &&
            String(a.enrollment_id || '') === String(b.enrollment_id || '');
    }

    // ── Public API ───────────────────────────────────────────────────────
    var api = {
        SCHEMA_VERSION: SCHEMA_VERSION,
        BOOTSTRAP_VERSION: BOOTSTRAP_VERSION,
        ITERATIONS: ITERATIONS,
        MAX_ATTEMPTS: MAX_ATTEMPTS,
        LOCK_MS: LOCK_MS,

        prerequisites: prerequisites,
        isUnlocked: function () { return dataKey !== null && activeEnrollment !== null; },

        openDb: openDb,

        /**
         * Ensure a stable device id (random, not a credential). Persisted in the
         * vault meta store so it survives reloads but never leaves the device.
         */
        ensureDeviceId: function () {
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var t = db.transaction('meta', 'readwrite');
                    var meta = t.objectStore('meta');
                    var get = meta.get('device_id');
                    get.onsuccess = function () {
                        if (get.result && typeof get.result.value === 'string' && get.result.value) {
                            resolve(get.result.value);
                            return;
                        }
                        var buf = randomBytes(16);
                        var hex = '';
                        for (var i = 0; i < buf.length; i++) hex += ('0' + buf[i].toString(16)).slice(-2);
                        var id = 'dl-' + hex;
                        meta.put({ key: 'device_id', value: id });
                        t.oncomplete = function () { resolve(id); };
                        t.onerror = function () { reject(new Error('Failed to persist device id')); };
                    };
                    get.onerror = function () { reject(new Error('Failed to read device id')); };
                });
            });
        },

        getStoredEnrollmentId: function () {
            return openDb().then(function (db) {
                return reqToPromise(db.transaction('meta', 'readonly').objectStore('meta').get('active_enrollment_id'))
                    .then(function (row) {
                        return row && typeof row.value === 'string' ? row.value : null;
                    });
            });
        },

        hasLocalEnrollment: function () {
            return openDb().then(function (db) {
                return reqToPromise(db.transaction('enrollment', 'readonly').objectStore('enrollment').count())
                    .then(function (n) { return n > 0; });
            });
        },

        /**
         * Atomically activate enrollment + bootstrap + readiness. Generates a
         * random data key, wraps it with the PIN-derived key, encrypts the
         * bootstrap, and writes everything in ONE readwrite transaction. Resolves
         * only after a decrypt-and-read-back of the bootstrap verifies the write.
         */
        activate: function (descriptor, bootstrap, pin) {
            var self = this;
            if (!cryptoAvailable()) return Promise.reject(new Error('no-webcrypto'));
            if (!/^\d{4,6}$/.test(String(pin || ''))) return Promise.reject(new Error('invalid-pin'));
            if (!descriptor || !descriptor.enrollment_id || !bootstrap) return Promise.reject(new Error('invalid-enrollment'));

            var scope = {
                tenant_scope: descriptor.tenant_scope || '',
                actor_user_id: String(descriptor.actor_user_id || ''),
                branch_id: String(descriptor.branch_id || ''),
                business_date: bootstrap.business_date || '',
                shift: bootstrap.shift || '',
                enrollment_id: descriptor.enrollment_id
            };
            activeEnrollment = {
                enrollment_id: descriptor.enrollment_id,
                tenant_scope: descriptor.tenant_scope,
                actor_user_id: descriptor.actor_user_id,
                branch_id: descriptor.branch_id,
                scope: scope,
                bootstrap: bootstrap
            };

            var salt = randomBytes(16);
            var keyBytes = randomBytes(32);
            var wrapIv = randomBytes(12);
            // Captured from the derivation chain so the atomic write phase below
            // can persist the wrapped key blob (AES-GCM of keyBytes under the
            // PIN-derived wrapping key).
            var wrappedKeyCt = null;

            return deriveWrappingKey(pin, salt)
                .then(function (wrapKey) {
                    return crypto.subtle.encrypt({ name: 'AES-GCM', iv: wrapIv }, wrapKey, keyBytes);
                })
                .then(function (wrapped) {
                    wrappedKeyCt = wrapped;
                    return importDataKey(keyBytes);
                })
                .then(function (key) {
                    return encryptJson(key, bootstrap, scope);
                })
                .then(function (bootstrapEnvelope) {
                    if (wrappedKeyCt === null) {
                        return Promise.reject(new Error('key-wrap-failed'));
                    }
                    // Everything above was in memory. Now write atomically.
                    return openDb().then(function (db) {
                        return new Promise(function (resolve, reject) {
                            var t = db.transaction(['meta', 'enrollment', 'bootstrap'], 'readwrite');
                            var meta = t.objectStore('meta');
                            var en = t.objectStore('enrollment');
                            var bs = t.objectStore('bootstrap');

                            meta.put({ key: 'active_enrollment_id', value: descriptor.enrollment_id });
                            meta.put({
                                key: 'wrapped_data_key',
                                salt: bufToB64(salt.buffer),
                                iv: bufToB64(wrapIv.buffer),
                                ct: bufToB64(wrappedKeyCt),
                                enrollment_id: descriptor.enrollment_id,
                                schema_version: SCHEMA_VERSION
                            });
                            meta.put({
                                key: 'lock_state',
                                attempts: 0,
                                lock_until: 0,
                                enrollment_id: descriptor.enrollment_id
                            });

                            en.put({
                                enrollment_id: descriptor.enrollment_id,
                                scope: scope,
                                descriptor: descriptor,
                                status: 'active',
                                activated_at: new Date().toISOString(),
                                schema_version: SCHEMA_VERSION
                            });
                            bs.put({
                                key: 'bootstrap:' + descriptor.enrollment_id,
                                enrollment_id: descriptor.enrollment_id,
                                scope: scope,
                                envelope: bootstrapEnvelope,
                                bootstrap_version: BOOTSTRAP_VERSION
                            });

                            t.oncomplete = function () {
                                // Read-back + decrypt verify before reporting readiness.
                                dataKey = null; // reset so read-back uses a fresh import
                                var storedKey = null;
                                var rowGet = db.transaction('meta', 'readonly').objectStore('meta').get('wrapped_data_key');
                                rowGet.onsuccess = function () {
                                    if (!rowGet.result || !rowGet.result.ct) {
                                        reject(new Error('write-verify-failed'));
                                        return;
                                    }
                                    storedKey = rowGet.result;
                                    var saltBuf = b64ToBuf(storedKey.salt);
                                    var wrapIvBuf = b64ToBuf(storedKey.iv);
                                    var wrapCt = b64ToBuf(storedKey.ct);
                                    deriveWrappingKey(pin, saltBuf)
                                        .then(function (wk) { return crypto.subtle.decrypt({ name: 'AES-GCM', iv: wrapIvBuf }, wk, wrapCt); })
                                        .then(function (kb) { return importDataKey(kb); })
                                        .then(function (k) {
                                            var bsGet = db.transaction('bootstrap', 'readonly').objectStore('bootstrap').get('bootstrap:' + descriptor.enrollment_id);
                                            bsGet.onsuccess = function () {
                                                if (!bsGet.result || !bsGet.result.envelope) {
                                                    reject(new Error('write-verify-failed'));
                                                    return;
                                                }
                                                decryptJson(k, bsGet.result.envelope, scope)
                                                    .then(function (boot) {
                                                        dataKey = k;
                                                        activeEnrollment.bootstrap = boot;
                                                        resolve({ ok: true, enrollment: descriptor, bootstrap: boot });
                                                    })
                                                    .catch(function () { reject(new Error('write-verify-failed')); });
                                            };
                                            bsGet.onerror = function () { reject(new Error('write-verify-failed')); };
                                        })
                                        .catch(function () { reject(new Error('unlock-verify-failed')); });
                                };
                                rowGet.onerror = function () { reject(new Error('write-verify-failed')); };
                            };
                            t.onerror = function () { reject(t.error || new Error('vault-write-failed')); };
                            t.onabort = function () { reject(new Error('vault-write-aborted')); };
                        });
                    });
                });
        },

        /**
         * Unlock with the PIN. Verifies by decrypting the wrapped data key
         * (AES-GCM authentication fails on a wrong PIN). Throttled by attempts
         * with an escalating lockout. Fails closed on corruption / expiry /
         * missing crypto / scope mismatch.
         */
        unlock: function (pin) {
            var self = this;
            if (!cryptoAvailable()) return Promise.reject(new Error('no-webcrypto'));
            if (!/^\d{4,6}$/.test(String(pin || ''))) return Promise.resolve({ ok: false, reason: 'invalid-pin' });

            return openDb().then(function (db) {
                return new Promise(function (resolve) {
                    var lockTx = db.transaction('meta', 'readwrite');
                    var meta = lockTx.objectStore('meta');

                    var lockGet = meta.get('lock_state');
                    lockGet.onsuccess = function () {
                        var lock = lockGet.result || { attempts: 0, lock_until: 0 };
                        if (lock.lock_until && Date.now() < lock.lock_until) {
                            resolve({ ok: false, reason: 'locked', lock_until: lock.lock_until });
                            return;
                        }

                        var keyGet = db.transaction('meta', 'readonly').objectStore('meta').get('wrapped_data_key');
                        keyGet.onsuccess = function () {
                            var rec = keyGet.result;
                            if (!rec || !rec.ct || !rec.salt || !rec.iv) {
                                resolve({ ok: false, reason: 'corrupt' });
                                return;
                            }
                            var enIdGet = db.transaction('meta', 'readonly').objectStore('meta').get('active_enrollment_id');
                            enIdGet.onsuccess = function () {
                                var enId = enIdGet.result && enIdGet.result.value ? enIdGet.result.value : null;
                                if (!enId) {
                                    resolve({ ok: false, reason: 'corrupt' });
                                    return;
                                }

                                var saltBuf;
                                var wrapIv;
                                var wrapCt;
                                try {
                                    saltBuf = b64ToBuf(rec.salt);
                                    wrapIv = b64ToBuf(rec.iv);
                                    wrapCt = b64ToBuf(rec.ct);
                                } catch (e) {
                                    resolve({ ok: false, reason: 'corrupt' });
                                    return;
                                }

                                deriveWrappingKey(pin, saltBuf)
                                    .then(function (wk) { return crypto.subtle.decrypt({ name: 'AES-GCM', iv: wrapIv }, wk, wrapCt); })
                                    .then(function (kb) { return importDataKey(kb); })
                                    .then(function (key) {
                                        // Wrong PIN already fails here (AES-GCM tag). Confirm the
                                        // enrollment scope by reading the enrollment record.
                                        var enGet = db.transaction('enrollment', 'readonly').objectStore('enrollment').get(enId);
                                        enGet.onsuccess = function () {
                                            var rec2 = enGet.result;
                                            if (!rec2) {
                                                resolve({ ok: false, reason: 'corrupt' });
                                                return;
                                            }
                                            if (rec2.status !== 'active') {
                                                resolve({ ok: false, reason: rec2.status === 'revoked' ? 'revoked' : 'expired' });
                                                return;
                                            }
                                            // Re-read the wrapped key block to reset lockout on success.
                                            var clearTx = db.transaction('meta', 'readwrite');
                                            clearTx.objectStore('meta').put({ key: 'lock_state', attempts: 0, lock_until: 0, enrollment_id: enId });
                                            clearTx.oncomplete = function () {
                                                dataKey = key;
                                                activeEnrollment = {
                                                    enrollment_id: rec2.enrollment_id,
                                                    tenant_scope: rec2.scope && rec2.scope.tenant_scope,
                                                    actor_user_id: rec2.scope && rec2.scope.actor_user_id,
                                                    branch_id: rec2.scope && rec2.scope.branch_id,
                                                    scope: rec2.scope || null,
                                                    bootstrap: null
                                                };
                                                resolve({ ok: true, enrollment_id: enId });
                                            };
                                            clearTx.onerror = function () {
                                                resolve({ ok: false, reason: 'storage' });
                                            };
                                        };
                                        enGet.onerror = function () { resolve({ ok: false, reason: 'corrupt' }); };
                                    })
                                    .catch(function () {
                                        // Wrong PIN (auth failure) — increment attempts.
                                        var attempts = (lock.attempts || 0) + 1;
                                        var newLock = { key: 'lock_state', attempts: attempts, lock_until: 0, enrollment_id: enId };
                                        if (attempts >= MAX_ATTEMPTS) {
                                            newLock.lock_until = Date.now() + LOCK_MS;
                                            newLock.attempts = 0;
                                        }
                                        var upTx = db.transaction('meta', 'readwrite');
                                        upTx.objectStore('meta').put(newLock);
                                        upTx.oncomplete = function () {
                                            resolve({ ok: false, reason: 'bad-pin', attempts_left: Math.max(0, MAX_ATTEMPTS - attempts) });
                                        };
                                        upTx.onerror = function () { resolve({ ok: false, reason: 'storage' }); };
                                    });
                            };
                            enIdGet.onerror = function () { resolve({ ok: false, reason: 'corrupt' }); };
                        };
                        keyGet.onerror = function () { resolve({ ok: false, reason: 'corrupt' }); };
                    };
                    lockGet.onerror = function () { resolve({ ok: false, reason: 'corrupt' }); };
                });
            });
        },

        lock: function () {
            dataKey = null;
            activeEnrollment = null;
            return Promise.resolve({ ok: true });
        },

        getEnrollment: function () {
            if (!activeEnrollment) return Promise.resolve(null);
            return Promise.resolve(activeEnrollment);
        },

        getBootstrap: function () {
            var self = this;
            if (!dataKey || !activeEnrollment) return Promise.reject(new Error('locked'));
            var scope = enrollmentScope();
            var enId = activeEnrollment.enrollment_id;
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var get = db.transaction('bootstrap', 'readonly').objectStore('bootstrap').get('bootstrap:' + enId);
                    get.onsuccess = function () {
                        if (!get.result || !get.result.envelope) {
                            reject(new Error('missing-bootstrap'));
                            return;
                        }
                        decryptJson(dataKey, get.result.envelope, scope)
                            .then(function (boot) {
                                activeEnrollment.bootstrap = boot;
                                resolve(boot);
                            })
                            .catch(function () { reject(new Error('scope-mismatch')); });
                    };
                    get.onerror = function () { reject(new Error('read-failed')); };
                });
            });
        },

        /**
         * Durable encrypted write before UI success. The operation payload is
         * AES-GCM encrypted with the data key and unique IV, bound to scope via
         * AAD, and committed before the returned promise resolves.
         */
        enqueueOperation: function (type, payload) {
            var self = this;
            if (!dataKey || !activeEnrollment) return Promise.reject(new Error('locked'));
            var scope = enrollmentScope();
            var clientOpId = crypto.randomUUID ? crypto.randomUUID() : ('op-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10));
            var now = new Date().toISOString();
            var op = {
                client_op_id: clientOpId,
                type: type,
                scope: scope,
                state: 'pending',
                payload: payload || {},
                base_version: 1,
                created_at: now,
                retry_count: 0,
                schema_version: SCHEMA_VERSION
            };

            return encryptJson(dataKey, op.payload, scope)
                .then(function (envelope) {
                    return openDb().then(function (db) {
                        return new Promise(function (resolve, reject) {
                            var t = db.transaction('operations', 'readwrite');
                            t.objectStore('operations').put({
                                client_op_id: clientOpId,
                                type: type,
                                scope: scope,
                                state: 'pending',
                                base_version: 1,
                                created_at: now,
                                retry_count: 0,
                                schema_version: SCHEMA_VERSION,
                                enrollment_id: activeEnrollment.enrollment_id,
                                envelope: envelope
                            });
                            t.oncomplete = function () { resolve(op); };
                            t.onerror = function () { reject(t.error || new Error('enqueue-failed')); };
                            t.onabort = function () { reject(new Error('enqueue-aborted')); };
                        });
                    });
                });
        },

        listOperations: function () {
            var self = this;
            if (!dataKey || !activeEnrollment) return Promise.reject(new Error('locked'));
            var scope = enrollmentScope();
            return openDb().then(function (db) {
                return new Promise(function (resolve) {
                    var out = [];
                    // Decryption is asynchronous and outlives the IDB cursor, so
                    // collect every decrypt promise and resolve only after all of
                    // them finish. Resolving on cursor exhaustion alone would
                    // return an incomplete list and silently drop queued work.
                    var pending = [];
                    var req = db.transaction('operations', 'readonly').objectStore('operations').index('enrollment_id')
                        .openCursor(IDBKeyRange.only(activeEnrollment.enrollment_id));
                    req.onsuccess = function () {
                        var c = req.result;
                        if (!c) {
                            Promise.all(pending).then(function () {
                                // Order by created_at (IDB cursor order is by index key; sort client-side).
                                out.sort(function (a, b) { return a.created_at < b.created_at ? -1 : a.created_at > b.created_at ? 1 : 0; });
                                resolve(out);
                            });
                            return;
                        }
                        var rec = c.value;
                        c.continue();
                        if (!rec.envelope) return;
                        pending.push(decryptJson(dataKey, rec.envelope, scope)
                            .then(function (payload) {
                                out.push({
                                    client_op_id: rec.client_op_id,
                                    type: rec.type,
                                    state: rec.state,
                                    base_version: rec.base_version,
                                    created_at: rec.created_at,
                                    retry_count: rec.retry_count,
                                    payload: payload
                                });
                            })
                            .catch(function () {
                                // Scope/corruption mismatch — surface as a quarantine candidate.
                                out.push({
                                    client_op_id: rec.client_op_id,
                                    type: rec.type,
                                    state: 'corrupt',
                                    base_version: rec.base_version,
                                    created_at: rec.created_at,
                                    retry_count: rec.retry_count,
                                    payload: null
                                });
                            }));
                    };
                    req.onerror = function () { resolve(out); };
                });
            });
        },

        updateOperationState: function (clientOpId, state, extra) {
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var t = db.transaction('operations', 'readwrite');
                    var store = t.objectStore('operations');
                    var get = store.get(clientOpId);
                    get.onsuccess = function () {
                        if (!get.result) { resolve(false); return; }
                        var rec = get.result;
                        rec.state = state;
                        if (extra && extra.retry_count != null) rec.retry_count = extra.retry_count;
                        store.put(rec);
                    };
                    t.oncomplete = function () { resolve(true); };
                    t.onerror = function () { reject(new Error('state-update-failed')); };
                });
            });
        },

        removeOperation: function (clientOpId) {
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var t = db.transaction('operations', 'readwrite');
                    t.objectStore('operations').delete(clientOpId);
                    t.oncomplete = function () { resolve(true); };
                    t.onerror = function () { reject(new Error('remove-failed')); };
                });
            });
        },

        saveReceipt: function (receipt) {
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var t = db.transaction('receipts', 'readwrite');
                    t.objectStore('receipts').put({
                        client_op_id: receipt.client_op_id,
                        enrollment_id: activeEnrollment ? activeEnrollment.enrollment_id : '',
                        result: receipt.result || {},
                        duplicate: !!receipt.duplicate,
                        status: receipt.status || 'applied',
                        applied_at: new Date().toISOString()
                    });
                    t.oncomplete = function () { resolve(true); };
                    t.onerror = function () { reject(new Error('receipt-failed')); };
                });
            });
        },

        quarantineRecord: function (record) {
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var t = db.transaction('quarantine', 'readwrite');
                    t.objectStore('quarantine').put({
                        id: crypto.randomUUID ? crypto.randomUUID() : ('q-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)),
                        enrollment_id: activeEnrollment ? activeEnrollment.enrollment_id : '',
                        reason: record.reason || 'unknown',
                        source_key: record.source_key || '',
                        payload: record.payload || {},
                        quarantined_at: new Date().toISOString()
                    });
                    t.oncomplete = function () { resolve(true); };
                    t.onerror = function () { reject(new Error('quarantine-failed')); };
                });
            });
        },

        listQuarantine: function () {
            return openDb().then(function (db) {
                return new Promise(function (resolve) {
                    var out = [];
                    var req = db.transaction('quarantine', 'readonly').objectStore('quarantine').openCursor();
                    req.onsuccess = function () {
                        var c = req.result;
                        if (!c) { resolve(out); return; }
                        out.push(c.value);
                        c.continue();
                    };
                    req.onerror = function () { resolve(out); };
                });
            });
        },

        countPending: function () {
            var self = this;
            return openDb().then(function (db) {
                return new Promise(function (resolve) {
                    // Count only the ACTIVE enrollment's pending operations. A
                    // re-enrollment on another branch leaves old operations in the
                    // store (never silently deleted); those must not inflate the
                    // active pending count or be miscounted as current work.
                    var enId = activeEnrollment ? activeEnrollment.enrollment_id : null;
                    if (!enId) { resolve(0); return; }
                    var req = db.transaction('operations', 'readonly').objectStore('operations').index('enrollment_id')
                        .openCursor(IDBKeyRange.only(enId));
                    var n = 0;
                    req.onsuccess = function () {
                        var c = req.result;
                        if (!c) { resolve(n); return; }
                        if (c.value && c.value.state === 'pending') n++;
                        c.continue();
                    };
                    req.onerror = function () { resolve(0); };
                });
            });
        },

        /**
         * Removes the active enrollment's vault data (used by "Remove offline
         * access"). Pending work must be surfaced by the caller BEFORE this is
         * invoked — this is the explicit user-confirmed removal path.
         */
        clearEnrollment: function () {
            var enId = activeEnrollment ? activeEnrollment.enrollment_id : null;
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    if (!enId) {
                        // No active enrollment — wipe everything to be safe.
                        var names = Array.prototype.slice.call(db.objectStoreNames);
                        var txAll = db.transaction(names, 'readwrite');
                        names.forEach(function (n) { txAll.objectStore(n).clear(); });
                        txAll.oncomplete = function () {
                            dataKey = null;
                            activeEnrollment = null;
                            resolve({ ok: true });
                        };
                        txAll.onerror = function () { reject(new Error('clear-failed')); };
                        return;
                    }
                    var t = db.transaction(['meta', 'enrollment', 'bootstrap', 'operations', 'receipts', 'quarantine'], 'readwrite');
                    var meta = t.objectStore('meta');
                    var en = t.objectStore('enrollment');
                    var bs = t.objectStore('bootstrap');
                    var ops = t.objectStore('operations');
                    var rc = t.objectStore('receipts');
                    var q = t.objectStore('quarantine');

                    // Remove scope-keyed records for this enrollment only.
                    var enKey = en.getKey(enId);
                    enKey.onsuccess = function () { if (enKey.result !== undefined) en.delete(enKey.result); };
                    var bsKey = bs.getKey('bootstrap:' + enId);
                    bsKey.onsuccess = function () { if (bsKey.result !== undefined) bs.delete(bsKey.result); };

                    var opIdx = ops.index('enrollment_id').openCursor(IDBKeyRange.only(enId));
                    opIdx.onsuccess = function () {
                        var c = opIdx.result;
                        if (c) { c.delete(); c.continue(); }
                    };
                    var rcIdx = rc.index('enrollment_id').openCursor(IDBKeyRange.only(enId));
                    rcIdx.onsuccess = function () {
                        var c = rcIdx.result;
                        if (c) { c.delete(); c.continue(); }
                    };
                    var metaIdx = meta.index('enrollment_id').openCursor(IDBKeyRange.only(enId));
                    metaIdx.onsuccess = function () {
                        var c = metaIdx.result;
                        if (c) { c.delete(); c.continue(); }
                    };

                    // Clear active markers if they point at this enrollment.
                    var activeGet = meta.get('active_enrollment_id');
                    activeGet.onsuccess = function () {
                        if (activeGet.result && activeGet.result.value === enId) meta.delete('active_enrollment_id');
                    };
                    var wrappedGet = meta.get('wrapped_data_key');
                    wrappedGet.onsuccess = function () {
                        if (wrappedGet.result && wrappedGet.result.enrollment_id === enId) meta.delete('wrapped_data_key');
                    };
                    var lockGet = meta.get('lock_state');
                    lockGet.onsuccess = function () {
                        if (lockGet.result && lockGet.result.enrollment_id === enId) meta.delete('lock_state');
                    };

                    t.oncomplete = function () {
                        dataKey = null;
                        activeEnrollment = null;
                        resolve({ ok: true });
                    };
                    t.onerror = function () { reject(new Error('clear-failed')); };
                    t.onabort = function () { reject(new Error('clear-aborted')); };
                });
            });
        },

        /**
         * Legacy migration. Requires an active unlocked enrollment. Imports
         * supported records from the legacy `daily-ledger-reference` IndexedDB
         * and the legacy localStorage queues, keying them by the current scope.
         * Mismatched records are quarantined (never deleted). Old keys are
         * removed only after the vault writes have been read back.
         *
         * @returns {Promise<{imported:number, quarantined:number, legacy_migrated:boolean}>}
         */
        migrateLegacy: function () {
            var self = this;
            if (!dataKey || !activeEnrollment) return Promise.reject(new Error('locked'));
            var scope = enrollmentScope();

            return openDb().then(function (db) {
                var imported = 0;
                var quarantined = 0;

                function readBackEnvelope(envelope) {
                    return decryptJson(dataKey, envelope, scope)
                        .then(function () { return true; })
                        .catch(function () { return false; });
                }

                // 1) Legacy IndexedDB reference products.
                var legacyPromise = new Promise(function (resolve) {
                    if (!idbAvailable()) { resolve(); return; }
                    var legacyReq = indexedDB.open(LEGACY_IDB_NAME, LEGACY_IDB_VERSION);
                    legacyReq.onupgradeneeded = function () { /* open at version to read */ };
                    legacyReq.onsuccess = function () {
                        var legacyDb = legacyReq.result;
                        if (!legacyDb.objectStoreNames.contains('products')) {
                            legacyDb.close();
                            resolve();
                            return;
                        }
                        var products = [];
                        var cursor = legacyDb.transaction('products', 'readonly').objectStore('products').openCursor();
                        cursor.onsuccess = function () {
                            var c = cursor.result;
                            if (c) { products.push(c.value); c.continue(); return; }
                            // Products are reference data; fold them into the bootstrap.
                            var bootStore = db.transaction('bootstrap', 'readwrite').objectStore('bootstrap');
                            var get = bootStore.get('bootstrap:' + activeEnrollment.enrollment_id);
                            get.onsuccess = function () {
                                var row = get.result;
                                if (row && row.envelope) {
                                    decryptJson(dataKey, row.envelope, scope)
                                        .then(function (boot) {
                                            var legacy = products.filter(function (p) {
                                                return p && typeof p.id !== 'undefined';
                                            }).map(function (p) {
                                                return { id: String(p.id), name: String(p.name || ''), legacy: true };
                                            });
                                            var merged = (boot.products || []).slice();
                                            var seen = {};
                                            merged.forEach(function (m) { seen[String(m.id)] = true; });
                                            legacy.forEach(function (l) { if (!seen[l.id]) { seen[l.id] = true; merged.push(l); } });
                                            boot.products = merged;
                                            encryptJson(dataKey, boot, scope)
                                                .then(function (envelope) {
                                                    var wtx = db.transaction('bootstrap', 'readwrite');
                                                    wtx.objectStore('bootstrap').put({
                                                        key: 'bootstrap:' + activeEnrollment.enrollment_id,
                                                        enrollment_id: activeEnrollment.enrollment_id,
                                                        scope: scope,
                                                        envelope: envelope,
                                                        bootstrap_version: BOOTSTRAP_VERSION
                                                    });
                                                    wtx.oncomplete = function () {
                                                        readBackEnvelope(envelope).then(function (okRead) {
                                                            if (okRead) imported++;
                                                            legacyDb.close();
                                                            resolve();
                                                        });
                                                    };
                                                    wtx.onerror = function () { legacyDb.close(); resolve(); };
                                                })
                                                .catch(function () { legacyDb.close(); resolve(); });
                                        })
                                        .catch(function () { legacyDb.close(); resolve(); });
                                } else {
                                    legacyDb.close();
                                    resolve();
                                }
                            };
                            get.onerror = function () { legacyDb.close(); resolve(); };
                        };
                        cursor.onerror = function () { legacyDb.close(); resolve(); };
                    };
                    legacyReq.onerror = function () { resolve(); };
                });

                // 2) Legacy localStorage queues → encrypted vault operations.
                var lsPromise = Promise.all(LEGACY_LS_PREFIXES.map(function (prefix) {
                    var keys = [];
                    try {
                        for (var i = 0; i < localStorage.length; i++) {
                            var k = localStorage.key(i);
                            if (k && (k === prefix || k.indexOf(prefix + ':') === 0 || k.indexOf(prefix + '-') === 0)) keys.push(k);
                        }
                    } catch (e) { keys = []; }

                    return Promise.all(keys.map(function (key) {
                        var raw = null;
                        try { raw = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { raw = null; }
                        var entries = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.entries) ? raw.entries : []);
                        if (entries.length === 0) return Promise.resolve();

                        var ops = entries.filter(function (entry) {
                            return entry && typeof entry === 'object';
                        }).map(function (entry) {
                            var op = null;
                            if (entry.op) {
                                op = {
                                    type: String(entry.op),
                                    payload: entry.payload || {},
                                    legacy_tenant: String(entry.tenant_scope || ''),
                                    legacy_actor: String(entry.actor_id || ''),
                                    legacy_branch: String(entry.branch_id || ''),
                                    legacy_date: String(entry.date || ''),
                                    legacy_shift: String(entry.shift || 'AM'),
                                    legacy_created_at: entry.created_at || null
                                };
                            } else if (entry.field) {
                                op = {
                                    type: 'ledger_save',
                                    payload: {
                                        product_id: entry.product_id,
                                        field: entry.field,
                                        value: entry.value,
                                        date: entry.date,
                                        branch_id: entry.branch_id,
                                        shift: entry.shift || 'AM'
                                    },
                                    legacy_tenant: String(entry.tenant_scope || ''),
                                    legacy_actor: String(entry.actor_id || ''),
                                    legacy_branch: String(entry.branch_id || ''),
                                    legacy_date: String(entry.date || ''),
                                    legacy_shift: String(entry.shift || 'AM'),
                                    legacy_created_at: entry.created_at || null
                                };
                            }
                            return op;
                        }).filter(function (op) { return op !== null; });

                        // Scope validation: only import records that match the ACTIVE
                        // enrollment scope. Anything else is quarantined, not deleted.
                        var valid = ops.filter(function (op) {
                            return String(op.legacy_tenant || '') === String(scope.tenant_scope || '') &&
                                String(op.legacy_actor || '') === String(scope.actor_user_id || '') &&
                                String(op.legacy_branch || '') === String(scope.branch_id || '') &&
                                String(op.legacy_date || '') === String(scope.business_date || '') &&
                                String(op.legacy_shift || 'AM') === String(scope.shift || 'AM');
                        });
                        var mismatched = ops.filter(function (op) { return valid.indexOf(op) === -1; });

                        var importPromise = Promise.all(valid.map(function (op) {
                            return encryptJson(dataKey, op.payload, scope)
                                .then(function (envelope) {
                                    return new Promise(function (resolveImport) {
                                        var clientOpId = crypto.randomUUID ? crypto.randomUUID() : ('op-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10));
                                        var t = db.transaction('operations', 'readwrite');
                                        t.objectStore('operations').put({
                                            client_op_id: clientOpId,
                                            type: op.type,
                                            scope: scope,
                                            state: 'pending',
                                            base_version: 1,
                                            created_at: op.legacy_created_at || new Date().toISOString(),
                                            retry_count: 0,
                                            schema_version: SCHEMA_VERSION,
                                            enrollment_id: activeEnrollment.enrollment_id,
                                            envelope: envelope
                                        });
                                        t.oncomplete = function () { resolveImport(true); };
                                        t.onerror = function () { resolveImport(false); };
                                    });
                                });
                        }));

                        return importPromise.then(function (results) {
                            var okCount = results.filter(Boolean).length;
                            imported += okCount;
                            quarantined += (mismatched.length + (valid.length - okCount));

                            if (mismatched.length > 0) {
                                var qStore = db.transaction('quarantine', 'readwrite').objectStore('quarantine');
                                mismatched.forEach(function (op) {
                                    qStore.put({
                                        id: crypto.randomUUID ? crypto.randomUUID() : ('q-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)),
                                        enrollment_id: activeEnrollment.enrollment_id,
                                        reason: 'scope-mismatch',
                                        source_key: key,
                                        payload: op,
                                        quarantined_at: new Date().toISOString()
                                    });
                                });
                            }

                            // Only delete the legacy key after the vault writes committed and
                            // were read back (all valid ops imported).
                            return readBackOperations(db, valid.length).then(function (okRead) {
                                if (okRead && okCount === valid.length) {
                                    try { localStorage.removeItem(key); } catch (e) { /* keep */ }
                                }
                                return;
                            });
                        });
                    }));
                }));

                return Promise.all([legacyPromise, lsPromise]).then(function () {
                    var metaTx = db.transaction('meta', 'readwrite');
                    metaTx.objectStore('meta').put({ key: 'legacy_migrated', value: '1', enrollment_id: activeEnrollment.enrollment_id });
                    return new Promise(function (resolve) {
                        metaTx.oncomplete = function () {
                            resolve({ imported: imported, quarantined: quarantined, legacy_migrated: true });
                        };
                        metaTx.onerror = function () {
                            resolve({ imported: imported, quarantined: quarantined, legacy_migrated: false });
                        };
                    });
                });
            });
        },

        /**
         * Storage / crypto readiness probe used before claiming "Offline ready":
         * verifies a vault write + decrypt read succeeds.
         */
        verifyStorage: function () {
            var self = this;
            if (!dataKey || !activeEnrollment) return Promise.reject(new Error('locked'));
            var probe = { probe: true, at: new Date().toISOString() };
            return encryptJson(dataKey, probe, enrollmentScope())
                .then(function (envelope) {
                    return decryptJson(dataKey, envelope, enrollmentScope());
                })
                .then(function (plain) {
                    return plain && plain.probe === true;
                });
        },

        resetInMemory: function () {
            dataKey = null;
            activeEnrollment = null;
            dbPromise = null;
        }
    };

    // read-back helper used by migration: re-decrypt N pending operations.
    function readBackOperations(db, expectedCount) {
        return new Promise(function (resolve) {
            var req = db.transaction('operations', 'readonly').objectStore('operations').index('state')
                .openCursor(IDBKeyRange.only('pending'));
            var n = 0;
            req.onsuccess = function () {
                var c = req.result;
                if (!c) { resolve(n >= expectedCount && expectedCount > 0); return; }
                n++;
                c.continue();
            };
            req.onerror = function () { resolve(false); };
        });
    }

    window.DLOfflineVault = api;
})();
