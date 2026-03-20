// =============================================================================
// THE ZENITH VIEW — /assets/js/main.js
// Global JavaScript Modules
//
//  1. Theme Manager      — Light/Dark toggle with localStorage persistence
//  2. Mobile Drawer      — Slide-in nav panel + hamburger animation
//  3. Navbar Scroll State — Adds .scrolled class for enhanced glass effect
//  4. Announcement Bar   — Dismiss / collapse behaviour
//  5. Ticker Calibration — Proportional animation-duration for ticker
//  6. Back To Top Button — Show/hide on scroll + smooth scroll action
//  7. Alert Auto-Dismiss — Utility for Bootstrap alerts with data-auto-dismiss
// =============================================================================


// =============================================================================
// 1. THEME MANAGER
// =============================================================================
(function ThemeManager() {
    'use strict';

    var STORAGE_KEY = 'zv-theme';
    var ICON_MOON   = 'bi-moon-stars-fill';
    var ICON_SUN    = 'bi-sun-fill';

    /**
     * Apply a theme to <html> and <body>, persist to localStorage, update icons.
     * @param {string} theme - 'light' | 'dark'
     */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.body.setAttribute('data-theme', theme);
        localStorage.setItem(STORAGE_KEY, theme);

        var isDark   = theme === 'dark';
        var icon     = isDark ? ICON_SUN  : ICON_MOON;
        var label    = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';

        document.querySelectorAll('#theme-icon, #theme-icon-mobile').forEach(function (el) {
            el.className = 'bi ' + icon;
        });
        document.querySelectorAll('#theme-toggle, #theme-toggle-mobile').forEach(function (btn) {
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        });
    }

    function toggle() {
        var current = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(current === 'light' ? 'dark' : 'light');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Sync icons with whatever theme flash-prevention script already applied
        var saved = localStorage.getItem(STORAGE_KEY) || 'light';
        applyTheme(saved);

        var btnDesktop = document.getElementById('theme-toggle');
        var btnMobile  = document.getElementById('theme-toggle-mobile');
        if (btnDesktop) btnDesktop.addEventListener('click', toggle);
        if (btnMobile)  btnMobile.addEventListener('click',  toggle);
    });
}());


// =============================================================================
// 2. MOBILE DRAWER
// Handles: hamburger animation, drawer open/close, overlay click to close,
//          Escape key to close, focus trap for accessibility.
// =============================================================================
(function MobileDrawer() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var hamburger   = document.getElementById('hamburgerBtn');
        var drawer      = document.getElementById('mobileDrawer');
        var overlay     = document.getElementById('drawerOverlay');
        var closeBtn    = document.getElementById('drawerClose');

        if (!hamburger || !drawer || !overlay) return;

        var isOpen = false;

        function openDrawer() {
            isOpen = true;
            drawer.classList.add('is-open');
            overlay.classList.add('is-visible');
            hamburger.classList.add('is-open');
            hamburger.setAttribute('aria-expanded', 'true');
            overlay.setAttribute('aria-hidden', 'false');
            drawer.setAttribute('aria-hidden', 'false');
            // FIX 4: Remove inert so drawer contents become interactive
            drawer.removeAttribute('inert');
            document.body.style.overflow = 'hidden'; // Prevent background scroll
            // Move focus into drawer for accessibility
            var firstLink = drawer.querySelector('.drawer-link, .btn-nav-cta');
            if (firstLink) setTimeout(function () { firstLink.focus(); }, 50);
        }

        function closeDrawer() {
            isOpen = false;
            drawer.classList.remove('is-open');
            overlay.classList.remove('is-visible');
            hamburger.classList.remove('is-open');
            hamburger.setAttribute('aria-expanded', 'false');
            overlay.setAttribute('aria-hidden', 'true');
            drawer.setAttribute('aria-hidden', 'true');
            // FIX 4: inert removes ALL interactivity (focus, click, scroll) from
            // the off-screen drawer, completely eliminating focus/event spill.
            drawer.setAttribute('inert', '');
            document.body.style.overflow = '';
            hamburger.focus(); // Return focus to trigger
        }

        hamburger.addEventListener('click', function () {
            isOpen ? closeDrawer() : openDrawer();
        });

        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        overlay.addEventListener('click', closeDrawer);

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (isOpen && (e.key === 'Escape' || e.keyCode === 27)) {
                closeDrawer();
            }
        });

        // Close drawer when a nav link is tapped (page navigation)
        drawer.querySelectorAll('.drawer-link').forEach(function (link) {
            link.addEventListener('click', closeDrawer);
        });

        // Close drawer if viewport becomes desktop-size (e.g. rotate tablet)
        var mq = window.matchMedia('(min-width: 992px)');
        mq.addEventListener('change', function (e) {
            if (e.matches && isOpen) closeDrawer();
        });
    });
}());


