<?php
// =============================================================================
// THE ZENITH VIEW — /student/my_submissions.php
// Protected: Student Role Only | Detailed Submission Ledger
//
// ARCHITECTURE:
//   PHP logic block runs first (RBAC, all DB queries, data prep).
//   HTML rendering begins only after all data is ready.
//   CSS lives entirely in submissions.css (linked in <head>).
//   No inline styles. No hardcoded colors. All theming via CSS variables.
//
// SECURITY:
//   [x] RBAC gate — only role='student' may access
//   [x] PDO prepared statements — all queries parameterised
//   [x] XSS — every echo of user data wrapped in htmlspecialchars()
//   [x] student_id always sourced from $_SESSION, never from $_GET/$_POST
//
// BLUEPRINT REFERENCES:
//   §1  — File lives in /student/ directory
//   §2  — Schema: users, achievements tables
//   §4  — RBAC, PDO, XSS
//   §9B — Student feature scope
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP: Config, Session, RBAC
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}

$student_id = (int) $_SESSION['user_id'];


// =============================================================================
// PHASE 2 — DATA FETCH: All queries run before any HTML output
// =============================================================================

// ---------------------------------------------------------------------------
// QUERY 1 — Student Profile
// Only name is needed for the page header personalisation.
// Safety net: missing row (deactivated mid-session) forces re-login.
// ---------------------------------------------------------------------------
$student = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, name
        FROM   users
        WHERE  id = :id AND role = 'student' AND is_active = 1
        LIMIT  1
    ");
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Zenith View] Submissions: profile fetch error: ' . $e->getMessage());
}

if (!$student) {
    session_destroy();
    header('Location: ../login.php?err=account_inactive');
    exit;
}


