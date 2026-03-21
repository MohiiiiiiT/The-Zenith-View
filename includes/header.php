<?php
/**
 * THE ZENITH VIEW — /includes/header.php
 * -----------------------------------------------------------------------
 * Reusable HTML <head> + Navbar (RBAC-dynamic) + Mobile Drawer
 * Included at the top of every page via require_once.
 *
 * Session must already be started by config.php before this file runs.
 * RBAC logic reads $_SESSION['role'] and $_SESSION['name'] to build the
 * correct nav links and show/hide the user pill + logout button.
 * -----------------------------------------------------------------------
 */

// Determine the current page for .active link highlighting.
// basename() strips the directory path; str_replace removes .php extension.
$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));

// Pull session data — default to empty strings if not logged in.
$session_name  = isset($_SESSION['user_name'])  ? htmlspecialchars($_SESSION['user_name'],  ENT_QUOTES, 'UTF-8') : '';
$session_role  = isset($_SESSION['role'])  ? htmlspecialchars($_SESSION['role'],  ENT_QUOTES, 'UTF-8') : '';
$is_logged_in  = isset($_SESSION['user_id']);

// Build initials for the avatar pill (e.g. "Dr. Amit Patel" → "AP")
$initials = '';
if ($session_name) {
    $words = explode(' ', $session_name);
    // Take first letter of first word and first letter of last word
    $initials .= strtoupper(substr($words[0], 0, 1));
    if (count($words) > 1) {
        $initials .= strtoupper(substr(end($words), 0, 1));
    }
}

// Human-readable role label
$role_labels = [
    'student' => 'Student',
    'teacher' => 'Faculty',
    'admin'   => 'System Administrator',
];
$role_label = $role_labels[$session_role] ?? '';

/**
 * Helper: returns 'active' string if $page matches current page, else ''.
 * Used inline to keep the template clean.
 */
