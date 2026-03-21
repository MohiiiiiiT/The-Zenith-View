<?php
// =============================================================================
// THE ZENITH VIEW — login.php
// Standalone Authentication Portal | Zenith Design System v3.0
//
// SECURITY CHECKLIST:
//   [x] CSRF token per-session, validated on POST with hash_equals()
//   [x] All DB queries use PDO prepared statements
//   [x] Passwords verified with password_verify() against bcrypt hash
//   [x] Session regenerated after successful login (prevents session fixation)
//   [x] Role sourced from DB only — never trusted from POST data
//   [x] Generic error message — never reveals which field was wrong
//   [x] 'Remember Me' sets 30-day persistent httpOnly cookie
//   [x] All user output wrapped in htmlspecialchars()
//   [x] Already-authenticated users redirected immediately
// =============================================================================

require_once __DIR__ . '/includes/config.php';

// ─── Redirect if already logged in ──────────────────────────────────────────
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $dest_map = [
        'student' => BASE_URL . '/index.php',
        'teacher' => BASE_URL . '/index.php',
        'admin'   => BASE_URL . '/index.php',
    ];
    header('Location: ' . ($dest_map[$_SESSION['role']] ?? BASE_URL . '/index.php'));
    exit;
}

// ─── CSRF Token ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── Output variables ───────────────────────────────────────────────────────
$login_error   = null;
$login_success = null;

// ─── LEFT PANEL STATS — live queries ────────────────────────────────────────
$stat_students = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(id) AS total
        FROM   users
        WHERE  role = 'student'
          AND  is_active = 1
    ");
    $stmt->execute();
    $stat_students = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Zenith View] Login stats query failed: ' . $e->getMessage());
    $stat_students = 0;
}

// Stat 2: Achievements — table not yet built; hidden in HTML below.
// $stat_achievements = 0; // Uncomment when achievements table exists.

// Stat 3: Departments — hardcoded to match DB ENUM ('Comps','IT','Mech','EXTC','Other').
$stat_departments = 5;

