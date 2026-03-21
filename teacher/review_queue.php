<?php
// =============================================================================
// THE ZENITH VIEW — /teacher/review_queue.php
// Protected: Teacher Role Only | Faculty Achievement Review Queue
//
// SECURITY CHECKLIST:
//   [x] RBAC gate — only role='teacher' may access
//   [x] All DB writes use PDO prepared statements — no interpolation
//   [x] CSRF token — generated per session, validated on every POST
//   [x] achievement_id and student_id cast to (int) before use
//   [x] points_awarded auto-calculated server-side from scoring matrix — not user input
//   [x] action whitelisted: only 'approve' | 'reject' accepted
//   [x] XSS — every echo of user data through htmlspecialchars()
//   [x] Department filter — teacher only sees their own dept submissions
//   [x] PRG pattern — session flash prevents duplicate submissions on refresh
//
// DB SCHEMA (from zenith_view_db.sql):
//   achievements: id, student_id, category, tier, title, proof_file_path,
//                 points_awarded, status, rejection_reason, reviewed_by,
//                 submitted_at
//   users:        id, name, department, study_year, total_points, ...
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP & RBAC
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

// Teacher guard — redirect anyone else immediately
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../login.php');
    exit;
}

$teacher_id   = (int) $_SESSION['user_id'];
$teacher_dept = $_SESSION['department'] ?? ''; // e.g. 'Comps', 'IT', etc.


