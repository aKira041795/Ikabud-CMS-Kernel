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

        function open() {
            isOpen = true;
            listbox.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            activeIndex = findSelectedIndex() || 0;
            highlightOption(activeIndex);
        }

        function close() {
            isOpen = false;
            listbox.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function findSelectedIndex() {
            for (var i = 0; i < options.length; i++) {
                if (options[i].getAttribute('aria-selected') === 'true') return i;
            }
            return -1;
        }

        function highlightOption(index) {
            options.forEach(function (opt, i) {
                opt.classList.toggle('wb-combobox__option--highlighted', i === index);
                if (i === index) {
                    input.setAttribute('aria-activedescendant', opt.id);
                }
            });
        }

        function selectOption(index) {
            var option = options[index];
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
            activeIndex = 0;
            highlightOption(activeIndex);
        });

        input.addEventListener('keydown', function (e) {
            var visibleOptions = Array.from(options).filter(function (o) { return !o.hidden; });

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (!isOpen) { open(); return; }
                    activeIndex = Math.min(activeIndex + 1, visibleOptions.length - 1);
                    highlightOption(Array.from(options).indexOf(visibleOptions[activeIndex]));
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (!isOpen) { open(); return; }
                    activeIndex = Math.max(activeIndex - 1, 0);
                    highlightOption(Array.from(options).indexOf(visibleOptions[activeIndex]));
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (isOpen && activeIndex >= 0) {
                        var realIndex = Array.from(options).indexOf(visibleOptions[activeIndex]);
                        selectOption(realIndex);
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
                selectOption(Array.from(options).indexOf(option));
            }
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!container.contains(e.target)) close();
        });
    }
})();
