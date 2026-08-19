/* ==========================================================================
   DFM — application shell behaviour
   Mobile drawer, dropdowns, command palette, modals, toasts.
   No framework, no build step. Progressive: the app is fully navigable
   with JavaScript disabled — this layer only adds convenience.
   ========================================================================== */

(function () {
    'use strict';

    var focusReturn = null;

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /* ---------------------------------------------------------------- utils */

    // Keeps Tab within an open overlay so keyboard users can't wander behind it.
    function trapFocus(container, event) {
        var focusable = container.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if (!focusable.length) return;

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function lockScroll(locked) {
        document.body.style.overflow = locked ? 'hidden' : '';
    }

    /* --------------------------------------------------------------- drawer */

    function initDrawer() {
        var drawer = document.querySelector('.dfms-drawer');
        var backdrop = document.querySelector('.dfms-drawer-backdrop');
        var openButtons = document.querySelectorAll('[data-drawer-open]');
        var closeButtons = document.querySelectorAll('[data-drawer-close]');

        if (!drawer || !backdrop) return;

        function open() {
            focusReturn = document.activeElement;
            drawer.classList.add('open');
            backdrop.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
            openButtons.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
            lockScroll(true);

            var firstLink = drawer.querySelector('a, button');
            if (firstLink) firstLink.focus();
        }

        function close() {
            drawer.classList.remove('open');
            backdrop.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
            openButtons.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
            lockScroll(false);
            if (focusReturn) { focusReturn.focus(); focusReturn = null; }
        }

        openButtons.forEach(function (b) { b.addEventListener('click', open); });
        closeButtons.forEach(function (b) { b.addEventListener('click', close); });

        document.addEventListener('keydown', function (event) {
            if (!drawer.classList.contains('open')) return;
            if (event.key === 'Escape') close();
            if (event.key === 'Tab') trapFocus(drawer, event);
        });

        // A resize past the mobile breakpoint should not leave the drawer stuck open.
        window.addEventListener('resize', function () {
            if (window.innerWidth > 1023 && drawer.classList.contains('open')) close();
        });
    }

    /* ------------------------------------------------------------ dropdowns */

    function initDropdowns() {
        var triggers = document.querySelectorAll('[data-dropdown-toggle]');

        function closeAll(except) {
            document.querySelectorAll('.dfms-dropdown.open').forEach(function (menu) {
                if (menu === except) return;
                menu.classList.remove('open');
                var owner = document.querySelector('[data-dropdown-toggle="' + menu.id + '"]');
                if (owner) owner.setAttribute('aria-expanded', 'false');
            });
        }

        triggers.forEach(function (trigger) {
            var menu = document.getElementById(trigger.getAttribute('data-dropdown-toggle'));
            if (!menu) return;

            trigger.addEventListener('click', function (event) {
                event.stopPropagation();
                var isOpen = menu.classList.contains('open');
                closeAll(menu);
                menu.classList.toggle('open', !isOpen);
                trigger.setAttribute('aria-expanded', String(!isOpen));
            });
        });

        document.addEventListener('click', function () { closeAll(null); });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAll(null);
        });
    }

    /* ------------------------------------------------------- command palette */

    function initPalette() {
        var backdrop = document.querySelector('[data-palette-backdrop]');
        if (!backdrop) return;

        var input = backdrop.querySelector('[data-palette-input]');
        var results = backdrop.querySelector('[data-palette-results]');
        var empty = backdrop.querySelector('[data-palette-empty]');
        var items = Array.prototype.slice.call(backdrop.querySelectorAll('[data-palette-item]'));
        var activeIndex = 0;

        function visibleItems() {
            return items.filter(function (item) { return !item.hidden; });
        }

        function setActive(index) {
            var shown = visibleItems();
            if (!shown.length) return;
            activeIndex = Math.max(0, Math.min(index, shown.length - 1));
            shown.forEach(function (item, i) {
                item.classList.toggle('is-active', i === activeIndex);
            });
            shown[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        function filter(query) {
            var needle = query.trim().toLowerCase();
            items.forEach(function (item) {
                var label = (item.getAttribute('data-label') || '').toLowerCase();
                item.hidden = needle !== '' && label.indexOf(needle) === -1;
            });

            // Group headings are noise once a query narrows the list.
            results.querySelectorAll('.dfms-palette-group').forEach(function (group) {
                group.hidden = needle !== '';
            });

            if (empty) empty.hidden = visibleItems().length > 0;
            setActive(0);
        }

        function open() {
            focusReturn = document.activeElement;
            backdrop.classList.add('open');
            lockScroll(true);
            if (input) { input.value = ''; filter(''); input.focus(); }
        }

        function close() {
            backdrop.classList.remove('open');
            lockScroll(false);
            if (focusReturn) { focusReturn.focus(); focusReturn = null; }
        }

        document.querySelectorAll('[data-palette-open]').forEach(function (button) {
            button.addEventListener('click', open);
        });

        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) close();
        });

        if (input) {
            input.addEventListener('input', function () { filter(input.value); });
        }

        document.addEventListener('keydown', function (event) {
            var isOpen = backdrop.classList.contains('open');

            // "/" opens search, but not while the user is typing in a field.
            if (!isOpen && event.key === '/') {
                var tag = (event.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable) return;
                event.preventDefault();
                open();
                return;
            }

            if (!isOpen) return;

            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(activeIndex - 1);
            } else if (event.key === 'Enter') {
                var shown = visibleItems();
                if (shown[activeIndex]) {
                    event.preventDefault();
                    window.location.href = shown[activeIndex].getAttribute('href');
                }
            } else if (event.key === 'Tab') {
                trapFocus(backdrop, event);
            }
        });
    }

    /* --------------------------------------------------------------- modals */

    function initModals() {
        function openModal(modal) {
            focusReturn = document.activeElement;
            modal.classList.add('open');
            lockScroll(true);
            var focusable = modal.querySelector('a[href], button:not([disabled]), input:not([disabled]), select, textarea');
            if (focusable) focusable.focus();
        }

        function closeModal(modal) {
            modal.classList.remove('open');
            lockScroll(false);
            if (focusReturn) { focusReturn.focus(); focusReturn = null; }
        }

        document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var modal = document.getElementById(trigger.getAttribute('data-modal-open'));
                if (modal) openModal(modal);
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var modal = trigger.closest('.dfms-modal-backdrop');
                if (modal) closeModal(modal);
            });
        });

        document.querySelectorAll('.dfms-modal-backdrop').forEach(function (backdrop) {
            backdrop.addEventListener('click', function (event) {
                if (event.target === backdrop) closeModal(backdrop);
            });
        });

        document.addEventListener('keydown', function (event) {
            var open = document.querySelector('.dfms-modal-backdrop.open');
            if (!open) return;
            if (event.key === 'Escape') closeModal(open);
            if (event.key === 'Tab') trapFocus(open, event);
        });
    }

    /* --------------------------------------------------------------- toasts */

    // Exposed for later versions to report the outcome of an action.
    window.dfmsToast = function (message, tone) {
        var region = document.querySelector('[data-toast-region]');
        if (!region) return;

        var toast = document.createElement('div');
        toast.className = 'dfms-toast' + (tone ? ' ' + tone : '');
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        region.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 200ms ease';
            setTimeout(function () { toast.remove(); }, 220);
        }, 4000);
    };

    /* ------------------------------------------------- submit-state feedback */

    // Prevents the double-submit that follows an impatient second click, and
    // gives the button an honest pending state.
    function initFormFeedback() {
        document.querySelectorAll('form[data-loading]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                if (!button || button.classList.contains('is-loading')) return;
                button.classList.add('is-loading');
                button.insertAdjacentHTML('afterbegin', '<span class="dfms-spinner"></span> ');
            });
        });
    }

    onReady(function () {
        initDrawer();
        initDropdowns();
        initPalette();
        initModals();
        initFormFeedback();
    });
})();
