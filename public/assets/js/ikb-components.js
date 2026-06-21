/**
 * ikb-components — DiSyL Alpine.js Bridge
 *
 * Provides the global `ikbComponent()` Alpine function used by the
 * `{ikb_component}` DiSyL tag to initialize reactive component state.
 *
 * Each component instance receives a JSON data blob from the server.
 * The function makes it reactive via Alpine's magic `$data` and provides
 * standard event methods and computed properties.
 *
 * Usage in templates:
 *   <div x-data="ikbComponent({...serverData...})">
 *     <span x-text="name"></span>
 *   </div>
 *
 * This bridge is designed to be swappable for a future DiSyL client
 * runtime (Island hydration) — the `ikbComponent()` function signature
 * remains stable while the implementation can be replaced.
 */
window.ikbComponent = function (data) {
    return {
        // ── Incoming data ──
        ...data,

        // ── Lifecycle ──
        init() {
            // Allow components to define an init() handler in their data
            if (typeof this._init === 'function') {
                this._init.call(this);
            }
        },

        // ── Helpers ──
        /**
         * Dispatch a custom event on the window for cross-component communication.
         * Other components can listen with: window.addEventListener('ikb:event', ...)
         */
        dispatch(name, detail) {
            window.dispatchEvent(new CustomEvent('ikb:' + name, {
                detail: { ...detail, source: this }
            }));
        },

        /**
         * Toggle a boolean state value by key.
         * Usage: @click="toggle('open')"
         */
        toggle(key) {
            if (key in this) {
                this[key] = !this[key];
            }
        },

        /**
         * Set a value by key.
         * Usage: @click="set('step', 2)"
         */
        set(key, value) {
            this[key] = value;
        },

        /**
         * Submit a form-like POST via fetch with JSON body.
         * Expects `this.action` and optionally `this.method` in data.
         */
        async submit() {
            if (!this.action) return;
            const method = (this.method || 'POST').toUpperCase();
            const body = method === 'GET' ? undefined : JSON.stringify(this._payload?.() || {});
            const resp = await fetch(this.action, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body,
            });
            const result = await resp.json();
            this.dispatch('response', { result });
            return result;
        },
    };
};
