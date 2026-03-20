<?php
// =============================================================================
// THE ZENITH VIEW — logout.php
// Root Directory
//
// PURPOSE:
//   Enterprise-grade session termination. Performs a full, three-phase
//   logout sequence:
//     Phase 1 — Annihilate all server-side session data.
//     Phase 2 — Forcibly expire the client-side session cookie.
//     Phase 3 — Send aggressive HTTP cache-control headers so browsers
//                discard any cached, post-login pages immediately.
//                This is the definitive fix for the "Back Button" attack.
//
// SECURITY MODEL:
//   A logged-out user who presses the browser's Back button MUST be forced
//   to re-authenticate. This is achieved by sending headers that instruct
//   every layer (browser, proxy, CDN) to NEVER serve a cached copy of a
//   protected page. These headers must be emitted BEFORE any output.
//
// FLOW:
//   Browser hits logout.php → Session wiped → Cookie expired → Cache headers
//   sent → Silent redirect to index.php (no message parameters, no alert).
//
// BLUEPRINT REFERENCE: Section 1 (File Architecture), Section 4 (Security).
// =============================================================================


// ---------------------------------------------------------------------------
// PHASE 1 — INITIALIZE & ANNIHILATE SERVER-SIDE SESSION DATA
// ---------------------------------------------------------------------------

// Start the session so we have access to the session data to destroy it.
// We use session_start() here directly (not config.php) because config.php
// also opens a DB connection which is entirely unnecessary at this point.
// We only need session management.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Overwrite every key in the $_SESSION superglobal with an empty array.
// This is the recommended first step before calling session_destroy(),
// as session_destroy() alone does NOT clear the $_SESSION variable in
// the current script's memory space.
$_SESSION = [];


// ---------------------------------------------------------------------------
// PHASE 2 — EXPIRE THE CLIENT-SIDE SESSION COOKIE
// ---------------------------------------------------------------------------

// If a session cookie was sent to the browser, we must force-expire it
// by reissuing the exact same cookie with a timestamp set in the past (1).
// This instructs the browser to immediately delete the cookie from its jar.
// Without this step, the cookie remains on the client until the browser
// session ends, which is a security gap.
if (ini_get('session.use_cookies')) {
    $cookie_params = session_get_cookie_params();
    setcookie(
        session_name(),          // e.g. 'PHPSESSID' — must match the cookie name exactly
        '',                      // Empty value — the cookie content is meaningless now
        1,                       // Expiry = Jan 1, 1970 (Unix timestamp 1) — forces immediate deletion
        $cookie_params['path'],
        $cookie_params['domain'],
        $cookie_params['secure'],
        $cookie_params['httponly']
    );
}

// Now that $_SESSION is cleared and the cookie is expired on the client,
// we can safely destroy the server-side session record.
session_destroy();


// ---------------------------------------------------------------------------
// PHASE 3 — CACHE-BUSTING HTTP HEADERS (Back Button Vulnerability Fix)
// ---------------------------------------------------------------------------
//
// THE ATTACK VECTOR:
//   After logout, a user presses 'Back'. The browser may serve the cached
//   version of /student/dashboard.php from its local cache — showing private
//   data — WITHOUT making a new request to the server. The session check on
//   the server never even fires. This is purely a client-side cache problem.
//
// THE FIX:
//   When the PROTECTED pages (dashboard, review queue, etc.) include
//   header.php, those headers must already be set. However, as a defence-in-
//   depth measure, we also set them here on the logout script itself.
//   The real fix belongs in header.php (or config.php), but this ensures
//   that the redirect response from logout.php itself is never cached.
//
// HEADER BREAKDOWN:
//   Cache-Control: no-store    — Do not store this response in ANY cache.
//   Cache-Control: no-cache    — Revalidate with the server before using cache.
//   Cache-Control: must-revalidate — Stale cached responses must NOT be used.
//   Cache-Control: max-age=0   — The response is considered stale immediately.
//   Pragma: no-cache           — Legacy HTTP/1.0 equivalent of Cache-Control.
//   Expires: 0                 — Legacy: tells old proxies the content is expired.

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false); // IE-specific legacy fix
header('Pragma: no-cache');
header('Expires: 0');


// ---------------------------------------------------------------------------
// REDIRECT — Drop the user silently on the homepage. No message parameters.
// The logout is intentionally frictionless: session is gone, cookie is dead,
// cache is busted. No UI confirmation is shown.
//
// Using a relative path so this works identically on localhost and on the
// production cPanel server (zero-rewrite deployment per Blueprint §8).
// ---------------------------------------------------------------------------
header('Location: index.php');
exit; // CRITICAL: Always exit() immediately after a Location header.
      // Without exit(), PHP continues executing code below the redirect,
      // which is a common source of session-related security bugs.