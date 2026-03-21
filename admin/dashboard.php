<?php
// =============================================================================
// THE ZENITH VIEW — /admin/dashboard.php
// Protected: Admin Role Only | Command Center
//
// SECURITY CHECKLIST:
//   [x] RBAC gate — only role='admin' may access
//   [x] All DB queries use PDO prepared statements
//   [x] XSS — every echo of user data through htmlspecialchars()
//   [x] PRG pattern not needed (read-only page)
//
// SECTIONS:
//   Phase 1 — Bootstrap & RBAC
//   Phase 2 — KPI Queries (students, teachers, pending, window status)
//   Phase 3 — Live Pulse Feed (5 most recent achievement events)
//   Phase 4 — Department Performance Matrix
//   Phase 5 — Page meta & render
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP & RBAC
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$admin_id   = (int) $_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');


// =============================================================================
// PHASE 2 — KPI QUERIES
// Lightweight aggregate counts — each runs as a single fast query.
// =============================================================================

// ── KPI 1: Total active students ────────────────────────────────────────────
$total_students = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users
        WHERE  role = 'student' AND is_active = 1
    ");
    $stmt->execute();
    $total_students = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Zenith View] admin dashboard student count: ' . $e->getMessage());
}

// ── KPI 2: Total active teachers ────────────────────────────────────────────
$total_teachers = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users
        WHERE  role = 'teacher' AND is_active = 1
    ");
    $stmt->execute();
    $total_teachers = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Zenith View] admin dashboard teacher count: ' . $e->getMessage());
}

// ── KPI 3: Global pending achievements ──────────────────────────────────────
$total_pending = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM achievements WHERE status = 'pending'
    ");
    $stmt->execute();
    $total_pending = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[Zenith View] admin dashboard pending count: ' . $e->getMessage());
}

// ── KPI 4: Submission window status from system_settings ────────────────────
$window_status = 'closed'; // safe default
try {
    $stmt = $pdo->prepare("
        SELECT setting_value FROM system_settings
        WHERE  setting_key = 'submission_window'
        LIMIT  1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $window_status = strtolower(trim($row['setting_value']));
    }
} catch (PDOException $e) {
    error_log('[Zenith View] admin dashboard window status: ' . $e->getMessage());
}

$window_open = ($window_status === 'open');


// =============================================================================
// PHASE 3 — LIVE PULSE FEED
// 5 most recent achievement events with student name + reviewer name.
// updated_at is used for ordering (covers both submission and review events).
// A LEFT JOIN on users aliased as 'r' fetches the reviewer name — NULL if
// the achievement is still pending (no reviewer yet).
// =============================================================================

$pulse_feed = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.title,
            a.category,
            a.status,
            a.submitted_at,
            a.updated_at,
            s.name  AS student_name,
            r.name  AS reviewer_name
        FROM      achievements  a
        JOIN      users         s ON s.id = a.student_id
        LEFT JOIN users         r ON r.id = a.reviewed_by
        ORDER BY  a.updated_at  DESC
        LIMIT     5
    ");
    $stmt->execute();
    $pulse_feed = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Zenith View] admin dashboard pulse feed: ' . $e->getMessage());
}


// =============================================================================
// PHASE 4 — DEPARTMENT PERFORMANCE MATRIX
// Advanced LEFT JOIN so departments with zero students still appear.
// Groups by department from the users table; aggregates per-dept stats
// from the achievements table via sub-aggregation.
// =============================================================================

$dept_matrix = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            u.department,
            COUNT(DISTINCT u.id)                                          AS active_students,
            COALESCE(SUM(u.total_points), 0)                              AS total_dept_points,
            COUNT(CASE WHEN a.status = 'pending'  THEN 1 END)            AS pending_count,
            COUNT(CASE WHEN a.status = 'approved' THEN 1 END)            AS approved_count
        FROM      users        u
        LEFT JOIN achievements a ON a.student_id = u.id
        WHERE     u.role      = 'student'
          AND     u.is_active = 1
        GROUP BY  u.department
        ORDER BY  total_dept_points DESC
    ");
    $stmt->execute();
    $dept_matrix = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Zenith View] admin dashboard dept matrix: ' . $e->getMessage());
}


// =============================================================================
// PHASE 5 — PAGE META
// =============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title = 'Command Center';
$active_nav = 'dashboard';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin_dashboard.css">';

require_once __DIR__ . '/../includes/header.php';

$base = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');

