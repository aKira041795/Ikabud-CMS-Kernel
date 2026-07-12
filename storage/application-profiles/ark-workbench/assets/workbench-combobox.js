/**
 * ARK Workbench v0.1 — Combobox
 *
 * Accessible searchable select with keyboard navigation.
 * Requires: workbench-core.js (for wbToast)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wb-combobox').forEach(initCombobox);
    });

    function initCombobox(container) {
        var input = container.querySelector('.wb-combobox__input');
        var listbox = container.querySelector('.wb-combobox__listbox');
        var options = container.querySelectorAll('.wb-combobox__option');
        var hiddenInput = container.querySelector('input[type="hidden"]');
        var isOpen = false;
        var activeIndex = -1;

        if (!input || !listbox) return;

        function getVisibleOptions() {
            return Array.from(options).filter(function (o) { return !o.hidden; });
        }

        function open() {
            isOpen = true;
            listbox.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            resetActiveIndex();
            highlightActive();
        }

        function resetActiveIndex() {
            var visible = getVisibleOptions();
            var selectedIdx = visible.findIndex(function (opt) {
                return opt.getAttribute('aria-selected') === 'true';
            });
            activeIndex = selectedIdx >= 0 ? selectedIdx : (visible.length > 0 ? 0 : -1);
        }

        function highlightActive() {
            var visible = getVisibleOptions();
            var option = visible[activeIndex];
            options.forEach(function (opt) {
                opt.classList.remove('wb-combobox__option--highlighted');
            });
            if (option) {
                option.classList.add('wb-combobox__option--highlighted');
                input.setAttribute('aria-activedescendant', option.id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function close() {
            isOpen = false;
            listbox.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function selectOption(index) {
            var visible = getVisibleOptions();
            var option = visible[index];
            if (!option) return;
            var value = option.getAttribute('data-value');
            var label = option.textContent.trim();

            options.forEach(function (o) { o.setAttribute('aria-selected', 'false'); });
            option.setAttribute('aria-selected', 'true');
            input.value = label;

            if (hiddenInput) {
                hiddenInput.value = value;
            }

            container.dispatchEvent(new CustomEvent('wb-combobox:change', {
                detail: { value: value, label: label }
            }));

            close();
        }

        function filterOptions(query) {
            var q = query.toLowerCase();
            var hasResults = false;
            options.forEach(function (opt) {
                var match = opt.textContent.toLowerCase().indexOf(q) !== -1;
                opt.hidden = !match;
                if (match) hasResults = true;
            });
            var emptyMsg = container.querySelector('.wb-combobox__empty');
            if (emptyMsg) emptyMsg.hidden = hasResults;
        }

        input.addEventListener('focus', function () { open(); });
        input.addEventListener('input', function () {
            if (!isOpen) open();
            filterOptions(this.value);
            resetActiveIndex();
            highlightActive();
        });

        input.addEventListener('keydown', function (e) {
            var visible = getVisibleOptions();

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (!isOpen) { open(); return; }
                    if (visible.length === 0) return;
                    activeIndex = Math.min(activeIndex + 1, visible.length - 1);
                    highlightActive();
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (!isOpen) { open(); return; }
                    if (visible.length === 0) return;
                    activeIndex = Math.max(activeIndex - 1, 0);
                    highlightActive();
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (isOpen && activeIndex >= 0 && visible.length > 0) {
                        selectOption(activeIndex);
                    }
                    break;
                case 'Escape':
                    close();
                    break;
            }
        });

        listbox.addEventListener('click', function (e) {
            var option = e.target.closest('.wb-combobox__option');
            if (option) {
                var visible = getVisibleOptions();
                var idx = visible.indexOf(option);
                if (idx >= 0) selectOption(idx);
            }
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!container.contains(e.target)) close();
        });
    }
})();
