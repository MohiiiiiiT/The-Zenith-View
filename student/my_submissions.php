<?php
// =============================================================================
// THE ZENITH VIEW — /student/my_submissions.php
// Protected: Student Role Only | Detailed Submission Ledger
//
// SECURITY CHECKLIST:
//   [x] RBAC gate — only role='student' may access
//   [x] All DB queries use PDO prepared statements with bound parameters
//   [x] XSS — every echo of user data wrapped in htmlspecialchars()
//   [x] proof_file_path echoed through htmlspecialchars() in href attribute
//   [x] No raw GET/POST data reaches SQL
//
// BLUEPRINT REFERENCES:
//   §1  — File lives in /student/ directory
//   §2  — Reads achievements table (all columns) + users table
//   §4  — RBAC, PDO, XSS, htmlspecialchars()
//   §9B — Student feature: "Submission History" — track status of past uploads
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP & RBAC
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}

$student_id = (int) $_SESSION['user_id'];


// =============================================================================
// PHASE 2 — QUERY 1: ALL SUBMISSIONS (ordered newest first)
// Fetches every achievement row for this student, including the rejection
// reason so the UI can surface it in the per-card explainer section.
// Blueprint §2: achievements table schema.
// =============================================================================

$submissions = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            category,
            tier,
            status,
            points_awarded,
            proof_file_path,
            rejection_reason,
            submitted_at
        FROM   achievements
        WHERE  student_id = :sid
        ORDER  BY submitted_at DESC
    ");
    $stmt->execute([':sid' => $student_id]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Zenith View] my_submissions: fetch failed: ' . $e->getMessage());
}


// =============================================================================
// PHASE 3 — QUERY 2: AGGREGATE SUMMARY COUNTS
// Single query with conditional aggregation — one DB round-trip for all four
// counts. Displayed in the filter pills and used to show contextual copy.
// =============================================================================

