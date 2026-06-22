/**
 * ikbInlineEdit — Alpine.js component for inline entity field editing.
 *
 * Renders a static display value that becomes an editable input/select on click.
 * Saves via POST /api/v1/entity/update with optimistic locking support.
 *
 * Usage in HTML (rendered by DefaultEntityRenderer / InlineEditRenderer):
 *
 *   <td x-data="ikbInlineEdit({
 *       entityId: 42,
 *       field: 'status',
 *       value: 'open',
 *       displayHtml: '<span class=\"...\">Open</span>',
 *       capability: 'guidance.case.status.update@1',
 *       allowedValues: ['open', 'closed', 'in_progress', 'on_hold'],
 *       version: 7,
 *       renderer: 'badge'
 *   })">
 *       <template x-if="!editing">
 *           <span @click="startEdit" class="cursor-pointer hover:ring-2 hover:ring-brand-300 rounded inline-block"
 *                 :aria-label="'Click to edit ' + field" role="button" tabindex="0"
 *                 @keydown.enter="startEdit">
 *               <span x-html="displayHtml"></span>
 *           </span>
 *       </template>
 *       <template x-if="editing">
 *           <div class="flex items-center gap-1">
 *               <select x-show="isSelect" x-model="newValue" @change="save" @click.stop
 *                       @keydown.escape="cancel"
 *                       class="text-sm border border-brand-300 rounded px-2 py-1 bg-white focus:ring-2 focus:ring-brand-500"
 *                       :aria-label="'Edit ' + field">
 *                   <template x-for="opt in allowedValues" :key="opt">
 *                       <option :value="opt" x-text="opt" :selected="opt === value"></option>
 *                   </template>
 *               </select>
 *               <input x-show="!isSelect" type="text" x-model="newValue"
 *                      @blur="save" @keydown.enter="save" @keydown.escape="cancel"
 *                      class="text-sm border border-brand-300 rounded px-2 py-1 w-full focus:ring-2 focus:ring-brand-500"
 *                      :aria-label="'Edit ' + field">
 *               <button @click="cancel" class="text-gray-400 hover:text-gray-600 p-1" title="Cancel" aria-label="Cancel editing">&times;</button>
 *           </div>
 *       </template>
 *       <div x-show="saving" class="inline-block ml-1 text-xs text-gray-400" aria-busy="true">saving...</div>
 *       <div x-show="error" x-text="error" class="text-xs text-red-600 mt-1" role="alert" aria-live="polite"></div>
 *   </td>
 *
 * @package Ikabud\Kernel
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('ikbInlineEdit', (config) => ({
        // ── State ──
        editing: false,
        value: config.value,
        newValue: config.value,
        displayHtml: config.displayHtml || '',
        version: config.version || null,
        saving: false,
        error: null,

        // ── Config ──
        entityId: config.entityId,
        field: config.field,
        capability: config.capability,
        allowedValues: config.allowedValues || [],
        renderer: config.renderer || null,
        rowData: config.rowData || {},

        // ── Computed ──
        get isSelect() {
            return this.allowedValues.length > 0;
        },

        // ── Actions ──
        startEdit() {
            if (this.saving) return;
            this.newValue = this.value;
            this.editing = true;
            this.error = null;
            this.$nextTick(() => {
                const input = this.$el.querySelector('input, select');
                if (input) input.focus();
            });
        },

        cancel() {
            this.editing = false;
            this.newValue = this.value;
            this.error = null;
        },

        async save() {
            if (this.newValue === this.value) {
                this.editing = false;
                return;
            }
            this.saving = true;
            this.error = null;

            try {
                // Get CSRF token from meta tag if available
                const metaToken = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = metaToken ? metaToken.getAttribute('content') : '';

                const response = await fetch('/api/v1/entity/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
                    },
                    body: JSON.stringify({
                        capability: this.capability,
                        entity_id: this.entityId,
                        field: this.field,
                        value: this.newValue,
                        expected_version: this.version,
                        renderer: this.renderer,
                        row_data: this.rowData,
                    }),
                });

                const result = await response.json();

                if (!result.ok) {
                    this.error = result.error || 'Update failed';
                    return;
                }

                const data = result.data || {};

                // Update local state
                this.value = data.raw_value;
                this.version = data.version || this.version;

                // Update display HTML from server
                if (data.display_html) {
                    this.displayHtml = data.display_html;
                } else {
                    this.displayHtml = Alpine.raw(String(data.raw_value));
                }

                this.editing = false;

            } catch (e) {
                this.error = 'Network error. Please try again.';
            } finally {
                this.saving = false;
            }
        },
    }));
});