// ---------------------------------------------------------------------------
// QUERY 2 — Summary Counts
// Single aggregate query: total, approved, pending, rejected — all in one pass.
// The CASE expressions are evaluated server-side; no PHP loop needed.
// ---------------------------------------------------------------------------
$counts = ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                              AS total,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
        FROM achievements
        WHERE student_id = :id
    ");
    $stmt->execute([':id' => $student_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $counts = [
            'total'    => (int) ($row['total']    ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'pending'  => (int) ($row['pending']  ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }
} catch (PDOException $e) {
    error_log('[Zenith View] Submissions: counts fetch error: ' . $e->getMessage());
}


// ---------------------------------------------------------------------------
// QUERY 3 — Full Submission History
// All columns needed for the ledger cards, ordered newest-first.
// No LIMIT — this is the complete history page.
// ---------------------------------------------------------------------------
$submissions = [];
try {
    $stmt = $pdo->prepare("
        SELECT   id, category, tier, title,
                 points_awarded, status, rejection_reason,
                 submitted_at
        FROM     achievements
        WHERE    student_id = :id
        ORDER BY submitted_at DESC
    ");
    $stmt->execute([':id' => $student_id]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Zenith View] Submissions: history fetch error: ' . $e->getMessage());
}


// =============================================================================
// PHASE 3 — DATA PREPARATION
// =============================================================================

$first_name = explode(' ', trim($student['name']))[0];

// ---------------------------------------------------------------------------
// CATEGORY → ICON MAP
// Maps each achievement category to a Bootstrap Icon class.
// The icon is displayed inside a coloured circle on each ledger card.
// 'default' catches any unmapped / future categories gracefully.
// ---------------------------------------------------------------------------
$category_icons = [
    'Technical Events'  => ['icon' => 'bi-code-slash',        'mod' => 'icon--tech'],
    'Research'          => ['icon' => 'bi-journal-richtext',   'mod' => 'icon--research'],
    'Extra-Curriculars' => ['icon' => 'bi-music-note-beamed',  'mod' => 'icon--extra'],
    'Leadership'        => ['icon' => 'bi-people-fill',        'mod' => 'icon--leadership'],
    'Academic'          => ['icon' => 'bi-mortarboard-fill',   'mod' => 'icon--academic'],
    'default'           => ['icon' => 'bi-award',              'mod' => 'icon--default'],
];

// ---------------------------------------------------------------------------
// BADGE MAP — identical to dashboard.php for visual consistency
// ---------------------------------------------------------------------------
$badge_map = [
    'approved' => ['class' => 'badge--approved', 'icon' => 'bi-check-circle-fill', 'label' => 'Approved'],
    'pending'  => ['class' => 'badge--pending',  'icon' => 'bi-clock-fill',        'label' => 'Pending'],
    'rejected' => ['class' => 'badge--rejected', 'icon' => 'bi-x-circle-fill',     'label' => 'Rejected'],
];

// ---------------------------------------------------------------------------
// PAGE META
// ---------------------------------------------------------------------------
$page_title = 'My Submissions';
$active_nav = 'dashboard'; // Submissions is a sub-page of the student portal
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/submissions.css">';

require_once __DIR__ . '/../includes/header.php';
?>

<main class="page-main" id="main-content">
<div class="sl-wrapper">

    <!-- =========================================================================
         PAGE HEADER
         Breadcrumb → eyebrow → title → subtitle.
         Right side: Upload Achievement CTA (single entry point, mirrors dashboard).
    ========================================================================= -->
    <header class="sl-header">

        <!-- Breadcrumb -->
        <nav class="guide-breadcrumb" aria-label="Breadcrumb">
            <a href="dashboard.php" class="guide-breadcrumb__link">
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                Dashboard
            </a>
            <span class="guide-breadcrumb__sep" aria-hidden="true">/</span>
            <span class="guide-breadcrumb__current">My Submissions</span>
        </nav>

        <div class="sl-header__body">
            <div class="sl-header__text">
                <span class="sl-header__eyebrow">Submission History</span>
                <h1 class="sl-header__title">Submission Ledger</h1>
                <p class="sl-header__sub">
                    Your complete upload history,
                    <?= htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8') ?>.
                    Every achievement you've submitted — approved, in review, or rejected.
                </p>
            </div>
            <div class="sl-header__action">
                <a href="upload_proof.php" class="btn-upload-hero" aria-label="Upload a new achievement proof">
                    <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
                    <span>Upload Achievement</span>
                </a>
            </div>
        </div>

    </header>


    <!-- =========================================================================
         STATUS STRIP — Summary counts + clickable filter pills
         Four pills: All / Approved / Pending / Rejected.
         Clicking a pill filters the ledger cards via JS (data-status attribute).
         The count badges update dynamically from the PHP-rendered totals.
    ========================================================================= -->
    <div class="sl-strip" role="group" aria-label="Filter submissions by status">

        <button
            class="sl-pill sl-pill--active"
            data-filter="all"
            aria-pressed="true"
            aria-label="Show all submissions (<?= $counts['total'] ?> total)"
        >
            <span class="sl-pill__label">All</span>
            <span class="sl-pill__count"><?= $counts['total'] ?></span>
        </button>

        <button
            class="sl-pill sl-pill--approved"
            data-filter="approved"
            aria-pressed="false"
            aria-label="Show approved submissions (<?= $counts['approved'] ?> approved)"
        >
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span class="sl-pill__label">Approved</span>
            <span class="sl-pill__count"><?= $counts['approved'] ?></span>
        </button>

        <button
            class="sl-pill sl-pill--pending"
            data-filter="pending"
            aria-pressed="false"
            aria-label="Show pending submissions (<?= $counts['pending'] ?> pending)"
        >
            <i class="bi bi-clock-fill" aria-hidden="true"></i>
            <span class="sl-pill__label">Pending</span>
            <span class="sl-pill__count"><?= $counts['pending'] ?></span>
        </button>

        <button
            class="sl-pill sl-pill--rejected"
            data-filter="rejected"
            aria-pressed="false"
            aria-label="Show rejected submissions (<?= $counts['rejected'] ?> rejected)"
        >
            <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
            <span class="sl-pill__label">Rejected</span>
            <span class="sl-pill__count"><?= $counts['rejected'] ?></span>
        </button>

    </div><!-- /sl-strip -->


    <!-- =========================================================================
         LEDGER
         One .ledger-card per submission. Cards carry data-status for JS filtering.
         Desktop: single flex row — [icon] [details flex:1] [date] [badge] [points].
         Mobile (<768px): two-row stack — [icon+title] on top, [date+badge+pts] below.
         Rejected cards gain an .ledger-card__rejection panel at the bottom.
    ========================================================================= -->
    <div class="ledger" id="ledgerList" role="list" aria-label="Submission ledger">

    <?php if (empty($submissions)): ?>

        <!-- EMPTY STATE -->
        <div class="sl-empty" role="region" aria-label="No submissions yet">
            <div class="sl-empty__icon" aria-hidden="true">
                <i class="bi bi-inbox"></i>
            </div>
            <h2 class="sl-empty__title">Nothing here yet</h2>
            <p class="sl-empty__body">
                You haven't submitted any achievements. Click
                <strong>Upload Achievement</strong> to get started.
            </p>
        </div>

    <?php else: ?>

        <?php foreach ($submissions as $sub):
            // XSS-safe values
            $e_title  = htmlspecialchars($sub['title'],            ENT_QUOTES, 'UTF-8');
            $e_cat    = htmlspecialchars($sub['category'],         ENT_QUOTES, 'UTF-8');
            $e_tier   = htmlspecialchars($sub['tier'],             ENT_QUOTES, 'UTF-8');
            $e_status = htmlspecialchars($sub['status'],           ENT_QUOTES, 'UTF-8');
            $e_reason = htmlspecialchars($sub['rejection_reason'] ?? '', ENT_QUOTES, 'UTF-8');
            $e_date   = htmlspecialchars(
                            date('d M Y', strtotime($sub['submitted_at'])),
                            ENT_QUOTES, 'UTF-8'
                        );

            // Icon resolution — fall back to 'default' for unmapped categories
            $icon_data = $category_icons[$sub['category']] ?? $category_icons['default'];

            // Badge resolution — fall back to 'pending' for any unmapped status
            $badge = $badge_map[$sub['status']] ?? $badge_map['pending'];

            // Points display
            $pts_display = match($sub['status']) {
                'approved' => '+' . number_format((int) $sub['points_awarded']),
                'pending'  => '—',
                default    => '0',
            };
            $pts_class = match($sub['status']) {
                'approved' => 'lc-pts lc-pts--approved',
                'pending'  => 'lc-pts lc-pts--pending',
                default    => 'lc-pts lc-pts--rejected',
            };
        ?>
        <div
            class="ledger-card ledger-card--<?= $e_status ?>"
            role="listitem"
            data-status="<?= $e_status ?>"
            aria-label="<?= $e_title ?>, <?= $e_status ?>, <?= $pts_display ?> points"
        >
            <!-- ── Main card body (icon + details + date + badge + points) ── -->
            <div class="ledger-card__body">

                <!-- Category icon circle -->
                <div class="lc-icon <?= htmlspecialchars($icon_data['mod'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
                    <i class="bi <?= htmlspecialchars($icon_data['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </div>

                <!-- Title + tier/category -->
                <div class="lc-details">
                    <span class="lc-title"><?= $e_title ?></span>
                    <span class="lc-meta">
                        <?= $e_cat ?>
                        <?php if ($e_tier): ?>
                        <span class="lc-meta__sep" aria-hidden="true">&middot;</span>
                        <?= $e_tier ?>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Date — hidden on mobile, shown in the footer row instead -->
                <span class="lc-date" aria-label="Submitted <?= $e_date ?>">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <?= $e_date ?>
                </span>

                <!-- Status badge -->
                <span class="status-badge <?= $badge['class'] ?>" role="status">
                    <i class="bi <?= $badge['icon'] ?>" aria-hidden="true"></i>
                    <span><?= $badge['label'] ?></span>
                </span>

                <!-- Points -->
                <span class="<?= $pts_class ?>" aria-label="Points: <?= $pts_display ?>">
                    <?= $pts_display ?>
                    <?php if ($sub['status'] === 'approved'): ?>
                    <span class="lc-pts__label">pts</span>
                    <?php endif; ?>
                </span>

            </div><!-- /ledger-card__body -->

            <!-- ── Mobile footer bar: date + badge + points in a tight row ── -->
            <!-- Hidden on ≥768px via CSS; the main body columns handle desktop. -->
            <div class="ledger-card__mobile-footer" aria-hidden="true">
                <span class="lc-date lc-date--mobile">
                    <i class="bi bi-calendar3"></i>
                    <?= $e_date ?>
                </span>
                <span class="status-badge <?= $badge['class'] ?>">
                    <i class="bi <?= $badge['icon'] ?>"></i>
                    <span><?= $badge['label'] ?></span>
                </span>
                <span class="<?= $pts_class ?>">
                    <?= $pts_display ?>
                    <?php if ($sub['status'] === 'approved'): ?>
                    <span class="lc-pts__label">pts</span>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($sub['status'] === 'rejected' && !empty($e_reason)): ?>
            <!-- ── Rejection explainer panel ─────────────────────────────── -->
            <!-- Only rendered when status = rejected AND reason is non-empty. -->
            <div class="ledger-card__rejection" role="alert" aria-label="Rejection reason">
                <i class="bi bi-info-circle-fill lc-rejection__icon" aria-hidden="true"></i>
                <p class="lc-rejection__text"><?= $e_reason ?></p>
            </div>
            <?php endif; ?>

        </div><!-- /ledger-card -->
        <?php endforeach; ?>

        <!-- JS-driven "no results" message shown when filter pill yields zero visible cards -->
        <div class="sl-no-results" id="slNoResults" hidden role="status" aria-live="polite">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            No submissions match this filter.
        </div>

    <?php endif; ?>

    </div><!-- /ledger -->

</div><!-- /sl-wrapper -->
</main>


<script>
(function () {
    'use strict';

    // ── STATUS STRIP FILTER ──────────────────────────────────────────────────
    // Clicking a pill hides all cards whose data-status doesn't match.
    // "all" shows every card. aria-pressed tracks the active state.
    // No page reload — pure DOM toggling for instant feedback.

    var pills     = document.querySelectorAll('.sl-pill');
    var cards     = document.querySelectorAll('#ledgerList .ledger-card');
    var noResults = document.getElementById('slNoResults');

    function applyFilter(filter) {
        var visible = 0;

        cards.forEach(function (card) {
            var show = (filter === 'all' || card.dataset.status === filter);
            card.hidden = !show;
            if (show) visible++;
        });

        // Toggle the "no results" message
        if (noResults) {
            noResults.hidden = (visible > 0 || cards.length === 0);
        }

        // Update pill active states + aria-pressed
        pills.forEach(function (pill) {
            var isActive = (pill.dataset.filter === filter);
            pill.classList.toggle('sl-pill--active', isActive);
            pill.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            applyFilter(pill.dataset.filter);
        });
    });

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>