$summary = ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                             AS total,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
        FROM achievements
        WHERE student_id = :sid
    ");
    $stmt->execute([':sid' => $student_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $summary = [
            'total'    => (int) ($row['total']    ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'pending'  => (int) ($row['pending']  ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }
} catch (PDOException $e) {
    error_log('[Zenith View] my_submissions: summary query failed: ' . $e->getMessage());
}


// =============================================================================
// PHASE 4 — PAGE META
// =============================================================================

$page_title = 'My Submissions';
$active_nav = 'dashboard';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/submissions.css">';

require_once __DIR__ . '/../includes/header.php';

$base = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');

// ── Badge map — maps status string → CSS class + icon + label ────────────────
$badge_map = [
    'approved' => ['class' => 'badge--approved', 'icon' => 'bi-check-circle-fill', 'label' => 'Approved'],
    'pending'  => ['class' => 'badge--pending',  'icon' => 'bi-clock-fill',        'label' => 'Pending'],
    'rejected' => ['class' => 'badge--rejected', 'icon' => 'bi-x-circle-fill',     'label' => 'Rejected'],
];
?>

<main class="page-main" id="main-content">
<div class="sub-wrapper">

    <!-- =========================================================================
         BREADCRUMB + PAGE HEADER
    ========================================================================= -->
    <nav class="guide-breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php" class="guide-breadcrumb__link">
            <i class="bi bi-speedometer2" aria-hidden="true"></i>
            Dashboard
        </a>
        <span class="guide-breadcrumb__sep" aria-hidden="true">/</span>
        <span class="guide-breadcrumb__current" aria-current="page">My Submissions</span>
    </nav>

    <header class="sub-header">
        <div class="sub-header__text">
            <h1 class="sub-header__title">Submission Ledger</h1>
            <p class="sub-header__sub">
                Your complete upload history — track the status of every certificate,
                achievement, and proof document you have submitted for faculty review.
            </p>
        </div>
        <a href="upload_proof.php" class="btn-new-submission" aria-label="Submit a new achievement">
            <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
            New Submission
        </a>
    </header>


    <!-- =========================================================================
         FILTER STRIP — 4 pill buttons (All / Approved / Pending / Rejected)
         Client-side JS filters .ledger-card elements by data-status attribute.
         The active filter is tracked via .filter-pill--active class.
    ========================================================================= -->
    <div class="filter-strip" role="group" aria-label="Filter submissions by status">

        <button
            type="button"
            class="filter-pill filter-pill--active"
            data-filter="all"
            aria-pressed="true"
        >
            All
            <span class="filter-pill__count"><?= $summary['total'] ?></span>
        </button>

        <button
            type="button"
            class="filter-pill filter-pill--approved"
            data-filter="approved"
            aria-pressed="false"
        >
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            Approved
            <span class="filter-pill__count"><?= $summary['approved'] ?></span>
        </button>

        <button
            type="button"
            class="filter-pill filter-pill--pending"
            data-filter="pending"
            aria-pressed="false"
        >
            <i class="bi bi-clock-fill" aria-hidden="true"></i>
            Pending
            <span class="filter-pill__count"><?= $summary['pending'] ?></span>
        </button>

        <button
            type="button"
            class="filter-pill filter-pill--rejected"
            data-filter="rejected"
            aria-pressed="false"
        >
            <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
            Rejected
            <span class="filter-pill__count"><?= $summary['rejected'] ?></span>
        </button>

    </div><!-- /filter-strip -->


    <!-- =========================================================================
         LEDGER LIST — Card-based. No <table>. Pure flexbox.
         Each .ledger-card carries data-status for JS filtering.
    ========================================================================= -->
    <div class="ledger-list" id="ledgerList" role="list"
         aria-label="Submission history">

        <?php if (empty($submissions)): ?>
        <!-- ── Empty state ───────────────────────────────────────────────── -->
        <div class="ledger-empty" role="region" aria-label="No submissions yet">
            <div class="ledger-empty__icon" aria-hidden="true">
                <i class="bi bi-inbox"></i>
            </div>
            <h2 class="ledger-empty__title">No submissions yet</h2>
            <p class="ledger-empty__body">
                You haven't submitted any achievements. Upload your first certificate
                or proof and get on the board!
            </p>
            <a href="upload_proof.php" class="btn-new-submission">
                <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
                Upload First Achievement
            </a>
        </div>

        <?php else: ?>
        <?php foreach ($submissions as $sub):
            $status      = $sub['status'] ?? 'pending';
            $badge       = $badge_map[$status] ?? $badge_map['pending'];
            $e_title     = htmlspecialchars($sub['title'],    ENT_QUOTES, 'UTF-8');
            $e_category  = htmlspecialchars($sub['category'], ENT_QUOTES, 'UTF-8');
            $e_tier      = htmlspecialchars($sub['tier'],     ENT_QUOTES, 'UTF-8');
            $e_status    = htmlspecialchars($status,          ENT_QUOTES, 'UTF-8');
            $e_proof     = htmlspecialchars($sub['proof_file_path'] ?? '', ENT_QUOTES, 'UTF-8');
            $e_reason    = htmlspecialchars($sub['rejection_reason'] ?? '', ENT_QUOTES, 'UTF-8');
            $pts         = (int) $sub['points_awarded'];
            $date_str    = !empty($sub['submitted_at'])
                           ? date('d M Y', strtotime($sub['submitted_at']))
                           : '—';
            $e_date      = htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8');
            $proof_url   = $e_proof ? $base . '/' . $e_proof : '';
        ?>
        <article
            class="ledger-card"
            data-status="<?= $e_status ?>"
            role="listitem"
            aria-label="<?= $e_title ?>, <?= $badge['label'] ?>"
        >
            <!-- ── MAIN ROW ─────────────────────────────────────────────── -->
            <div class="ledger-card__main">

                <!-- 1. Details Group: Title + Tier · Category -->
                <div class="ledger-card__details">
                    <span class="ledger-card__title"><?= $e_title ?></span>
                    <span class="ledger-card__meta">
                        <?= $e_tier ?>
                        <span aria-hidden="true">&middot;</span>
                        <?= $e_category ?>
                    </span>
                </div>

                <!-- 2–5. Meta row: Date, Status, Action, Points -->
                <div class="ledger-card__meta-row">

                    <!-- 2. Date -->
                    <div class="ledger-card__date">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span><?= $e_date ?></span>
                    </div>

                    <!-- 3. Status Badge -->
                    <div class="ledger-card__status">
                        <span class="status-badge <?= $badge['class'] ?>" role="status">
                            <i class="bi <?= $badge['icon'] ?>" aria-hidden="true"></i>
                            <?= $badge['label'] ?>
                        </span>
                    </div>

                    <!-- 4. View Proof Action -->
                    <div class="ledger-card__action">
                        <?php if ($proof_url): ?>
                        <a
                            href="<?= $proof_url ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn-view-proof"
                            aria-label="View proof document for <?= $e_title ?> (opens in new tab)"
                        >
                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                            View Proof
                        </a>
                        <?php else: ?>
                        <span class="btn-view-proof btn-view-proof--disabled"
                              aria-label="No proof file attached">
                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                            No File
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- 5. Points Group -->
                    <div class="ledger-card__points" aria-label="Points">
                        <?php if ($status === 'approved'): ?>
                            <span class="points-value points-value--earned">
                                +<?= number_format($pts) ?>
                                <span class="points-value__unit">pts</span>
                            </span>
                        <?php elseif ($status === 'pending'): ?>
                            <span class="points-value points-value--pending"
                                  title="Points awarded on approval">
                                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                                TBD
                            </span>
                        <?php else: ?>
                            <span class="points-value points-value--nil"
                                  aria-label="No points awarded">
                                0 pts
                            </span>
                        <?php endif; ?>
                    </div>

                </div><!-- /ledger-card__meta-row -->

            </div><!-- /ledger-card__main -->


            <!-- ── REJECTION EXPLAINER (only for rejected status) ────────── -->
            <?php if ($status === 'rejected' && $e_reason !== ''): ?>
            <div class="ledger-card__rejection" role="note"
                 aria-label="Rejection reason for <?= $e_title ?>">
                <i class="bi bi-info-circle-fill ledger-card__rejection-icon"
                   aria-hidden="true"></i>
                <div>
                    <span class="ledger-card__rejection-label">Faculty Remarks:</span>
                    <span class="ledger-card__rejection-text"><?= $e_reason ?></span>
                </div>
            </div>
            <?php elseif ($status === 'rejected'): ?>
            <div class="ledger-card__rejection ledger-card__rejection--no-reason" role="note">
                <i class="bi bi-info-circle-fill ledger-card__rejection-icon"
                   aria-hidden="true"></i>
                <span class="ledger-card__rejection-text">
                    No specific reason was provided. Please contact the helpdesk
                    if you require clarification.
                </span>
            </div>
            <?php endif; ?>

        </article><!-- /ledger-card -->
        <?php endforeach; ?>

        <!-- JS-injected no-results message (shown when a filter has 0 results) -->
        <div class="ledger-no-results" id="ledgerNoResults" hidden
             role="status" aria-live="polite">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <p>No submissions match this filter.</p>
        </div>

        <?php endif; ?>

    </div><!-- /ledger-list -->

</div><!-- /sub-wrapper -->
</main>


<!-- =============================================================================
     INLINE JS — filter strip (vanilla, no dependencies)
     Clicking a pill hides .ledger-card items whose data-status does not match.
     'all' shows every card. aria-pressed tracks active state for accessibility.
============================================================================= -->
<script>
(function () {
    'use strict';

    var pills     = document.querySelectorAll('.filter-strip .filter-pill');
    var cards     = document.querySelectorAll('#ledgerList .ledger-card');
    var noResults = document.getElementById('ledgerNoResults');

    if (!pills.length) return;

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            var filterValue = this.getAttribute('data-filter');

            // ── Update active pill state ─────────────────────────────────────
            pills.forEach(function (p) {
                p.classList.remove('filter-pill--active');
                p.setAttribute('aria-pressed', 'false');
            });
            this.classList.add('filter-pill--active');
            this.setAttribute('aria-pressed', 'true');

            // Shed focus ring after click (mouse users) without breaking
            // keyboard navigation — blur only when triggered by pointer.
            if (this.matches(':focus:not(:focus-visible)')) {
                this.blur();
            }

            // ── Show / hide cards ────────────────────────────────────────────
            // style.display = '' removes the inline override and restores the CSS
            // cascade. .ledger-card is declared display:flex; flex-direction:column
            // in submissions.css, so the card comes back as a flex column —
            // __main and __rejection stack vertically, never side-by-side.
            var visible = 0;
            cards.forEach(function (card) {
                var cardStatus = card.getAttribute('data-status');
                var match = (filterValue === 'all' || cardStatus === filterValue);

                if (match) {
                    card.style.display = '';     /* restore CSS cascade → flex column */
                    visible++;
                } else {
                    card.style.display = 'none';
                }
            });

            // ── No-results message ───────────────────────────────────────────
            if (noResults) {
                noResults.hidden = (visible > 0);
            }
        });
    });

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>