// =============================================================================
// 3. NAVBAR SCROLL STATE
// Adds .scrolled class to the navbar after 20px of scroll for denser glass.
// =============================================================================
(function NavbarScroll() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var navbar    = document.getElementById('mainNavbar');
        var threshold = 20;

        if (!navbar) return;

        function onScroll() {
            if (window.scrollY > threshold) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll(); // Run once on load in case page is already scrolled
    });
}());


// =============================================================================
// 4. ANNOUNCEMENT BAR DISMISS
// Collapses the bar on close button click. Remembers dismiss state in sessionStorage
// so the bar stays hidden for the current session (but returns on new tab/visit).
// =============================================================================
(function AnnouncementBar() {
    'use strict';

    var STORAGE_KEY = 'zv_ann_dismissed';

    document.addEventListener('DOMContentLoaded', function () {
        var bar   = document.querySelector('.announcement-bar');
        var btn   = document.getElementById('announcementClose');

        if (!bar) return;

        // Check if user dismissed this session
        if (sessionStorage.getItem(STORAGE_KEY) === '1') {
            bar.classList.add('ann-hidden');
            return;
        }

        if (btn) {
            btn.addEventListener('click', function () {
                bar.classList.add('ann-hidden');
                sessionStorage.setItem(STORAGE_KEY, '1');
            });
        }
    });
}());


// =============================================================================
// 5. TICKER SPEED CALIBRATION
// Measures actual track width after render and sets animation-duration so
// scroll speed (in px/s) is constant regardless of content length.
// =============================================================================
document.addEventListener('DOMContentLoaded', function () {
    var track = document.getElementById('tickerTrack');
    if (!track) return;

    // Content is duplicated, so actual one-loop width = scrollWidth / 2
    var loopWidth       = track.scrollWidth / 2;
    var pixelsPerSecond = 90;
    var minDuration     = 18;
    var duration        = Math.max(minDuration, loopWidth / pixelsPerSecond);

    track.style.animationDuration = duration.toFixed(1) + 's';
});


// =============================================================================
// 6. BACK TO TOP BUTTON
// Shows after 300px scroll. Smooth-scrolls to top on click.
// =============================================================================
(function BackToTop() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn       = document.getElementById('backToTop');
        var threshold = 300;

        if (!btn) return;

        window.addEventListener('scroll', function () {
            if (window.scrollY > threshold) {
                btn.classList.add('is-visible');
            } else {
                btn.classList.remove('is-visible');
            }
        }, { passive: true });

        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
}());


// =============================================================================
// 7. AUTO-DISMISS BOOTSTRAP ALERTS
// Any .alert with data-auto-dismiss="[ms]" fades out after that delay.
// Defaults to 4000ms if attribute value is not a valid number.
// =============================================================================
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (alertEl) {
        var delay = parseInt(alertEl.getAttribute('data-auto-dismiss'), 10);
        if (isNaN(delay) || delay < 0) delay = 4000;

        setTimeout(function () {
            var bsAlert = typeof bootstrap !== 'undefined'
                ? bootstrap.Alert.getOrCreateInstance(alertEl)
                : null;
            if (bsAlert) {
                bsAlert.close();
            } else {
                alertEl.style.display = 'none';
            }
        }, delay);
    });
});


