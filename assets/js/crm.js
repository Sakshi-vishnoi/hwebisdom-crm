document.addEventListener('DOMContentLoaded', function () {
    const menuBtn = document.getElementById('menuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');

    /* =====================================================
       MOBILE NAVIGATION
       ===================================================== */

    function setMenu(open) {
        if (!sidebar) return;

        sidebar.classList.toggle('open', open);

        if (overlay) {
            overlay.classList.toggle('show', open);
            overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuBtn.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        }

        document.body.classList.toggle('menu-open', open);
    }

    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', function () {
            setMenu(!sidebar.classList.contains('open'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            setMenu(false);
        });
    }

    document.querySelectorAll('.nav-item, .logout-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 1000) {
                setMenu(false);
            }
        });
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 1000) {
            setMenu(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setMenu(false);
        }
    });


    /* =====================================================
       CONFIRM ACTIONS
       ===================================================== */

    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (event) {
            if (!confirm(el.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });


    /* =====================================================
       LEAD BULK SELECTION
       ===================================================== */

    const selectAll = document.getElementById('selectAll');
    const boxes = document.querySelectorAll('.lead-checkbox');
    const deleteBtn = document.getElementById('deleteBtn');

    function updateDeleteButton() {
        if (!deleteBtn) return;

        const checked = document.querySelectorAll('.lead-checkbox:checked').length;
        deleteBtn.disabled = checked === 0;

        if (selectAll && boxes.length) {
            selectAll.checked = checked === boxes.length;
            selectAll.indeterminate = checked > 0 && checked < boxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            boxes.forEach(function (box) {
                box.checked = selectAll.checked;
            });

            updateDeleteButton();
        });
    }

    boxes.forEach(function (box) {
        box.addEventListener('change', updateDeleteButton);
    });

    updateDeleteButton();


    /* =====================================================
       CRM PAGE SCROLL RESTORATION

       Each CRM page remembers its OWN scroll position.
       This prevents a user from losing their place when
       navigating back to a long Leads / Pipeline / Meetings
       page, while a first visit still opens at the top.
       ===================================================== */

    const SCROLL_PREFIX = 'webisdom_crm_scroll:';
    const MAX_SCROLL_ENTRIES = 20;

    function getPageKey(url) {
        try {
            const parsed = new URL(url, window.location.href);
            parsed.hash = '';
            return SCROLL_PREFIX + parsed.origin + parsed.pathname + parsed.search;
        } catch (error) {
            return SCROLL_PREFIX + window.location.pathname + window.location.search;
        }
    }

    function saveCurrentScroll() {
        try {
            const key = getPageKey(window.location.href);
            const data = {
                x: window.scrollX || 0,
                y: window.scrollY || 0,
                savedAt: Date.now()
            };

            sessionStorage.setItem(key, JSON.stringify(data));

            // Keep storage tidy so the CRM does not accumulate stale positions.
            const entries = [];

            for (let i = 0; i < sessionStorage.length; i++) {
                const storageKey = sessionStorage.key(i);

                if (!storageKey || !storageKey.startsWith(SCROLL_PREFIX)) {
                    continue;
                }

                try {
                    const value = JSON.parse(sessionStorage.getItem(storageKey));
                    entries.push({
                        key: storageKey,
                        savedAt: Number(value && value.savedAt) || 0
                    });
                } catch (error) {
                    sessionStorage.removeItem(storageKey);
                }
            }

            entries.sort(function (a, b) {
                return b.savedAt - a.savedAt;
            });

            entries.slice(MAX_SCROLL_ENTRIES).forEach(function (entry) {
                sessionStorage.removeItem(entry.key);
            });

        } catch (error) {
            // Storage can be unavailable in private/restricted browser modes.
        }
    }

    function restoreCurrentScroll() {
        try {
            const key = getPageKey(window.location.href);
            const raw = sessionStorage.getItem(key);

            if (!raw) {
                return;
            }

            const data = JSON.parse(raw);

            if (!data || typeof data.y !== 'number') {
                sessionStorage.removeItem(key);
                return;
            }

            // Wait until the page has rendered enough content to restore safely.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    window.scrollTo({
                        left: Number(data.x) || 0,
                        top: Number(data.y) || 0,
                        behavior: 'auto'
                    });
                });
            });

        } catch (error) {
            // Ignore restoration errors.
        }
    }

    // Preserve the browser's normal Back/Forward behavior.
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }

    // Save whenever the user leaves the current document.
    window.addEventListener('beforeunload', saveCurrentScroll);

    // Also save when clicking a normal internal link.
    document.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (
                event.defaultPrevented ||
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey ||
                link.target === '_blank' ||
                link.hasAttribute('download')
            ) {
                return;
            }

            const href = link.getAttribute('href');

            if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
                return;
            }

            try {
                const destination = new URL(href, window.location.href);

                if (destination.origin === window.location.origin) {
                    saveCurrentScroll();
                }
            } catch (error) {
                // Ignore malformed/non-standard links.
            }
        });
    });

    restoreCurrentScroll();
});
