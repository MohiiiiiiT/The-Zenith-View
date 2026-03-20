<?php
// =============================================================================
// THE ZENITH VIEW — /student/dashboard.php
// Protected: Student Role Only
//
// ARCHITECTURE:
//   PHP logic block runs first (RBAC, all DB queries, data prep).
//   HTML rendering begins only after all data is ready.
//   CSS lives entirely in dashboard.css (linked in <head>).
//   No inline styles. No hardcoded colors. All theming via CSS variables.
//
// BLUEPRINT REFERENCES:
//   §1  — File lives in /student/ directory.
//   §2  — Schema: users, achievements tables.
//   §4  — RBAC: session check before anything else. PDO prepared statements.
//          XSS: htmlspecialchars() on every echo of user-generated data.
//   §8  — __DIR__ used for all require paths (local ↔ production safe).
//   §9B — Student feature scope: rank, CGPA points, total score, upload history.
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP: Config, Session, RBAC
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

// --- RBAC Gate ---
// Blueprint §4 & §9B: Only authenticated students may pass.
// Any other state (no session, wrong role) → immediate redirect.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}

$student_id = (int) $_SESSION['user_id'];


// =============================================================================
// PHASE 2 — DATA FETCH: All queries run before any HTML output
// =============================================================================

// ---------------------------------------------------------------------------
// QUERY 1 — Student Profile + Core Stats
// Fetches the full user row. total_points is the live score.
// base_cgpa is stored separately so we can display the academic component.
// Blueprint §2: users table schema.
// ---------------------------------------------------------------------------
$student = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, name, email, department, study_year, base_cgpa, total_points
        FROM   users
        WHERE  id = :id AND role = 'student' AND is_active = 1
    ");
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[Zenith View] Dashboard profile fetch error: ' . $e->getMessage());
}

// Safety net: if the row is missing (deactivated mid-session), force logout.
if (!$student) {
    session_destroy();
    header('Location: ../login.php?err=account_inactive');
    exit;
}


// ---------------------------------------------------------------------------
// QUERY 2 — Global Rank
// Counts how many active students have MORE points. Rank = that count + 1.
// Using a subquery approach avoids a separate ranking table.
// Blueprint §2: users table.
// ---------------------------------------------------------------------------
$global_rank = 1; // Default: assume #1 until proven otherwise
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS students_above
        FROM   users
        WHERE  role       = 'student'
          AND  is_active  = 1
          AND  total_points > (
              SELECT total_points
              FROM   users
              WHERE  id = :id
          )
    ");
    $stmt->execute([':id' => $student_id]);
    $row = $stmt->fetch();
    $global_rank = (int) $row['students_above'] + 1;
} catch (PDOException $e) {
    error_log('[Zenith View] Dashboard rank fetch error: ' . $e->getMessage());
}


// ---------------------------------------------------------------------------
// QUERY 3 — Achievement Counts (Approved & Pending)
// Two aggregate counts in one query for efficiency.
// Blueprint §2: achievements table.
// ---------------------------------------------------------------------------
$approved_count = 0;
$pending_count  = 0;
try {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_count
        FROM achievements
        WHERE student_id = :id
    ");
    $stmt->execute([':id' => $student_id]);
    $counts = $stmt->fetch();
    if ($counts) {
        $approved_count = (int) ($counts['approved_count'] ?? 0);
        $pending_count  = (int) ($counts['pending_count']  ?? 0);
    }
} catch (PDOException $e) {
    error_log('[Zenith View] Dashboard counts fetch error: ' . $e->getMessage());
}


// ---------------------------------------------------------------------------
// QUERY 4 — Gamification: Rank #1 Student's Points
// Finds the highest total_points among all active students.
// We then calculate the gap: rank1_points - this_student's points.
// Blueprint §9B: "View current rank" feature.
// ---------------------------------------------------------------------------
$rank1_points  = 0;
$points_gap    = 0;
$is_rank_one   = false;
try {
    $stmt = $pdo->prepare("
        SELECT MAX(total_points) AS top_score
        FROM   users
        WHERE  role = 'student' AND is_active = 1
    ");
    $stmt->execute();
    $top = $stmt->fetch();
    $rank1_points = (int) ($top['top_score'] ?? 0);
    $points_gap   = max(0, $rank1_points - (int) $student['total_points']);
    $is_rank_one  = ($global_rank === 1);
} catch (PDOException $e) {
    error_log('[Zenith View] Dashboard rank#1 fetch error: ' . $e->getMessage());
}


// ---------------------------------------------------------------------------
// QUERY 5 — Recent Activity Feed (Last 5 Submissions)
// Orders by submission date DESC to show the most recent work first.
// Blueprint §9B: "Submission History" feature.
// ---------------------------------------------------------------------------
$recent_activity = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, category, tier, title, points_awarded, status,
               rejection_reason, submitted_at
        FROM   achievements
        WHERE  student_id = :id
        ORDER  BY submitted_at DESC
        LIMIT  5
    ");
    $stmt->execute([':id' => $student_id]);
    $recent_activity = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Zenith View] Dashboard activity fetch error: ' . $e->getMessage());
}


