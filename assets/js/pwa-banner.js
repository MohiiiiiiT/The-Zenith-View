/**
 * =============================================================================
 * THE ZENITH VIEW — PWA Install Banner Logic
 * /assets/js/pwa-banner.js
 *
 * BUG FIXES IN THIS VERSION:
 *
 *   FIX 1 — DESKTOP GUARD
 *     showBanner() checks window.innerWidth < 768 before doing anything.
 *     Belt-and-suspenders alongside the CSS display:none — ensures the JS
 *     slide animation never runs on desktop even if beforeinstallprompt
 *     fires there.
 *
 *   FIX 2 — CLICK EVENTS NOT FIRING
 *     Old code ran synchronously at parse time. If the script loaded before
 *     the banner HTML was in the DOM, getElementById() returned null and the
 *     early-return guard silently killed everything.
 *     Fix: wrap all logic in a function called after DOMContentLoaded (or
 *     immediately if the DOM is already ready, which is the normal case when
 *     the script tag is at the bottom of <body> without defer).
 *     Also removed 'defer' from the <script> tag in footer.php — the banner
 *     HTML is already above the script tag so sync loading is fine and
 *     removes any async timing risk.
 *
 *   FIX 3 — iOS UNICODE ESCAPE
 *     Replaced \u{1F4F1} (ES2015+ syntax) with the raw emoji character to
 *     avoid syntax errors on older WebKit versions still in use on older iPhones.
 *
 * Flow:
 *   1. If already dismissed (localStorage), exit.
 *   2. Android/Chrome/Edge: intercept beforeinstallprompt → show banner.
 *   3. iOS Safari: detect iOS + not-standalone → show with Share instructions.
 *   4. Close (×): permanent dismiss via localStorage.
 * =============================================================================
 */

(function () {
    'use strict';

    var STORAGE_KEY      = 'pwa_banner_dismissed';
    var SHOW_CLASS       = 'pwa-banner--show';
    var BTN_HIDDEN_CLASS = 'pwa-banner__btn--hidden';

    /* ── Already dismissed? Exit before touching the DOM ─────────────────── */
    try {
        if (localStorage.getItem(STORAGE_KEY) === 'true') { return; }
    } catch (e) { /* private browsing — continue */ }

    /* ── Main setup — runs after DOM is ready ────────────────────────────── */
    function setup() {

        var banner     = document.getElementById('pwaBanner');
        var subtitle   = document.getElementById('pwaBannerSubtitle');
        var installBtn = document.getElementById('pwaBannerInstall');
        var closeBtn   = document.getElementById('pwaBannerClose');

        /* If the HTML is missing for any reason, exit cleanly */
        if (!banner || !subtitle || !installBtn || !closeBtn) { return; }

        /* ── Helpers ──────────────────────────────────────────────────────── */

        function isMobile() {
            return window.innerWidth < 768;
        }

        function showBanner() {
            if (!isMobile()) { return; } /* FIX 1: never show on desktop */
            banner.removeAttribute('aria-hidden');
            banner.classList.add(SHOW_CLASS);
        }

        function hideBanner() {
            banner.classList.remove(SHOW_CLASS);
            banner.setAttribute('aria-hidden', 'true');
        }

        function dismiss() {
            hideBanner();
            try { localStorage.setItem(STORAGE_KEY, 'true'); } catch (e) {}
        }

        /* ── Close button ─────────────────────────────────────────────────── */
        closeBtn.addEventListener('click', function () {
            dismiss();
        });

        /* ── Android / Chrome / Edge ──────────────────────────────────────── */
        var deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            subtitle.textContent = 'Add Zenith View to your home screen';
            showBanner();
        });

        installBtn.addEventListener('click', function () {
            if (!deferredPrompt) { return; }
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (result) {
                if (result.outcome === 'accepted') {
                    dismiss();
                } else {
                    hideBanner(); /* declined native dialog — hide for session */
                }
                deferredPrompt = null;
            });
        });

        window.addEventListener('appinstalled', function () {
            dismiss();
            deferredPrompt = null;
        });

        /* ── iOS Safari ───────────────────────────────────────────────────── */
        var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
        var isStandalone = (
            ('standalone' in navigator && navigator.standalone === true) ||
            window.matchMedia('(display-mode: standalone)').matches
        );

        if (isIOS && !isStandalone && deferredPrompt === null) {
            setTimeout(function () {
                if (!isMobile()) { return; } /* re-check on delay */
                /* FIX 3: raw emoji instead of \u{1F4F1} for older WebKit */
                subtitle.textContent = 'Tap the \uD83D\uDCF1 Share icon, then \u201CAdd to Home Screen\u201D';
                installBtn.classList.add(BTN_HIDDEN_CLASS);
                showBanner();
            }, 2500);
        }
    }

    /* ── Kick off after DOM ready ─────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup(); /* already ready (script is at bottom of body) */
    }

}());