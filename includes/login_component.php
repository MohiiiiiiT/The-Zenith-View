<?php
// =============================================================================
// THE ZENITH VIEW — /includes/login_component.php
// Reusable Login Card HTML Component.
//
// USAGE: require_once __DIR__ . '/login_component.php';
// Called from login.php after the minimal header is rendered.
//
// EXPECTS:
//   $login_error   (string|null) — Error message to display, or null.
//   $login_success (string|null) — Success message (e.g. after password reset request).
//   BASE_URL       (constant)    — Defined in config.php.
// =============================================================================

$login_error   = $login_error   ?? null;
$login_success = $login_success ?? null;
?>

<!-- ===========================================================================
     LOGIN VIEWPORT — flex centred area between header and page bottom
=========================================================================== -->
<main class="login-viewport" id="main-content" aria-label="Login">

    <div class="login-card" role="main">

        <!-- Card Header -->
        <div class="login-card-header">
            <h1 class="login-card-title">Welcome Back</h1>
            <p class="login-card-subtitle">Sign in to your Zenith View account</p>
        </div>

        <!-- ===================================================================
             ROLE TOGGLE — Pill Selector (CSS radio-driven, JS-enhanced labels)
        =================================================================== -->
        <div class="role-toggle-wrap" role="group" aria-label="Select your role">
            <!-- Radio inputs must come BEFORE their labels in the DOM so
                 the CSS sibling selector (~) works without JavaScript. -->
            <input type="radio" name="role" id="role-student" value="student" checked>
            <input type="radio" name="role" id="role-faculty" value="faculty">

            <label class="role-toggle-label" for="role-student">
                <i class="bi bi-mortarboard-fill" aria-hidden="true"></i>
                Student
            </label>
            <label class="role-toggle-label" for="role-faculty">
                <i class="bi bi-person-badge-fill" aria-hidden="true"></i>
                Faculty
            </label>
        </div>

        <!-- ===================================================================
             FLASH MESSAGES
        =================================================================== -->
        <?php if ($login_error): ?>
        <div class="login-alert error" role="alert" aria-live="assertive">
            <i class="bi bi-exclamation-circle-fill" aria-hidden="true" style="flex-shrink:0;margin-top:1px;"></i>
            <span><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <?php if ($login_success): ?>
        <div class="login-alert success" role="status" aria-live="polite">
            <i class="bi bi-check-circle-fill" aria-hidden="true" style="flex-shrink:0;margin-top:1px;"></i>
            <span><?= htmlspecialchars($login_success, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <!-- ===================================================================
             LOGIN FORM
             Single unified form — hidden field carries the selected role.
             JS updates labels dynamically on toggle without page reload.
        =================================================================== -->
        <form class="login-form"
              id="loginForm"
              method="POST"
              action="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/login.php"
              novalidate>

            <!-- CSRF token — login.php must verify this -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <!-- Role carried by hidden field; synced by JS from radio state -->
            <input type="hidden" name="role" id="hiddenRole" value="student">

            <!-- --- ID Field (label changes dynamically via JS) ----------- -->
            <div class="form-group">
                <label class="form-label" for="user_id" id="idLabel">SVV Student ID</label>
                <div class="input-wrap">
                    <i class="bi bi-person-fill input-icon" aria-hidden="true"></i>
                    <input
                        type="text"
                        class="form-input"
                        id="user_id"
                        name="user_id"
                        placeholder="e.g. 2022BECOMPS001"
                        autocomplete="username"
                        autocapitalize="off"
                        autocorrect="off"
                        spellcheck="false"
                        required
                        aria-required="true"
                        aria-describedby="idLabel"
                    >
                </div>
            </div>

            <!-- --- Password Field --------------------------------------- -->
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrap input-wrap-password">
                    <i class="bi bi-lock-fill input-icon" aria-hidden="true"></i>
                    <input
                        type="password"
                        class="form-input"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                    >
                    <!-- Show / Hide password toggle -->
                    <button type="button"
                            class="input-icon-right"
                            id="togglePassword"
                            aria-label="Toggle password visibility"
                            title="Show / hide password">
                        <i class="bi bi-eye-fill" id="togglePasswordIcon" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <!-- --- Remember Me + Forgot Password ----------------------- -->
            <div class="form-row-meta">
                <label class="checkbox-wrap">
                    <input type="checkbox" name="remember_me" id="rememberMe" value="1">
                    <span class="checkbox-label">Remember me</span>
                </label>
                <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/forgot_password.php"
                   class="forgot-link">
                    Forgot Password?
                </a>
            </div>

            <!-- --- Submit Button ---------------------------------------- -->
            <button type="submit" class="btn-login" id="loginSubmitBtn">
                <span class="btn-label">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                    Sign In
                </span>
                <span class="btn-spinner" role="status" aria-label="Signing in…"></span>
            </button>

        </form><!-- /login-form -->

        <!-- Divider -->
        <div class="card-divider" aria-hidden="true" style="margin-top:1.5rem;">
            <span class="card-divider-text">or</span>
        </div>

        <!-- Back to Home button -->
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/index.php"
           class="btn-back-home"
           style="margin-top:0.75rem;">
            <i class="bi bi-house-fill" aria-hidden="true"></i>
            Back to Home
        </a>

    </div><!-- /login-card -->

    <!-- Legal note below card -->
    <p class="login-legal">
        This portal is restricted to authorised KJSCE members only.<br>
        Unauthorised access attempts are logged and monitored.
    </p>

</main><!-- /login-viewport -->