function nav_active(string $page, string $current): string {
    return ($page === $current) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="The Zenith View — KJSCE's official student achievement leaderboard portal.">

    <title>The Zenith View &mdash; KJSCE</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <script>
        (function () {
            try {
                var saved = localStorage.getItem('zv-theme');
                if (saved === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>
</head>

<body>

<div class="announcement-bar" id="announcementBar" role="marquee" aria-label="Announcements">

    <span class="announcement-label">
        <span class="ann-dot"></span>
        Live
    </span>

    <div class="announcement-track-wrapper">
        <div class="announcement-track" id="tickerTrack">
            <span class="ticker-item">
                <i class="bi bi-star-fill ticker-icon"></i>
                Rohan Sharma (BE · Comps) just hit <span class="ticker-badge">1,240 pts</span>
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-trophy-fill ticker-icon"></i>
                Submission window closes <span class="ticker-badge">Dec 20, 2025</span>
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-patch-check-fill ticker-icon"></i>
                Priya Nair's patent submission is <span class="ticker-badge">Under Review</span>
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-bar-chart-fill ticker-icon"></i>
                Leaderboard updated — <span class="ticker-badge">42 new approvals</span> this week
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-star-fill ticker-icon"></i>
                Rohan Sharma (BE · Comps) just hit <span class="ticker-badge">1,240 pts</span>
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-trophy-fill ticker-icon"></i>
                Submission window closes <span class="ticker-badge">Dec 20, 2025</span>
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-patch-check-fill ticker-icon"></i>
                Priya Nair's patent submission is <span class="ticker-badge">Under Review</span>
            </span>
            <span class="ticker-separator">&#9679;</span>
            <span class="ticker-item">
                <i class="bi bi-bar-chart-fill ticker-icon"></i>
                Leaderboard updated — <span class="ticker-badge">42 new approvals</span> this week
            </span>
            <span class="ticker-separator">&#9679;</span>
        </div>
    </div>

    <button class="announcement-close" id="announcementClose" aria-label="Dismiss announcement bar">
        <i class="bi bi-x"></i>
    </button>

</div><header>
<nav class="navbar navbar-glass sticky-top" id="mainNavbar" aria-label="Main navigation">
    <div class="container d-flex align-items-center justify-content-between" style="gap: 1rem;">

        <a href="<?= BASE_URL ?>/index.php" class="navbar-brand" aria-label="The Zenith View — Home">
            <span class="brand-logo-mark">
                <i class="bi bi-bar-chart-fill"></i>
            </span>
            <span>
                <span class="brand-name">The Zenith View</span>
                <span class="brand-subtitle">KJSCE Achievement Portal</span>
            </span>
        </a>

        <ul class="navbar-nav-desktop d-none d-lg-flex flex-row align-items-center gap-1 me-auto ms-4">

            <?php if (!$is_logged_in): ?>
                <li><a href="<?= BASE_URL ?>/index.php"       class="nav-link <?= nav_active('index',       $current_page) ?>">Home</a></li>
                <li><a href="<?= BASE_URL ?>/leaderboard.php" class="nav-link <?= nav_active('leaderboard', $current_page) ?>">Leaderboard</a></li>
                <li><a href="<?= BASE_URL ?>/submit_ticket.php" class="nav-link <?= nav_active('submit_ticket', $current_page) ?>">Support</a></li>

            <?php elseif ($session_role === 'student'): ?>
                <li><a href="<?= BASE_URL ?>/index.php"                   class="nav-link <?= nav_active('index',        $current_page) ?>">Home</a></li>
                <li><a href="<?= BASE_URL ?>/leaderboard.php"             class="nav-link <?= nav_active('leaderboard',  $current_page) ?>">Leaderboard</a></li>
                <li><a href="<?= BASE_URL ?>/student/dashboard.php"       class="nav-link <?= nav_active('dashboard',    $current_page) ?>">Dashboard</a></li>
                <li><a href="<?= BASE_URL ?>/student/upload_proof.php"    class="nav-link <?= nav_active('upload_proof', $current_page) ?>">Upload Proof</a></li>
                <li><a href="<?= BASE_URL ?>/submit_ticket.php"           class="nav-link <?= nav_active('submit_ticket',$current_page) ?>">Support</a></li>

            <?php elseif ($session_role === 'teacher'): ?>
                <li><a href="<?= BASE_URL ?>/index.php"                   class="nav-link <?= nav_active('index',        $current_page) ?>">Home</a></li>
                <li><a href="<?= BASE_URL ?>/leaderboard.php"             class="nav-link <?= nav_active('leaderboard',  $current_page) ?>">Leaderboard</a></li>
                <li><a href="<?= BASE_URL ?>/teacher/review_queue.php"    class="nav-link <?= nav_active('review_queue', $current_page) ?>">Review Queue</a></li>
                <li><a href="<?= BASE_URL ?>/submit_ticket.php"           class="nav-link <?= nav_active('submit_ticket',$current_page) ?>">Support</a></li>

            <?php elseif ($session_role === 'admin'): ?>
                <li><a href="<?= BASE_URL ?>/admin/dashboard.php"        class="nav-link <?= nav_active('dashboard',    $current_page) ?>">Dashboard</a></li>
                <li><a href="<?= BASE_URL ?>/admin/manage_users.php"     class="nav-link <?= nav_active('manage_users', $current_page) ?>">Users</a></li>
                <li><a href="<?= BASE_URL ?>/admin/settings.php"         class="nav-link <?= nav_active('settings',     $current_page) ?>">Settings</a></li>
                <li><a href="<?= BASE_URL ?>/leaderboard.php"            class="nav-link <?= nav_active('leaderboard',  $current_page) ?>">Leaderboard</a></li>
                <li><a href="<?= BASE_URL ?>/submit_ticket.php"          class="nav-link <?= nav_active('submit_ticket',$current_page) ?>">Support</a></li>

            <?php endif; ?>
        </ul>

        <div class="d-none d-lg-flex align-items-center gap-3">

            <button class="theme-btn" id="theme-toggle" aria-label="Toggle dark/light mode" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="theme-icon"></i>
            </button>

            <?php if ($is_logged_in): ?>

                <div class="nav-user-pill" title="Logged in as <?= $session_name ?> — <?= $role_label ?>">
                    <span class="nav-user-avatar" aria-hidden="true"><?= $initials ?: '?' ?></span>
                    <span class="nav-user-info">
                        <span class="nav-user-name"><?= $session_name ?></span>
                        <?php if ($role_label): ?>
                        <span class="nav-user-role"><?= $role_label ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <a href="<?= BASE_URL ?>/logout.php" class="btn-nav-cta btn-nav-outline">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>

            <?php else: ?>

                <a href="<?= BASE_URL ?>/login.php" class="btn-nav-cta btn-nav-outline">Log In</a>
                <a href="<?= BASE_URL ?>/login.php" class="btn-nav-cta">
                    <i class="bi bi-person-plus-fill"></i>
                    Get Started
                </a>

            <?php endif; ?>

        </div>

        <div class="d-flex d-lg-none align-items-center gap-2">
            <button class="theme-btn" id="theme-toggle-mobile" aria-label="Toggle dark/light mode">
                <i class="bi bi-moon-stars-fill" id="theme-icon-mobile"></i>
            </button>
            <button class="hamburger" id="hamburgerBtn" aria-label="Open navigation menu" aria-expanded="false" aria-controls="mobileDrawer">
                <span class="hamburger-line top"></span>
                <span class="hamburger-line mid"></span>
                <span class="hamburger-line bot"></span>
            </button>
        </div>

    </div></nav>
<!-- </header> -->
 <?php if (isset($extra_head)) echo $extra_head; ?>
</head>


<div class="drawer-overlay" id="drawerOverlay" aria-hidden="true"></div>


<div class="mobile-drawer" id="mobileDrawer" role="dialog" aria-modal="true" aria-label="Navigation menu">

    <div class="drawer-header">
        <a href="<?= BASE_URL ?>/index.php" class="navbar-brand drawer-brand" aria-label="Home">
            <span class="brand-logo-mark drawer-brand-mark">
                <i class="bi bi-bar-chart-fill"></i>
            </span>
            <span class="brand-name" style="font-size: 0.9rem;">The Zenith View</span>
        </a>
        <button class="drawer-close" id="drawerClose" aria-label="Close navigation menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="drawer-nav" aria-label="Mobile navigation">
        <ul>
            <?php if (!$is_logged_in): ?>
                <li>
                    <a href="<?= BASE_URL ?>/index.php" class="drawer-link <?= nav_active('index', $current_page) ?>">
                        <i class="bi bi-house-door"></i> Home
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/leaderboard.php" class="drawer-link <?= nav_active('leaderboard', $current_page) ?>">
                        <i class="bi bi-trophy"></i> Leaderboard
                    </a>
                </li>
                <li class="drawer-divider"></li>
                <li>
                    <a href="<?= BASE_URL ?>/submit_ticket.php" class="drawer-link <?= nav_active('submit_ticket', $current_page) ?>">
                        <i class="bi bi-headset"></i> Support
                    </a>
                </li>

            <?php elseif ($session_role === 'student'): ?>
                <li>
                    <a href="<?= BASE_URL ?>/index.php" class="drawer-link <?= nav_active('index', $current_page) ?>">
                        <i class="bi bi-house-door"></i> Home
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/leaderboard.php" class="drawer-link <?= nav_active('leaderboard', $current_page) ?>">
                        <i class="bi bi-trophy"></i> Leaderboard
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/student/dashboard.php" class="drawer-link <?= nav_active('dashboard', $current_page) ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/student/upload_proof.php" class="drawer-link <?= nav_active('upload_proof', $current_page) ?>">
                        <i class="bi bi-cloud-upload"></i> Upload Proof
                    </a>
                </li>
                <li class="drawer-divider"></li>
                <li>
                    <a href="<?= BASE_URL ?>/submit_ticket.php" class="drawer-link <?= nav_active('submit_ticket', $current_page) ?>">
                        <i class="bi bi-headset"></i> Support
                    </a>
                </li>

            <?php elseif ($session_role === 'teacher'): ?>
                <li>
                    <a href="<?= BASE_URL ?>/index.php" class="drawer-link <?= nav_active('index', $current_page) ?>">
                        <i class="bi bi-house-door"></i> Home
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/leaderboard.php" class="drawer-link <?= nav_active('leaderboard', $current_page) ?>">
                        <i class="bi bi-trophy"></i> Leaderboard
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/teacher/review_queue.php" class="drawer-link <?= nav_active('review_queue', $current_page) ?>">
                        <i class="bi bi-clipboard2-check"></i> Review Queue
                    </a>
                </li>
                <li class="drawer-divider"></li>
                <li>
                    <a href="<?= BASE_URL ?>/submit_ticket.php" class="drawer-link <?= nav_active('submit_ticket', $current_page) ?>">
                        <i class="bi bi-headset"></i> Support
                    </a>
                </li>

            <?php elseif ($session_role === 'admin'): ?>
                <li>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="drawer-link <?= nav_active('dashboard', $current_page) ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/admin/manage_users.php" class="drawer-link <?= nav_active('manage_users', $current_page) ?>">
                        <i class="bi bi-people"></i> Users
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/admin/settings.php" class="drawer-link <?= nav_active('settings', $current_page) ?>">
                        <i class="bi bi-sliders"></i> Settings
                    </a>
                </li>
                <li>
                    <a href="<?= BASE_URL ?>/leaderboard.php" class="drawer-link <?= nav_active('leaderboard', $current_page) ?>">
                        <i class="bi bi-trophy"></i> Leaderboard
                    </a>
                </li>
                <li class="drawer-divider"></li>
                <li>
                    <a href="<?= BASE_URL ?>/submit_ticket.php" class="drawer-link <?= nav_active('submit_ticket', $current_page) ?>">
                        <i class="bi bi-headset"></i> Support
                    </a>
                </li>

            <?php endif; ?>
        </ul>
    </nav>

    <div class="drawer-footer">

        <?php if ($is_logged_in): ?>

            <div class="drawer-user-pill mb-3">
                <span class="drawer-user-avatar" aria-hidden="true"><?= $initials ?: '?' ?></span>
                <div class="drawer-user-info">
                    <span class="drawer-user-name" style="color: var(--text-primary);"><?= $session_name ?></span>
                </div>
            </div>

            <a href="<?= BASE_URL ?>/logout.php" class="btn-drawer-logout">
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </a>

        <?php else: ?>

            <a href="<?= BASE_URL ?>/login.php" class="btn-nav-cta w-100 justify-content-center" style="border-radius: var(--border-radius);">
                <i class="bi bi-person-fill"></i>
                Log In to Your Account
            </a>

        <?php endif; ?>

    </div></div><main class="page-main">