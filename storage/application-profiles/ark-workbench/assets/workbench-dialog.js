/**
 * ARK Workbench v0.1 — Dialog
 *
 * Accessible modal dialog with focus trap.
 * Trigger: <button data-wb-dialog="dialog-id">Open</button>
 * Dialog: <div id="dialog-id" class="wb-dialog" role="dialog" aria-modal="true">
 */
(function () {
    'use strict';

    var activeDialog = null;
    var previousFocus = null;

    document.addEventListener('DOMContentLoaded', function () {
        initDialogTriggers();
    });

    function initDialogTriggers() {
        document.querySelectorAll('[data-wb-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var dialogId = this.getAttribute('data-wb-dialog');
                var dialog = document.getElementById(dialogId);
                if (dialog) openDialog(dialog, this);
            });
        });
    }

    function openDialog(dialog, trigger) {
        previousFocus = trigger || document.activeElement;
        activeDialog = dialog;

        dialog.hidden = false;
        dialog.setAttribute('aria-modal', 'true');
        document.body.style.overflow = 'hidden';

        // Focus first focusable element
        var focusable = getFocusableElements(dialog);
        if (focusable.length) focusable[0].focus();

        // Trap focus
        dialog.addEventListener('keydown', trapFocus);

        // Close buttons
        dialog.querySelectorAll('[data-wb-dialog-close]').forEach(function (btn) {
            btn.addEventListener('click', function () { closeDialog(dialog); });
        });

        // Backdrop click
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog && dialog.getAttribute('data-wb-close-on-backdrop') !== 'false') {
                closeDialog(dialog);
            }
        });
    }

    function closeDialog(dialog) {
        dialog.hidden = true;
        dialog.removeAttribute('aria-modal');
        document.body.style.overflow = '';
        dialog.removeEventListener('keydown', trapFocus);

        activeDialog = null;

        // Restore focus
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    }

    function trapFocus(e) {
        if (e.key !== 'Tab' || !activeDialog) return;

        var focusable = getFocusableElements(activeDialog);
        if (!focusable.length) return;

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function getFocusableElements(container) {
        return Array.from(container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(function (el) { return el.offsetParent !== null; });
    }

    // Global Escape handler
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && activeDialog) {
            closeDialog(activeDialog);
        }
    });
})();