// ---------------------------------------------------------------------------
// QUERY 6 — Category Point Distribution (for Skill Radar)
// Sums points_awarded per category for approved submissions only.
// Used by the Skill Radar progress bars in the right column.
// Blueprint §2: achievements table.
// ---------------------------------------------------------------------------
$category_points_raw = [];
try {
    $stmt = $pdo->prepare("
        SELECT   category, SUM(points_awarded) AS cat_points
        FROM     achievements
        WHERE    student_id = :id
          AND    status     = 'approved'
        GROUP BY category
    ");
    $stmt->execute([':id' => $student_id]);
    foreach ($stmt->fetchAll() as $row) {
        $category_points_raw[$row['category']] = (int) $row['cat_points'];
    }
} catch (PDOException $e) {
    error_log('[Zenith View] Dashboard category points fetch error: ' . $e->getMessage());
}


// =============================================================================
// PHASE 3 — DATA PREPARATION: Derive display-ready values
// All business logic and string building happens here, before HTML.
// =============================================================================

// Extract the student's first name for the personalised greeting.
// explode on space, take index 0. Works for "Rohan Sharma" → "Rohan".
$full_name  = $student['name'];
$first_name = explode(' ', trim($full_name))[0];

// Academic base points (CGPA × 100, matching Blueprint §3 scoring matrix).
$academic_points = round((float) $student['base_cgpa'] * 100);

// ---------------------------------------------------------------------------
// LIVE SCORE CALCULATION (Overrides static database total)
// ---------------------------------------------------------------------------
// 1. Get the sum of all approved achievements (calculated in Query 6)
$approved_achievement_points = array_sum($category_points_raw);

// 2. Add base CGPA points + approved achievement points
$live_total_points = $academic_points + $approved_achievement_points;

// 3. Override the static database value so the whole page uses the live math
$student['total_points'] = $live_total_points;
$total_pts = $live_total_points;

// 4. Format for display
$total_points_formatted = number_format($live_total_points);

// ---------------------------------------------------------------------------
// SKILL RADAR — category percentage calculation
// Each category's share of total_points is pre-calculated here so the HTML
// template stays logic-free. Division-by-zero is guarded by the ternary.
//
// Category map: canonical display name → DB category key (as stored in achievements).
// 'Academic' is derived from base_cgpa, not from the achievements table.
// ---------------------------------------------------------------------------
$radar_categories = [
    'Academic'           => null,          // Sourced from $academic_points, not DB
    'Technical Events'   => 'Technical Events',
    'Research'           => 'Research',
    'Extra-Curriculars'  => 'Extra-Curriculars',
    'Leadership'         => 'Leadership',
];

$total_pts = (int) $student['total_points'];

// Build the final radar rows: [label, points, pct]
$radar_rows = [];
foreach ($radar_categories as $label => $db_key) {
    if ($db_key === null) {
        // Academic: sourced from the CGPA base calculation
        $pts = (int) $academic_points;
    } else {
        $pts = $category_points_raw[$db_key] ?? 0;
    }
    // Guard division by zero; clamp to 100% ceiling
    $pct = ($total_pts > 0) ? min(100, round(($pts / $total_pts) * 100)) : 0;
    $radar_rows[] = ['label' => $label, 'pts' => $pts, 'pct' => $pct];
}

// Rank ordinal suffix: 1 → "1st", 2 → "2nd", 3 → "3rd", 4+ → "Nth"
function ordinal_suffix(int $n): string {
    if ($n >= 11 && $n <= 13) return $n . 'th'; // 11th, 12th, 13th exception
    return match ($n % 10) {
        1 => $n . 'st',
        2 => $n . 'nd',
        3 => $n . 'rd',
        default => $n . 'th',
    };
}

// Page meta (consumed by header.php for <title> and active nav state).
$page_title = 'My Dashboard';
$active_nav = 'dashboard';

// Per-page stylesheet injected into <head> by header.php.
// header.php should echo $extra_head inside <head> if this variable is set.
// This keeps dashboard styles scoped and out of the global style.css.
// $extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/student/dashboard.css">';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/dashboard.css">';


// =============================================================================
// PHASE 4 — RENDER: HTML output begins here
// =============================================================================
require_once __DIR__ . '/../includes/header.php';
?>

<main class="page-main" id="main-content">
<div class="dashboard-wrapper">

    <!-- =====================================================================
         DASHBOARD HEADER
         Personalised greeting + metadata strip (dept, year, CGPA).
         The greeting uses the student's first name only (extracted above).
    ====================================================================== -->
    <header class="dash-header">
        <div class="dash-header__left">
            <p class="dash-header__eyebrow">Student Portal</p>
            <h1 class="dash-header__greeting">
                Welcome back, <span class="dash-header__name"><?= htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8') ?></span>
            </h1>
            <div class="dash-header__meta">
                <span class="meta-chip">
                    <i class="bi bi-building" aria-hidden="true"></i>
                    <?= htmlspecialchars($student['department'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="meta-chip">
                    <i class="bi bi-mortarboard" aria-hidden="true"></i>
                    <?= htmlspecialchars($student['study_year'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="meta-chip meta-chip--academic">
                    <i class="bi bi-graph-up" aria-hidden="true"></i>
                    CGPA <?= htmlspecialchars(number_format((float)$student['base_cgpa'], 2), ENT_QUOTES, 'UTF-8') ?>
                    &rarr; <?= (int) $academic_points ?> base pts
                </span>
            </div>
        </div>
        <div class="dash-header__right">
            <!-- Single upload entry point for the entire dashboard -->
            <a href="upload_proof.php" class="btn-upload-hero" aria-label="Upload a new achievement proof">
                <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
                <span>Upload Achievement</span>
            </a>
        </div>
    </header>


    <!-- =====================================================================
         BENTO STATS GRID
         4 stat cards in a responsive bento layout:
           Desktop  (≥992px)  — single row, 4 columns (2fr 1fr 1fr 1fr).
                                 Card 1 is naturally wider via the 2fr track.
                                 No grid-column span — all 4 cards fit in 4 tracks.
           Tablet   (≥576px)  — 2 × 2 grid; wide card spans both columns
           Mobile   (<576px)  — single column stack
    ====================================================================== -->
    <section class="bento-grid" aria-label="Your performance statistics">

        <!-- CARD 1: Total Points (naturally wider via 2fr column track on desktop) -->
        <div class="bento-card bento-card--wide bento-card--primary" aria-label="Total Points">
            <div class="bento-card__icon" aria-hidden="true">
                <i class="bi bi-lightning-charge-fill"></i>
            </div>
            <div class="bento-card__body">
                <span class="bento-card__label">Total Score</span>
                <span class="bento-card__value" id="stat-points">
                    <?= htmlspecialchars($total_points_formatted, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="bento-card__sub">points earned</span>
            </div>
            <!-- GAMIFICATION HOOK: Point gap to Rank #1 -->
            <div class="gamification-hook" aria-live="polite">
                <?php if ($is_rank_one): ?>
                    <span class="gamification-hook__badge gamification-hook__badge--champion">
                        <i class="bi bi-trophy-fill" aria-hidden="true"></i>
                        You are Rank #1! Keep the crown!
                    </span>
                <?php elseif ($points_gap > 0): ?>
                    <span class="gamification-hook__badge gamification-hook__badge--chase">
                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                        <?= number_format($points_gap) ?> points from Rank #1
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- CARD 2: Global Rank -->
        <div class="bento-card" aria-label="Global Rank">
            <div class="bento-card__icon" aria-hidden="true">
                <i class="bi bi-bar-chart-line-fill"></i>
            </div>
            <div class="bento-card__body">
                <span class="bento-card__label">Global Rank</span>
                <span class="bento-card__value"><?= ordinal_suffix($global_rank) ?></span>
                <span class="bento-card__sub">across all students</span>
            </div>
        </div>

        <!-- CARD 3: Approved Proofs -->
        <div class="bento-card" aria-label="Approved Achievements">
            <div class="bento-card__icon bento-card__icon--approved" aria-hidden="true">
                <i class="bi bi-patch-check-fill"></i>
            </div>
            <div class="bento-card__body">
                <span class="bento-card__label">Approved</span>
                <span class="bento-card__value"><?= (int) $approved_count ?></span>
                <span class="bento-card__sub">verified achievements</span>
            </div>
        </div>

        <!-- CARD 4: Pending Reviews -->
        <div class="bento-card" aria-label="Pending Reviews">
            <div class="bento-card__icon bento-card__icon--pending" aria-hidden="true">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div class="bento-card__body">
                <span class="bento-card__label">Pending Review</span>
                <span class="bento-card__value"><?= (int) $pending_count ?></span>
                <span class="bento-card__sub">awaiting faculty</span>
            </div>
        </div>

    </section><!-- /bento-grid -->


    <!-- =====================================================================
         LOWER SECTION: Two-column layout on desktop, stacked on mobile.
         LEFT  (col-lg-8) — Recent Activity Feed
         RIGHT (col-lg-4) — Action Portal Card
         Bootstrap grid handles all layout shifts.
    ====================================================================== -->
    <div class="row g-4 mt-0">

        <!-- =================================================================
             LEFT COLUMN: Recent Activity Feed
        ================================================================= -->
        <div class="col-12 col-lg-8">
            <div class="dash-card" id="recent-activity">
                <div class="dash-card__header">
                    <div>
                        <h2 class="dash-card__title">Recent Submissions</h2>
                        <p class="dash-card__subtitle">Your 5 most recent achievement uploads</p>
                    </div>
                    <?php if (!empty($recent_activity)): ?>
                    <a href="upload_proof.php" class="dash-card__header-action" aria-label="Submit another achievement">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> New
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($recent_activity)): ?>
                <!-- =========================================================
                     EMPTY STATE
                     Shown when the student has 0 submissions. No button here —
                     the single upload entry point is the header button above.
                ========================================================== -->
                <div class="empty-state" role="region" aria-label="No submissions yet">
                    <div class="empty-state__icon" aria-hidden="true">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h3 class="empty-state__title">Your journey starts here</h3>
                    <p class="empty-state__body">
                        You haven't submitted any achievements yet. Click the
                        <strong>Upload Achievement</strong> button in the top right
                        to get on the board!
                    </p>
                </div>

                <?php else: ?>
                <!-- =========================================================
                     ACTIVITY TABLE
                     Redesigned for mobile-first: Status and Points columns
                     are always visible at every breakpoint. Category and Date
                     collapse on smaller screens as before, but the two most
                     important data points — what happened and what it earned —
                     are never hidden.
                     .table-responsive wrapper retained for future-proofing
                     if more columns are added.
                ========================================================== -->
                <div class="table-responsive">
                    <table class="table activity-table" aria-label="Recent achievement submissions">
                        <thead>
                            <tr>
                                <th scope="col">Achievement</th>
                                <th scope="col" class="d-none d-sm-table-cell">Category</th>
                                <th scope="col" class="d-none d-md-table-cell">Submitted</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-end">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_activity as $activity): ?>
                            <tr class="activity-row activity-row--<?= htmlspecialchars($activity['status'], ENT_QUOTES, 'UTF-8') ?>">

                                <!-- Achievement Title + Tier (+ rejection note on mobile) -->
                                <td class="activity-title-cell">
                                    <span class="activity-title">
                                        <?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="activity-tier">
                                        <?= htmlspecialchars($activity['tier'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($activity['status'] === 'rejected' && !empty($activity['rejection_reason'])): ?>
                                        <span class="activity-rejection-note d-sm-none">
                                            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                            <?= htmlspecialchars($activity['rejection_reason'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Category — hidden on xs -->
                                <td class="d-none d-sm-table-cell">
                                    <span class="category-tag">
                                        <?= htmlspecialchars($activity['category'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($activity['status'] === 'rejected' && !empty($activity['rejection_reason'])): ?>
                                        <span class="activity-rejection-note d-none d-sm-inline-flex">
                                            <i class="bi bi-chat-left-text-fill" aria-hidden="true"></i>
                                            <?= htmlspecialchars($activity['rejection_reason'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Date — hidden on xs and sm -->
                                <td class="d-none d-md-table-cell activity-date">
                                    <?= htmlspecialchars(
                                        date('d M Y', strtotime($activity['submitted_at'])),
                                        ENT_QUOTES, 'UTF-8'
                                    ) ?>
                                </td>

                                <!-- Status Badge — ALWAYS VISIBLE -->
                                <td class="text-center activity-status-cell">
                                    <?php
                                    $badge_map = [
                                        'approved' => ['class' => 'badge--approved', 'icon' => 'bi-check-circle-fill', 'label' => 'Approved'],
                                        'pending'  => ['class' => 'badge--pending',  'icon' => 'bi-clock-fill',        'label' => 'Pending'],
                                        'rejected' => ['class' => 'badge--rejected', 'icon' => 'bi-x-circle-fill',     'label' => 'Rejected'],
                                    ];
                                    $status_key = $activity['status'];
                                    $badge = $badge_map[$status_key] ?? $badge_map['pending'];
                                    ?>
                                    <span class="status-badge <?= $badge['class'] ?>" role="status">
                                        <i class="bi <?= $badge['icon'] ?>" aria-hidden="true"></i>
                                        <?php /* Label text hidden on xs; icon alone carries meaning at that width */ ?>
                                        <span class="badge-label"><?= $badge['label'] ?></span>
                                    </span>
                                </td>

                                <!-- Points — ALWAYS VISIBLE -->
                                <td class="text-end activity-points">
                                    <?php if ($activity['status'] === 'approved'): ?>
                                        <span class="points-value">+<?= number_format((int) $activity['points_awarded']) ?></span>
                                    <?php elseif ($activity['status'] === 'pending'): ?>
                                        <span class="points-tbd" aria-label="Points pending review">—</span>
                                    <?php else: ?>
                                        <span class="points-nil" aria-label="No points awarded">0</span>
                                    <?php endif; ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div><!-- /table-responsive -->

                <!-- "View All" footer — always rendered when the table is shown -->
                <div class="activity-card__footer">
                    <a href="my_submissions.php" class="btn-view-all" aria-label="View all your submissions">
                        View All Submissions
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <?php endif; ?>

            </div><!-- /dash-card #recent-activity -->
        </div><!-- /col-lg-8 -->


        <!-- =================================================================
             RIGHT COLUMN: Score Breakdown + Skill Radar
             Two separate cards stacked vertically via .right-col-stack.
             On mobile (< 992px) Bootstrap stacks this below the left col.
        ================================================================= -->
        <div class="col-12 col-lg-4">
            <div class="right-col-stack">

                <!-- CARD 1: SCORE BREAKDOWN — original three-line math card -->
                <div class="dash-card score-breakdown" aria-label="Score breakdown">
                    <div class="dash-card__header">
                        <div>
                            <h2 class="dash-card__title">Score Breakdown</h2>
                            <p class="dash-card__subtitle">How your total is calculated</p>
                        </div>
                    </div>
                    <div class="breakdown-list">
                        <div class="breakdown-item">
                            <span class="breakdown-item__label">
                                <i class="bi bi-journal-text" aria-hidden="true"></i>
                                Academic (CGPA &times; 100)
                            </span>
                            <span class="breakdown-item__value"><?= number_format($academic_points) ?></span>
                        </div>
                        <div class="breakdown-item">
                            <span class="breakdown-item__label">
                                <i class="bi bi-patch-check" aria-hidden="true"></i>
                                Approved Activities
                            </span>
                            <span class="breakdown-item__value">
                                <?= number_format(max(0, (int)$student['total_points'] - $academic_points)) ?>
                            </span>
                        </div>
                        <div class="breakdown-item breakdown-item--total">
                            <span class="breakdown-item__label">
                                <i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>
                                Total Score
                            </span>
                            <span class="breakdown-item__value"><?= $total_points_formatted ?></span>
                        </div>
                    </div>
                    <div class="score-breakdown__footer">
                        <a href="tickets.php" class="btn-support-link" aria-label="Report a scoring issue">
                            <i class="bi bi-headset" aria-hidden="true"></i>
                            Report a scoring issue
                        </a>
                    </div>
                </div><!-- /score-breakdown -->

                <!-- CARD 2: SKILL RADAR — category progress bars -->
                <div class="dash-card" aria-label="Skill Radar — point distribution by category">
                    <div class="dash-card__header">
                        <div>
                            <h2 class="dash-card__title">Skill Radar</h2>
                            <p class="dash-card__subtitle">Progress analytics by category</p>
                        </div>
                    </div>

                    <div class="radar-list">
                    <?php foreach ($radar_rows as $row):
                        $fill_class = ($row['label'] === 'Academic') ? 'radar-fill--academic' : '';
                    ?>
                        <div class="radar-item">
                            <div class="radar-item__header">
                                <span class="radar-item__label"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="radar-item__meta">
                                    <span class="radar-item__pts"><?= number_format($row['pts']) ?> pts</span>
                                    <span class="radar-item__pct"><?= $row['pct'] ?>%</span>
                                </span>
                            </div>
                            <div class="radar-track" role="progressbar"
                                 aria-valuenow="<?= $row['pct'] ?>"
                                 aria-valuemin="0" aria-valuemax="100"
                                 aria-label="<?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?> progress: <?= $row['pct'] ?>%">
                                <div class="radar-fill <?= $fill_class ?>"
                                     style="width: <?= $row['pct'] ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div><!-- /radar-list -->

                </div><!-- /skill-radar -->

            </div><!-- /right-col-stack -->
        </div><!-- /col-lg-4 -->
    </div><!-- /row -->

</div><!-- /dashboard-wrapper -->
</main><!-- /page-main -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>