// =============================================================================
// PHASE 2 — CSRF BOOTSTRAP
// =============================================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// =============================================================================
// PHASE 3 — POST HANDLER (PRG pattern)
// Processes approve / reject actions. Writes a flash message to $_SESSION
// then redirects back to this page — prevents duplicate submission on F5.
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── 3a. CSRF validation ──────────────────────────────────────────────────
    $submitted_token = trim($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Security token mismatch. Please try again.'];
        header('Location: review_queue.php');
        exit;
    }

    // ── 3b. Input capture & whitelist ───────────────────────────────────────
    $action         = trim($_POST['action']         ?? '');
    $achievement_id = (int) ($_POST['achievement_id'] ?? 0);
    $student_id     = (int) ($_POST['student_id']     ?? 0);
    // Note: points are no longer submitted by the form — auto-calculated
    // server-side from the scoring matrix in §3d using category + tier.
    $remark         = trim($_POST['rejection_reason'] ?? '');

    // Whitelist action
    if (!in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid action.'];
        header('Location: review_queue.php');
        exit;
    }

    // Validate IDs
    if ($achievement_id <= 0 || $student_id <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid submission reference.'];
        header('Location: review_queue.php');
        exit;
    }

    // ── 3c. Ownership check — confirm this achievement belongs to a student in
    //        the teacher's department before allowing any write.
    try {
        $check = $pdo->prepare("
            SELECT a.id
            FROM   achievements a
            JOIN   users u ON u.id = a.student_id
            WHERE  a.id         = :aid
              AND  a.student_id = :sid
              AND  a.status     = 'pending'
              AND  u.department = :dept
            LIMIT  1
        ");
        $check->execute([
            ':aid'  => $achievement_id,
            ':sid'  => $student_id,
            ':dept' => $teacher_dept,
        ]);
        if (!$check->fetch()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Submission not found or already processed.'];
            header('Location: review_queue.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log('[Zenith View] review_queue ownership check: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A database error occurred. Please try again.'];
        header('Location: review_queue.php');
        exit;
    }

    // ── 3d. Execute approve / reject ─────────────────────────────────────────
    if ($action === 'approve') {

        // ── Scoring matrix — matches Blueprint §3 and points_guide.php exactly.
        // Keys are exact strings stored in achievements.category and .tier.
        $scoring_matrix = [
            'Technical Events' => [
                '1st Place'        => 200,
                '2nd Place'        => 150,
                '3rd Place'        => 100,
                'Finalist / Top 10'=> 50,
                'Participation'    => 20,
            ],
            'Research & Technical' => [
                'Patent Filed / Published' => 400,
                'Published Paper'          => 300,
                'Certification'            => 50,
            ],
            'Extra-Curriculars & Sports' => [
                'University / State Winner' => 150,
                'College Winner'            => 75,
            ],
            'Positions of Responsibility' => [
                'President / Core'   => 150,
                'Member / Volunteer' => 50,
            ],
        ];

        // Re-fetch category and tier for this achievement (they were validated
        // in the ownership check above but not stored in a variable).
        $pts_row = null;
        try {
            $pts_stmt = $pdo->prepare("
                SELECT category, tier FROM achievements WHERE id = :aid LIMIT 1
            ");
            $pts_stmt->execute([':aid' => $achievement_id]);
            $pts_row = $pts_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[Zenith View] review_queue pts fetch: ' . $e->getMessage());
        }

        $cat            = $pts_row['category'] ?? '';
        $tier           = $pts_row['tier']     ?? '';
        $points_awarded = (int) ($scoring_matrix[$cat][$tier] ?? 0);

        try {
            $pdo->beginTransaction();

            // Mark achievement approved
            $stmt = $pdo->prepare("
                UPDATE achievements
                SET    status         = 'approved',
                       points_awarded = :pts,
                       reviewed_by   = :reviewer
                WHERE  id             = :aid
            ");
            $stmt->execute([
                ':pts'      => $points_awarded,
                ':reviewer' => $teacher_id,
                ':aid'      => $achievement_id,
            ]);

            // Increment student's total_points atomically
            $stmt = $pdo->prepare("
                UPDATE users
                SET    total_points = total_points + :pts
                WHERE  id           = :sid
            ");
            $stmt->execute([
                ':pts' => $points_awarded,
                ':sid' => $student_id,
            ]);

            $pdo->commit();

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => "Achievement approved and {$points_awarded} points awarded successfully.",
            ];

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[Zenith View] review_queue approve: ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Database error during approval. Please try again.'];
        }

    } else { // 'reject'

        // Remark is required on rejection
        if ($remark === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A rejection reason is required.'];
            header('Location: review_queue.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE achievements
                SET    status           = 'rejected',
                       points_awarded   = 0,
                       rejection_reason = :reason,
                       reviewed_by      = :reviewer
                WHERE  id               = :aid
            ");
            $stmt->execute([
                ':reason'   => $remark,
                ':reviewer' => $teacher_id,
                ':aid'      => $achievement_id,
            ]);

            $_SESSION['flash'] = [
                'type' => 'warning',
                'msg'  => 'Submission rejected and student notified.',
            ];

        } catch (PDOException $e) {
            error_log('[Zenith View] review_queue reject: ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Database error during rejection. Please try again.'];
        }
    }

    // Regenerate CSRF after every successful POST
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // PRG — redirect to prevent re-submission on F5
    header('Location: review_queue.php');
    exit;
}


// =============================================================================
// PHASE 4 — DATA FETCH
// Pending submissions in the teacher's department, newest first.
// =============================================================================

$queue = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id              AS achievement_id,
            a.title,
            a.category,
            a.tier,
            a.proof_file_path,
            a.submitted_at,
            u.id              AS student_id,
            u.name            AS student_name,
            u.department,
            u.study_year
        FROM  achievements a
        JOIN  users u ON u.id = a.student_id
        WHERE a.status     = 'pending'
          AND u.department = :dept
          AND u.is_active  = 1
        ORDER BY a.submitted_at ASC
    ");
    $stmt->execute([':dept' => $teacher_dept]);
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Zenith View] review_queue fetch: ' . $e->getMessage());
}

$pending_count = count($queue);


// =============================================================================
// PHASE 5 — FLASH MESSAGE & PAGE META
// =============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── numberToWord() — converts 1–20 to English words for natural prose.
// Numbers outside this range fall back to the numeral string.
function numberToWord(int $n): string {
    $words = [
        1  => 'One',   2  => 'Two',    3  => 'Three', 4  => 'Four',
        5  => 'Five',  6  => 'Six',    7  => 'Seven', 8  => 'Eight',
        9  => 'Nine',  10 => 'Ten',    11 => 'Eleven',12 => 'Twelve',
        13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen',  17 => 'Seventeen',18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty',
    ];
    return $words[$n] ?? (string) $n;
}

$page_title = 'Review Queue';
$active_nav = 'review_queue';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/review_queue.css">';

require_once __DIR__ . '/../includes/header.php';

$e_csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
$base   = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');

// Scoring matrix for display badges — mirrors the POST handler matrix exactly.
$display_matrix = [
    'Technical Events' => [
        '1st Place'         => 200, '2nd Place'  => 150, '3rd Place' => 100,
        'Finalist / Top 10' => 50,  'Participation' => 20,
    ],
    'Research & Technical' => [
        'Patent Filed / Published' => 400,
        'Published Paper'          => 300,
        'Certification'            => 50,
    ],
    'Extra-Curriculars & Sports' => [
        'University / State Winner' => 150,
        'College Winner'            => 75,
    ],
    'Positions of Responsibility' => [
        'President / Core'   => 150,
        'Member / Volunteer' => 50,
    ],
];
?>

<main class="page-main" id="main-content">
<div class="rq-wrapper">


    <!-- =========================================================================
         FLASH MESSAGE
    ========================================================================= -->
    <?php if ($flash): ?>
    <?php
        $alert_class = match($flash['type']) {
            'success' => 'rq-alert--success',
            'warning' => 'rq-alert--warning',
            default   => 'rq-alert--error',
        };
        $alert_icon = match($flash['type']) {
            'success' => 'bi-check-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            default   => 'bi-x-circle-fill',
        };
    ?>
    <div class="rq-alert <?= $alert_class ?>" role="alert" id="flashAlert">
        <i class="bi <?= $alert_icon ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></span>
        <button type="button" class="rq-alert__dismiss" onclick="this.parentElement.remove()"
                aria-label="Dismiss">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <?php endif; ?>


    <!-- =========================================================================
         PAGE HEADER
    ========================================================================= -->
    <header class="rq-header">
        <div class="rq-header__text">
            <p class="rq-header__eyebrow">Faculty Portal</p>
            <h1 class="rq-header__title">Review Queue</h1>
            <p class="rq-header__sub">
                <?php if ($pending_count === 0): ?>
                    All submissions reviewed — queue is clear.
                <?php else: ?>
                    <span class="rq-header__count"><?= numberToWord($pending_count) ?></span>
                    pending submission<?= $pending_count !== 1 ? 's' : '' ?>
                    awaiting your review in
                    <strong><?= htmlspecialchars($teacher_dept, ENT_QUOTES, 'UTF-8') ?></strong>.
                <?php endif; ?>
            </p>
        </div>
        <div class="rq-header__stat">
            <div class="rq-stat-pill">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <span class="rq-stat-pill__value"><?= $pending_count ?></span>
                <span class="rq-stat-pill__label">Pending</span>
            </div>
        </div>
    </header>


    <?php if ($pending_count === 0): ?>
    <!-- =========================================================================
         EMPTY STATE
    ========================================================================= -->
    <div class="rq-empty">
        <div class="rq-empty__icon" aria-hidden="true"><i class="bi bi-inbox-fill"></i></div>
        <h2 class="rq-empty__title">You're all caught up!</h2>
        <p class="rq-empty__body">
            There are no pending submissions for the
            <strong><?= htmlspecialchars($teacher_dept, ENT_QUOTES, 'UTF-8') ?></strong>
            department right now. Check back later.
        </p>
    </div>

    <?php else: ?>
    <!-- =========================================================================
         REVIEW QUEUE GRID
         1 col mobile → 2 col ≥992px → 3 col ≥1200px
    ========================================================================= -->
    <div class="row g-3 row-cols-1 row-cols-lg-2 row-cols-xl-3 align-items-start" id="reviewGrid">

    <?php foreach ($queue as $item):
        $ach_id    = (int) $item['achievement_id'];
        $s_id      = (int) $item['student_id'];
        $e_name    = htmlspecialchars($item['student_name'],   ENT_QUOTES, 'UTF-8');
        $e_dept    = htmlspecialchars($item['department'],      ENT_QUOTES, 'UTF-8');
        $e_year    = htmlspecialchars($item['study_year'],      ENT_QUOTES, 'UTF-8');
        $e_title   = htmlspecialchars($item['title'],           ENT_QUOTES, 'UTF-8');
        $e_cat     = htmlspecialchars($item['category'],        ENT_QUOTES, 'UTF-8');
        $e_tier    = htmlspecialchars($item['tier'],            ENT_QUOTES, 'UTF-8');
        $e_proof   = htmlspecialchars($item['proof_file_path'], ENT_QUOTES, 'UTF-8');
        $proof_url = $e_proof ? $base . '/' . $e_proof : '';
        $e_date    = htmlspecialchars(date('d M Y', strtotime($item['submitted_at'])), ENT_QUOTES, 'UTF-8');
        $file_ext  = strtolower(pathinfo($item['proof_file_path'], PATHINFO_EXTENSION));
        $is_image  = in_array($file_ext, ['jpg', 'jpeg', 'png'], true);
        // Auto-calculated points for this submission (shown as badge, also used server-side)
        $auto_pts  = (int) ($display_matrix[$item['category']][$item['tier']] ?? 0);
        $e_pts     = $auto_pts > 0 ? '+' . $auto_pts . ' pts' : 'Unmatched tier';
        // Unique ID for the reject collapse panel — scoped per card
        $id_reject  = 'rejectPanel_'  . $ach_id;
    ?>
    <div class="col">
        <div class="zv-card" data-ach-id="<?= $ach_id ?>">

            <!-- ── Card Header: student identity ─────────────────────────── -->
            <div class="zv-card__header">
                <div class="zv-card__identity">
                    <div class="zv-card__avatar" aria-hidden="true">
                        <?= strtoupper(mb_substr($item['student_name'], 0, 1)) ?>
                    </div>
                    <div class="zv-card__student-info">
                        <span class="zv-card__student-name"><?= $e_name ?></span>
                        <span class="zv-card__student-meta">
                            <span class="dept-badge"><?= $e_dept ?></span>
                            <span class="year-chip"><?= $e_year ?></span>
                        </span>
                    </div>
                </div>
                <span class="zv-card__date" title="Submitted on <?= $e_date ?>">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <?= $e_date ?>
                </span>
            </div>

            <!-- ── Card Body: achievement details ────────────────────────── -->
            <div class="zv-card__body">
                <h2 class="zv-card__achievement-title"><?= $e_title ?></h2>
                <div class="zv-card__achievement-meta">
                    <span class="zv-card__category">
                        <i class="bi bi-tag-fill" aria-hidden="true"></i><?= $e_cat ?>
                    </span>
                    <span class="zv-card__tier">
                        <i class="bi bi-bar-chart-steps" aria-hidden="true"></i><?= $e_tier ?>
                    </span>
                </div>

                <?php if ($proof_url): ?>
                <button
                    type="button"
                    class="btn-view-proof"
                    data-proof-url="<?= $proof_url ?>"
                    data-proof-type="<?= $is_image ? 'image' : 'pdf' ?>"
                    data-proof-title="<?= $e_title ?>"
                    data-bs-toggle="modal"
                    data-bs-target="#proofModal"
                    aria-label="View attached proof for <?= $e_title ?>"
                >
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    View Attached Proof
                </button>
                <?php else: ?>
                <span class="btn-view-proof btn-view-proof--none" aria-label="No file attached">
                    <i class="bi bi-slash-circle" aria-hidden="true"></i>
                    No File Attached
                </span>
                <?php endif; ?>
            </div>

            <!-- ── Card Actions ───────────────────────────────────────────── -->
            <div class="zv-card__actions">

                <!--
                    ACTION ROW — always visible.
                    Approve: inline submit form, 1-click, no accordion.
                    Reject:  toggles the collapse panel below via Bootstrap.
                    Both share a hidden-field wrapper for the approve form;
                    the reject form lives inside its own collapse panel.
                -->
                <div class="action-triggers" role="group" aria-label="Review actions">

                    <!-- 1-CLICK APPROVE FORM — submits instantly on click -->
                    <form method="POST" action="review_queue.php"
                          class="action-approve-form" novalidate>
                        <input type="hidden" name="csrf_token"     value="<?= $e_csrf ?>">
                        <input type="hidden" name="achievement_id" value="<?= $ach_id ?>">
                        <input type="hidden" name="student_id"     value="<?= $s_id ?>">
                        <button type="submit" name="action" value="approve"
                                class="btn-trigger btn-trigger--approve">
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                            Approve (<?= htmlspecialchars($e_pts, ENT_QUOTES, 'UTF-8') ?>)
                        </button>
                    </form>

                    <!-- REJECT TOGGLE — expands the collapse panel below -->
                    <button
                        type="button"
                        class="btn-trigger btn-trigger--reject"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= $id_reject ?>"
                        aria-expanded="false"
                        aria-controls="<?= $id_reject ?>"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        Reject
                    </button>
                </div>

                <!-- ── REJECT COLLAPSE PANEL ───────────────────────────────── -->
                <div class="collapse action-panel" id="<?= $id_reject ?>">
                    <form method="POST" action="review_queue.php" novalidate>
                        <input type="hidden" name="csrf_token"     value="<?= $e_csrf ?>">
                        <input type="hidden" name="achievement_id" value="<?= $ach_id ?>">
                        <input type="hidden" name="student_id"     value="<?= $s_id ?>">

                        <label class="action-label action-label--reject" for="reason_<?= $ach_id ?>">
                            <i class="bi bi-x-octagon-fill" aria-hidden="true"></i>
                            Rejection Reason
                        </label>

                        <div class="quick-chips" role="group" aria-label="Quick rejection reasons">
                            <button type="button" class="quick-chip"
                                data-reason="The uploaded document is blurry or illegible. Please re-upload a clear scan or photo."
                                data-target="reason_<?= $ach_id ?>">
                                <i class="bi bi-image" aria-hidden="true"></i>Blurry Document
                            </button>
                            <button type="button" class="quick-chip"
                                data-reason="The selected tier does not match the achievement level demonstrated in the proof."
                                data-target="reason_<?= $ach_id ?>">
                                <i class="bi bi-diagram-3" aria-hidden="true"></i>Invalid Tier
                            </button>
                            <button type="button" class="quick-chip"
                                data-reason="The document is missing an official signature, seal, or authorisation from the issuing body."
                                data-target="reason_<?= $ach_id ?>">
                                <i class="bi bi-pen" aria-hidden="true"></i>Missing Signature
                            </button>
                        </div>

                        <textarea
                            id="reason_<?= $ach_id ?>"
                            name="rejection_reason"
                            class="reject-textarea"
                            rows="3"
                            placeholder="Write a specific, helpful rejection reason for the student…"
                            aria-label="Rejection reason (required when rejecting)"
                            maxlength="500"
                        ></textarea>

                        <button type="submit" name="action" value="reject" class="btn-reject">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                            Reject Submission
                        </button>
                    </form>
                </div>

            </div><!-- /zv-card__actions -->
        </div><!-- /zv-card -->
    </div><!-- /col -->
    <?php endforeach; ?>

    </div><!-- /row -->
    <?php endif; ?>

</div><!-- /rq-wrapper -->
</main>


<!-- =============================================================================
     PROOF LIGHTBOX MODAL
     modal-dialog-centered + modal-dialog-scrollable: Bootstrap handles scroll
     containment inside the modal — the page behind NEVER scrolls.
     data-bs-backdrop="static" prevents accidental dismiss on backdrop click.
============================================================================= -->
<div class="modal fade" id="proofModal" tabindex="-1"
     aria-labelledby="proofModalLabel" aria-modal="true" role="dialog"
     data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rq-modal-content">

            <div class="modal-header rq-modal-header">
                <div>
                    <h5 class="modal-title rq-modal-title" id="proofModalLabel">
                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                        Proof Document
                    </h5>
                    <p class="rq-modal-subtitle" id="proofModalSubtitle"></p>
                </div>
                <div class="rq-modal-header-actions">
                    <a href="#" id="proofOpenNewTab" target="_blank" rel="noopener noreferrer"
                       class="btn-open-tab" aria-label="Open proof in new tab" title="Open in new tab">
                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    </a>
                    <button type="button" class="btn-modal-close" data-bs-dismiss="modal" aria-label="Close">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <!--
                rq-modal-body: height:75vh; display:flex; flex-direction:column; padding:0
                The iframe gets flex:1 so it fills this container exactly.
                Bootstrap's modal-dialog-scrollable handles overflow — page never scrolls.
            -->
            <div class="modal-body rq-modal-body" id="proofModalBody">
                <div class="rq-modal-loader" id="proofLoader">
                    <div class="rq-spinner" aria-label="Loading proof document…"></div>
                </div>
            </div>

        </div>
    </div>
</div>


<!-- =============================================================================
     INLINE JS
     1. Quick-chip → textarea population.
     2. Proof modal loading: image vs PDF, mobile fallback with brand button.
     3. Flash auto-dismiss after 6 s.
     Note: rqTogglePanel removed — approve is now 1-click inline submit,
     reject accordion has no sibling to close.
============================================================================= -->
<script>
(function () {
    'use strict';


    // ── 1. QUICK-REJECT CHIPS ────────────────────────────────────────────────

    document.querySelectorAll('.quick-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var textarea = document.getElementById(this.getAttribute('data-target'));
            if (!textarea) return;
            textarea.value = this.getAttribute('data-reason');
            textarea.focus();
            var group = this.closest('.quick-chips');
            if (group) {
                group.querySelectorAll('.quick-chip').forEach(function (c) {
                    c.classList.remove('quick-chip--active');
                });
            }
            this.classList.add('quick-chip--active');
        });
    });


    // ── 2. PROOF LIGHTBOX MODAL ──────────────────────────────────────────────

    var proofModal      = document.getElementById('proofModal');
    var proofModalBody  = document.getElementById('proofModalBody');
    var proofLoader     = document.getElementById('proofLoader');
    var proofSubtitle   = document.getElementById('proofModalSubtitle');
    var proofOpenNewTab = document.getElementById('proofOpenNewTab');

    if (proofModal) {
        proofModal.addEventListener('show.bs.modal', function (event) {
            var btn   = event.relatedTarget;
            if (!btn) return;

            var url   = btn.getAttribute('data-proof-url')   || '';
            var type  = btn.getAttribute('data-proof-type')  || 'pdf';
            var title = btn.getAttribute('data-proof-title') || 'Proof Document';

            if (proofSubtitle)   proofSubtitle.textContent = title;
            if (proofOpenNewTab) proofOpenNewTab.href = url;

            if (proofLoader) proofLoader.style.display = 'flex';
            var old = proofModalBody.querySelector('.rq-proof-media');
            if (old) old.remove();

            if (!url) {
                if (proofLoader) proofLoader.style.display = 'none';
                proofModalBody.insertAdjacentHTML('beforeend',
                    '<p class="rq-modal-no-file">No file attached to this submission.</p>');
                return;
            }

            if (type === 'image') {
                var img = document.createElement('img');
                img.src           = url;
                img.alt           = 'Proof: ' + title;
                img.className     = 'rq-proof-media rq-proof-media--image';
                img.style.cssText = 'width:100%;height:auto;display:block;';
                img.onload  = function () { if (proofLoader) proofLoader.style.display = 'none'; };
                img.onerror = function () { if (proofLoader) proofLoader.style.display = 'none'; };
                proofModalBody.appendChild(img);

            } else {
                // PDF handling — mobile browsers block inline PDF iframes.
                if (window.innerWidth < 768) {
                    // ── Mobile: brand-styled "Open in New Tab" prompt ────────
                    // Uses var(--text-heading) background for the dark brand
                    // aesthetic. No transforms — this button must not jump.
                    if (proofLoader) proofLoader.style.display = 'none';
                    proofModalBody.insertAdjacentHTML('beforeend',
                        '<div class="rq-proof-media rq-mobile-pdf-prompt">' +
                            '<i class="bi bi-file-earmark-pdf rq-mobile-pdf-prompt__icon" aria-hidden="true"></i>' +
                            '<p class="rq-mobile-pdf-prompt__msg">PDF preview is not available on mobile browsers.</p>' +
                            '<a href="' + url + '" target="_blank" rel="noopener noreferrer" ' +
                               'class="rq-mobile-pdf-prompt__btn">' +
                                '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>' +
                                ' Open PDF in New Tab' +
                            '</a>' +
                        '</div>'
                    );
                } else {
                    // ── Desktop: iframe with calc height to prevent scroll ───
                    var iframe = document.createElement('iframe');
                    iframe.src            = url;
                    iframe.title          = 'Proof: ' + title;
                    iframe.className      = 'rq-proof-media rq-proof-media--pdf';
                    iframe.style.cssText  = 'height:calc(100vh - 200px);width:100%;border:none;flex:1;display:block;';
                    iframe.setAttribute('loading', 'lazy');
                    iframe.onload = function () { if (proofLoader) proofLoader.style.display = 'none'; };
                    proofModalBody.appendChild(iframe);
                }
            }
        });

        proofModal.addEventListener('hidden.bs.modal', function () {
            var media  = proofModalBody.querySelector('.rq-proof-media');
            if (media)  media.remove();
            var noFile = proofModalBody.querySelector('.rq-modal-no-file');
            if (noFile) noFile.remove();
            if (proofLoader) proofLoader.style.display = 'flex';
        });
    }


    // ── 3. FLASH AUTO-DISMISS ────────────────────────────────────────────────
    var flash = document.getElementById('flashAlert');
    if (flash) {
        setTimeout(function () {
            flash.style.transition = 'opacity 0.4s ease';
            flash.style.opacity    = '0';
            setTimeout(function () { if (flash.parentNode) flash.remove(); }, 420);
        }, 6000);
    }

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>