// ── Helper: build a human-readable pulse event string ───────────────────────
function pulse_event_text(array $row): array {
    $student  = htmlspecialchars($row['student_name']  ?? 'A student',  ENT_QUOTES, 'UTF-8');
    $reviewer = htmlspecialchars($row['reviewer_name'] ?? 'A reviewer', ENT_QUOTES, 'UTF-8');
    $title    = htmlspecialchars($row['title']         ?? 'achievement', ENT_QUOTES, 'UTF-8');

    switch ($row['status']) {
        case 'approved':
            return [
                'icon'    => 'bi-patch-check-fill',
                'color'   => 'pulse--approved',
                'message' => "<strong>{$reviewer}</strong> approved <em>{$title}</em>",
                'sub'     => "Submitted by {$student}",
            ];
        case 'rejected':
            return [
                'icon'    => 'bi-x-circle-fill',
                'color'   => 'pulse--rejected',
                'message' => "<strong>{$reviewer}</strong> rejected <em>{$title}</em>",
                'sub'     => "Submitted by {$student}",
            ];
        default: // pending
            return [
                'icon'    => 'bi-cloud-arrow-up-fill',
                'color'   => 'pulse--pending',
                'message' => "<strong>{$student}</strong> submitted <em>{$title}</em>",
                'sub'     => ucfirst(htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8')),
            ];
    }
}

// ── Helper: relative time label ──────────────────────────────────────────────
function relative_time(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M', strtotime($datetime));
}
?>

<main class="page-main" id="main-content">
<div class="adm-wrapper">


    <!-- =========================================================================
         FLASH MESSAGE
    ========================================================================= -->
    <?php if ($flash): ?>
    <?php
        $alert_class = match($flash['type']) {
            'success' => 'adm-alert--success',
            'warning' => 'adm-alert--warning',
            default   => 'adm-alert--error',
        };
        $alert_icon = match($flash['type']) {
            'success' => 'bi-check-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            default   => 'bi-x-circle-fill',
        };
    ?>
    <div class="adm-alert <?= $alert_class ?>" role="alert">
        <i class="bi <?= $alert_icon ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></span>
        <button type="button" class="adm-alert__dismiss"
                onclick="this.parentElement.remove()" aria-label="Dismiss">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <?php endif; ?>


    <!-- =========================================================================
         PAGE HEADER
    ========================================================================= -->
    <header class="adm-header">
        <div>
            <p class="adm-header__eyebrow">
                <i class="bi bi-shield-fill-check" aria-hidden="true"></i>
                Admin Command Center
            </p>
            <h1 class="adm-header__title">Platform Dashboard</h1>
            <p class="adm-header__sub">
                Real-time overview of platform health, activity, and department performance.
            </p>
        </div>
        <div class="adm-header__meta" aria-label="Logged in as <?= $admin_name ?>">
            <span class="adm-avatar" aria-hidden="true">
                <?= strtoupper(mb_substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
            </span>
            <div>
                <span class="adm-header__user-name"><?= $admin_name ?></span>
                <span class="adm-header__user-role">Administrator</span>
            </div>
        </div>
    </header>


    <!-- =========================================================================
         ROW 1 — KPI CARDS
         4 responsive cards: 1 col → 2 col (sm) → 4 col (xl)
    ========================================================================= -->
    <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-4 mb-4"
         aria-label="Key performance indicators">

        <!-- KPI 1: Total Active Students -->
        <div class="col">
            <div class="kpi-card">
                <div class="kpi-card__icon-wrap kpi-card__icon-wrap--blue" aria-hidden="true">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="kpi-card__body">
                    <span class="kpi-card__label">Active Students</span>
                    <span class="kpi-card__value"><?= number_format($total_students) ?></span>
                </div>
            </div>
        </div>

        <!-- KPI 2: Total Active Teachers -->
        <div class="col">
            <div class="kpi-card">
                <div class="kpi-card__icon-wrap kpi-card__icon-wrap--purple" aria-hidden="true">
                    <i class="bi bi-person-badge-fill"></i>
                </div>
                <div class="kpi-card__body">
                    <span class="kpi-card__label">Active Faculty</span>
                    <span class="kpi-card__value"><?= number_format($total_teachers) ?></span>
                </div>
            </div>
        </div>

        <!-- KPI 3: Global Pending Achievements -->
        <div class="col">
            <div class="kpi-card">
                <div class="kpi-card__icon-wrap kpi-card__icon-wrap--amber" aria-hidden="true">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="kpi-card__body">
                    <span class="kpi-card__label">Pending Review</span>
                    <span class="kpi-card__value"><?= number_format($total_pending) ?></span>
                </div>
            </div>
        </div>

        <!-- KPI 4: System Status — pulsing dot indicator -->
        <div class="col">
            <div class="kpi-card kpi-card--status">
                <div class="kpi-card__icon-wrap <?= $window_open ? 'kpi-card__icon-wrap--green' : 'kpi-card__icon-wrap--red' ?>"
                     aria-hidden="true">
                    <i class="bi <?= $window_open ? 'bi-unlock-fill' : 'bi-lock-fill' ?>"></i>
                </div>
                <div class="kpi-card__body">
                    <span class="kpi-card__label">Submission Window</span>
                    <span class="kpi-card__value kpi-card__value--status">
                        <span class="status-dot <?= $window_open ? 'status-dot--open' : 'status-dot--closed' ?>"
                              aria-hidden="true"></span>
                        <?= $window_open ? 'Open' : 'Closed' ?>
                    </span>
                </div>
            </div>
        </div>

    </div><!-- /Row 1 KPIs -->


    <!-- =========================================================================
         ROW 2 — LIVE PULSE + QUICK ACTIONS
         col-lg-7 left (pulse feed) + col-lg-5 right (quick actions bento)
    ========================================================================= -->
    <div class="row g-3 mb-4 align-items-start">

        <!-- LEFT: Live Platform Pulse ──────────────────────────────────────── -->
        <div class="col-12 col-lg-7">
            <div class="adm-card h-100">
                <div class="adm-card__header">
                    <div>
                        <h2 class="adm-card__title">
                            <i class="bi bi-activity" aria-hidden="true"></i>
                            Live Platform Pulse
                        </h2>
                        <p class="adm-card__sub">5 most recent achievement events</p>
                    </div>
                    <span class="pulse-live-badge" aria-label="Live feed">
                        <span class="status-dot status-dot--open" aria-hidden="true"></span>
                        Live
                    </span>
                </div>

                <?php if (empty($pulse_feed)): ?>
                <!-- Empty state -->
                <div class="adm-empty" role="status">
                    <div class="adm-empty__icon" aria-hidden="true">
                        <i class="bi bi-wind"></i>
                    </div>
                    <p class="adm-empty__title">No activity yet</p>
                    <p class="adm-empty__sub">
                        Events will appear here once students start submitting achievements.
                    </p>
                </div>

                <?php else: ?>
                <ul class="pulse-list" role="list" aria-label="Recent platform activity">
                    <?php foreach ($pulse_feed as $event):
                        $ev = pulse_event_text($event);
                        $ts = relative_time($event['updated_at'] ?? $event['submitted_at']);
                    ?>
                    <li class="pulse-item">
                        <div class="pulse-icon-wrap <?= $ev['color'] ?>" aria-hidden="true">
                            <i class="bi <?= $ev['icon'] ?>"></i>
                        </div>
                        <div class="pulse-item__text">
                            <span class="pulse-item__msg"><?= $ev['message'] ?></span>
                            <span class="pulse-item__sub"><?= $ev['sub'] ?></span>
                        </div>
                        <time class="pulse-item__time"
                              datetime="<?= htmlspecialchars($event['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?= $ts ?>
                        </time>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

            </div><!-- /pulse card -->
        </div>

        <!-- RIGHT: Quick Actions Bento Grid ───────────────────────────────── -->
        <div class="col-12 col-lg-5">
            <div class="adm-card">
                <div class="adm-card__header">
                    <div>
                        <h2 class="adm-card__title">
                            <i class="bi bi-grid-3x2-gap-fill" aria-hidden="true"></i>
                            Quick Actions
                        </h2>
                        <p class="adm-card__sub">Platform management shortcuts</p>
                    </div>
                </div>

                <div class="bento-actions" role="list" aria-label="Admin quick action buttons">

                    <a href="<?= $base ?>/admin/manage_users.php?action=new"
                       class="bento-action" role="listitem"
                       aria-label="Add a new user">
                        <div class="bento-action__icon bento-action__icon--green" aria-hidden="true">
                            <i class="bi bi-person-plus-fill"></i>
                        </div>
                        <span class="bento-action__label">Add User</span>
                        <span class="bento-action__sub">Create a new account</span>
                    </a>

                    <a href="<?= $base ?>/admin/manage_users.php"
                       class="bento-action" role="listitem"
                       aria-label="Manage all users">
                        <div class="bento-action__icon bento-action__icon--blue" aria-hidden="true">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <span class="bento-action__label">Manage Users</span>
                        <span class="bento-action__sub">View, edit &amp; deactivate</span>
                    </a>

                    <a href="<?= $base ?>/admin/settings.php"
                       class="bento-action" role="listitem"
                       aria-label="Open system settings">
                        <div class="bento-action__icon bento-action__icon--amber" aria-hidden="true">
                            <i class="bi bi-gear-fill"></i>
                        </div>
                        <span class="bento-action__label">System Settings</span>
                        <span class="bento-action__sub">Window &amp; configuration</span>
                    </a>

                    <a href="<?= $base ?>/admin/helpdesk.php"
                       class="bento-action" role="listitem"
                       aria-label="Open helpdesk tickets">
                        <div class="bento-action__icon bento-action__icon--red" aria-hidden="true">
                            <i class="bi bi-headset"></i>
                        </div>
                        <span class="bento-action__label">Helpdesk</span>
                        <span class="bento-action__sub">Manage support tickets</span>
                    </a>

                </div>
            </div><!-- /quick actions card -->
        </div>

    </div><!-- /Row 2 -->


    <!-- =========================================================================
         ROW 3 — DEPARTMENT PERFORMANCE MATRIX
         Full-width responsive table wrapped in .table-responsive
    ========================================================================= -->
    <div class="adm-card mb-4" id="dept-matrix">
        <div class="adm-card__header">
            <div>
                <h2 class="adm-card__title">
                    <i class="bi bi-bar-chart-steps" aria-hidden="true"></i>
                    Department Performance Matrix
                </h2>
                <p class="adm-card__sub">
                    Ranked by total points — aggregated across all active students
                </p>
            </div>
            <a href="<?= $base ?>/leaderboard.php"
               class="adm-card__header-link" aria-label="View full leaderboard">
                View Leaderboard
                <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
            </a>
        </div>

        <?php if (empty($dept_matrix)): ?>
        <div class="adm-empty" role="status">
            <div class="adm-empty__icon" aria-hidden="true"><i class="bi bi-table"></i></div>
            <p class="adm-empty__title">No department data yet</p>
            <p class="adm-empty__sub">Data will populate once students are enrolled.</p>
        </div>

        <?php else: ?>
        <div class="table-responsive">
            <table class="adm-table" aria-label="Department performance statistics">
                <thead>
                    <tr>
                        <th scope="col" class="adm-table__rank">#</th>
                        <th scope="col">Department</th>
                        <th scope="col" class="text-end">Students</th>
                        <th scope="col" class="text-end">Total Points</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">Approved</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">Pending</th>
                        <th scope="col" class="d-none d-lg-table-cell">Activity</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dept_matrix as $rank => $dept):
                    $e_dept     = htmlspecialchars($dept['department'], ENT_QUOTES, 'UTF-8');
                    $students   = (int) $dept['active_students'];
                    $pts        = (int) $dept['total_dept_points'];
                    $approved   = (int) $dept['approved_count'];
                    $pending    = (int) $dept['pending_count'];
                    $total_acts = $approved + $pending;
                    $pct        = $total_acts > 0 ? round(($approved / $total_acts) * 100) : 0;
                    // Find max pts for proportional bar
                    $max_pts    = (int) ($dept_matrix[0]['total_dept_points'] ?? 1);
                    $bar_width  = $max_pts > 0 ? min(100, round(($pts / $max_pts) * 100)) : 0;
                    $rank_num   = $rank + 1;
                ?>
                <tr>
                    <td class="adm-table__rank">
                        <?php if ($rank_num === 1): ?>
                            <span class="rank-medal rank-medal--gold"
                                  title="Rank #1">
                                <i class="bi bi-trophy-fill" aria-hidden="true"></i>
                            </span>
                        <?php elseif ($rank_num === 2): ?>
                            <span class="rank-medal rank-medal--silver"
                                  title="Rank #2"><?= $rank_num ?></span>
                        <?php elseif ($rank_num === 3): ?>
                            <span class="rank-medal rank-medal--bronze"
                                  title="Rank #3"><?= $rank_num ?></span>
                        <?php else: ?>
                            <span class="rank-num"><?= $rank_num ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="dept-name"><?= $e_dept ?></span>
                    </td>
                    <td class="text-end">
                        <span class="table-value"><?= number_format($students) ?></span>
                    </td>
                    <td class="text-end">
                        <span class="table-value table-value--pts">
                            <?= number_format($pts) ?>
                        </span>
                    </td>
                    <td class="text-end d-none d-md-table-cell">
                        <span class="table-badge table-badge--approved"><?= $approved ?></span>
                    </td>
                    <td class="text-end d-none d-md-table-cell">
                        <?php if ($pending > 0): ?>
                            <span class="table-badge table-badge--pending"><?= $pending ?></span>
                        <?php else: ?>
                            <span class="table-value table-value--muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <div class="dept-bar-wrap" title="<?= $pct ?>% approval rate">
                            <div class="dept-bar">
                                <div class="dept-bar__fill"
                                     style="width: <?= $bar_width ?>%"
                                     role="progressbar"
                                     aria-valuenow="<?= $bar_width ?>"
                                     aria-valuemin="0"
                                     aria-valuemax="100"
                                     aria-label="<?= $e_dept ?> points: <?= $bar_width ?>% of top department"></div>
                            </div>
                            <span class="dept-bar__pct"><?= $pct ?>% approved</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div><!-- /dept matrix card -->


</div><!-- /adm-wrapper -->
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>