// ─── POST Handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF check
    $submitted_token = trim($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $login_error = 'Your session has expired. Please refresh the page and try again.';
        error_log('[Zenith View] CSRF mismatch from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    // 2. Input validation
    if (!$login_error) {
        $raw_email    = trim($_POST['user_id'] ?? '');
        $raw_password = $_POST['password']     ?? '';
        $remember_me  = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

        if ($raw_email === '' || $raw_password === '') {
            $login_error = 'Please enter your email and password to continue.';
        }
    }

    // 3. Database lookup
    $user = null;
    if (!$login_error) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, name, email, password_hash, role, department, is_active
                FROM   users
                WHERE  email = :email
                LIMIT  1
            ");
            $stmt->execute([':email' => $raw_email]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('[Zenith View] Login DB error: ' . $e->getMessage());
            $login_error = 'A server error occurred. Please try again shortly.';
        }
    }

    // 4. Credential verification (constant-time to prevent enumeration)
    if (!$login_error) {
        $dummy_hash  = '$2y$12$invalidHashUsedToPreventTimingAttackXXXXXXXXXXXXXXXXXXXXXXXX';
        $stored_hash = $user['password_hash'] ?? $dummy_hash;

        if (!$user || !password_verify($raw_password, $stored_hash)) {
            $login_error = 'Invalid credentials. Please check your email and password.';
        } elseif ((int)$user['is_active'] !== 1) {
            $login_error = 'Your account has been deactivated. Please contact the helpdesk.';
        }
    }

    // 5. Login success
    if (!$login_error) {
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['department'] = $user['department']; // Fix: required by review_queue.php dept filter

        if ($remember_me) {
            $cp = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + (30 * 24 * 60 * 60),
                'path'     => $cp['path'],
                'domain'   => $cp['domain'],
                'secure'   => $cp['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // $dest_map = [
        //     'student' => BASE_URL . '/student/dashboard.php',
        //     'teacher' => BASE_URL . '/teacher/review_queue.php',
        //     'admin'   => BASE_URL . '/admin/settings.php',
        // ];
                $dest_map = [
            'student' => BASE_URL . '/index.php',
            'teacher' => BASE_URL . '/index.php',
            'admin'   => BASE_URL . '/index.php',
        ];
        header('Location: ' . ($dest_map[$user['role']] ?? BASE_URL . '/index.php'));
        exit;
    }

} // end POST handler

$base  = rtrim(htmlspecialchars(BASE_URL,    ENT_QUOTES, 'UTF-8'), '/');
$aname = htmlspecialchars(APP_NAME,    ENT_QUOTES, 'UTF-8');
$acoll = htmlspecialchars(APP_COLLEGE, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Sign in to <?= $aname ?> — <?= $acoll ?> Achievement Portal.">
    <title>Sign In &mdash; <?= $aname ?></title>

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- style.css first — defines all CSS custom properties used by login_style.css -->
    <link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/login_style.css">

    <!-- Theme flash prevention: synchronous, inline, before first paint -->
    <!-- Uses 'zv-theme' key (dash) to match the site-wide convention -->
    <script>
        (function(){
            var t = localStorage.getItem('zv-theme');
            if (t === 'dark') document.documentElement.setAttribute('data-theme','dark');
        }());
    </script>
</head>
<body>

<!-- ─── Theme Toggle — fixed top-right, visible on all breakpoints ─────────── -->
<button id="themeBtn" class="login-theme-btn" aria-label="Switch to Dark Mode">
    <i class="bi bi-moon-stars-fill" id="themeIcon" aria-hidden="true"></i>
</button>

<!-- ─── SPLIT LAYOUT ─────────────────────────────────────────────────────── -->
<div class="login-split">

    <!-- =====================================================================
         LEFT PANEL — Architectural Brand Canvas (hidden on mobile)
         aria-hidden: purely decorative / visual context, not read by AT
    ===================================================================== -->
    <div class="login-left" aria-hidden="true">

        <!-- Geometric grid overlay -->
        <div class="left-grid" aria-hidden="true"></div>

        <!-- Decorative geometric shapes are rendered via CSS ::before / ::after
             and .left-shape-* elements below for maximum control. -->
        <div class="left-shape-a" aria-hidden="true"></div>
        <div class="left-shape-b" aria-hidden="true"></div>
        <div class="left-shape-c" aria-hidden="true"></div>

        <!-- Brand mark — top of panel -->
        <div class="left-content-top">
            <a href="<?= $base ?>/index.php" class="left-brand" tabindex="-1">
                <div class="brand-logo-mark">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div class="left-brand-text">
                    <span class="left-brand-name"><?= $aname ?></span>
                    <span class="left-brand-college"><?= $acoll ?></span>
                </div>
            </a>
        </div>

        <!-- Hero copy — centred in the panel -->
        <div class="left-hero">
            <div class="left-eyebrow">
                <span class="eyebrow-dash" aria-hidden="true"></span>
                <span class="eyebrow-text">Academic Achievement Platform</span>
            </div>
            <h2 class="left-headline">
                Merit<br>
                <em>made</em><br>
                <span class="hl-red">visible.</span>
            </h2>
            <p class="left-sub">
                KJSCE's centralised leaderboard — from hackathon wins and published
                research to sports victories and leadership.
            </p>
        </div>

        <!-- Stats strip — bottom of panel -->
        <div class="left-stats">
            <div class="left-stat">
                <span class="stat-number"><?= number_format($stat_students) ?>+</span>
                <span class="stat-label">Students</span>
            </div>
            <?php /* ── Achievements stat hidden until table is built ──────────
            <div class="left-stat">
                <span class="stat-number"><?= number_format($stat_achievements ?? 0) ?></span>
                <span class="stat-label">Achievements</span>
            </div>
            ── Uncomment the block above once the achievements table exists. ── */ ?>
            <div class="left-stat">
                <span class="stat-number"><?= $stat_departments ?></span>
                <span class="stat-label">Departments</span>
            </div>
            <div class="left-stat">
                <span class="stat-number">1</span>
                <span class="stat-label">Institution</span>
            </div>
        </div>

    </div><!-- /login-left -->


    <!-- =====================================================================
         RIGHT PANEL — Form Stage
         On mobile this is the ONLY visible panel (100vw / 100dvh).
    ===================================================================== -->
    <div class="login-right">

        <!-- Ambient radial glow behind the card -->
        <div class="right-glow" aria-hidden="true"></div>

        <div class="login-right-inner">

            <!-- =============================================================
                 MOBILE BRAND HEADER
                 Displayed ONLY on mobile (≤768px) via CSS.
                 Provides logo + title above the card since the left panel
                 is completely hidden on small screens.
                 No "navbar" or floating buttons — just a clean brand block.
            ============================================================= -->
            <div class="mobile-brand-header" aria-label="<?= $aname ?>">
                <a href="<?= $base ?>/index.php" class="mobile-brand-link">
                    <div class="brand-logo-mark mobile-logo-mark" aria-hidden="true">
                        <i class="bi bi-trophy-fill"></i>
                    </div>
                    <div class="mobile-brand-text">
                        <span class="mobile-brand-name"><?= $aname ?></span>
                        <span class="mobile-brand-college"><?= $acoll ?></span>
                    </div>
                </a>
            </div>

            <!-- =============================================================
                 GLASS LOGIN CARD
            ============================================================= -->
            <div class="login-card">

                <!-- Card header -->
                <div class="card-head">
                    <span class="card-greeting">Welcome back</span>
                    <h1 class="card-title">Sign in</h1>
                    <p class="card-sub">Enter your KJSCE institutional email and password to continue.</p>
                </div>

                <!-- Flash alerts -->
                <?php if ($login_error): ?>
                <div class="login-alert error" role="alert" aria-live="assertive">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>

                <?php if ($login_success): ?>
                <div class="login-alert success" role="status" aria-live="polite">
                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($login_success, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>

                <!-- Login form -->
                <form id="loginForm"
                      method="POST"
                      action="<?= $base ?>/login.php"
                      novalidate>

                    <!-- CSRF -->
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                    <!-- Email field — floating label pattern -->
                    <div class="field-group">
                        <input
                            type="email"
                            class="field-input"
                            id="user_id"
                            name="user_id"
                            placeholder=" "
                            autocomplete="email"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            aria-required="true"
                            aria-label="Email address"
                            value="<?= htmlspecialchars($_POST['user_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <label class="field-label" for="user_id" id="emailLabel">
                            KJSCE Email Address
                        </label>
                    </div>

                    <!-- Password field — floating label + eye toggle -->
                    <div class="field-group">
                        <input
                            type="password"
                            class="field-input has-icon-right"
                            id="password"
                            name="password"
                            placeholder=" "
                            autocomplete="current-password"
                            required
                            aria-required="true"
                            aria-label="Password"
                        >
                        <label class="field-label" for="password">Password</label>
                        <button type="button"
                                class="field-eye"
                                id="eyeBtn"
                                aria-label="Show password"
                                tabindex="0">
                            <i class="bi bi-eye-fill" id="eyeIcon" aria-hidden="true"></i>
                        </button>
                    </div>

                    <!-- Meta row: Remember Me + Forgot Password -->
                    <div class="form-meta">
                        <label class="custom-check">
                            <input type="checkbox" name="remember_me" value="1" id="rememberMe">
                            <span class="check-label">Remember me</span>
                        </label>
                        <a href="<?= $base ?>/forgot_password.php" class="forgot-link">
                            Forgot password?
                        </a>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-submit" id="submitBtn">
                        <span class="btn-text">
                            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                            Sign In
                        </span>
                        <span class="btn-spinner" role="status" aria-label="Signing in"></span>
                    </button>

                </form><!-- /loginForm -->

                <!-- Card footer -->
                <div class="card-footer-links">
                    <a href="<?= $base ?>/index.php" class="back-home-link">
                        <i class="bi bi-house-fill" aria-hidden="true"></i>
                        Back to Home
                    </a>
                    <span class="footer-divider" aria-hidden="true"></span>
                    <span class="legal-note">KJSCE members only</span>
                </div>

            </div><!-- /login-card -->

        </div><!-- /login-right-inner -->
    </div><!-- /login-right -->

</div><!-- /login-split -->


<!-- ==========================================================================
     INLINE JAVASCRIPT
     No dependency on Bootstrap JS or main.js.
     localStorage key: 'zv-theme' (dash, NOT underscore).
     Sections:
       1. Theme Manager
       2. Password Show / Hide
       3. Submit Spinner
       4. Auto-fade error alert
========================================================================== -->
<script>
(function () {
    'use strict';

    /* ── 1. Theme Manager ─────────────────────────────────────────────────── */
    var THEME_KEY = 'zv-theme'; /* MUST use dash: 'zv-theme' */
    var themeBtn  = document.getElementById('themeBtn');
    var themeIcon = document.getElementById('themeIcon');

    function applyTheme(t) {
        document.documentElement.setAttribute('data-theme', t);
        document.body.setAttribute('data-theme', t);
        localStorage.setItem(THEME_KEY, t);
        if (themeIcon) {
            themeIcon.className = t === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }
        if (themeBtn) {
            themeBtn.setAttribute('aria-label',
                t === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
        }
    }

    /* Sync with the flash-prevention script's initial attribute */
    applyTheme(localStorage.getItem(THEME_KEY) || 'light');

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            applyTheme(
                document.documentElement.getAttribute('data-theme') === 'dark'
                    ? 'light' : 'dark'
            );
        });
    }


    /* ── 2. Password Show / Hide ──────────────────────────────────────────── */
    var eyeBtn     = document.getElementById('eyeBtn');
    var eyeIcon    = document.getElementById('eyeIcon');
    var pwInput    = document.getElementById('password');
    var emailInput = document.getElementById('user_id'); /* Used by submit validator below */

    if (eyeBtn && pwInput) {
        eyeBtn.addEventListener('click', function () {
            var isHidden = pwInput.type === 'password';
            pwInput.type = isHidden ? 'text' : 'password';
            if (eyeIcon) {
                eyeIcon.className = isHidden ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill';
            }
            eyeBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }


    /* ── 3. Submit Spinner (prevent double-submit + show loading) ─────────── */
    var loginForm = document.getElementById('loginForm');
    var submitBtn = document.getElementById('submitBtn');

    if (loginForm && submitBtn) {
        loginForm.addEventListener('submit', function (e) {
            var email = emailInput ? emailInput.value.trim() : '';
            var pw    = pwInput    ? pwInput.value           : '';

            if (!email || !pw) {
                e.preventDefault();
                if (!email && emailInput) { emailInput.focus(); }
                else if (!pw && pwInput)  { pwInput.focus(); }
                return;
            }

            submitBtn.classList.add('loading');
            submitBtn.setAttribute('aria-disabled', 'true');
        });
    }


    /* ── 4. Auto-fade error alert on first keystroke ─────────────────────── */
    var alertEl = document.querySelector('.login-alert.error');
    if (alertEl) {
        var inputs = document.querySelectorAll('.field-input');
        inputs.forEach(function (inp) {
            inp.addEventListener('input', function () {
                alertEl.style.transition = 'opacity 0.35s ease';
                alertEl.style.opacity    = '0';
                setTimeout(function () {
                    if (alertEl && alertEl.parentNode) {
                        alertEl.parentNode.removeChild(alertEl);
                    }
                }, 380);
            }, { once: true });
        });
    }

}());
</script>

</body>
</html>