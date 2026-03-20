<?php
// =============================================================================
// THE ZENITH VIEW — /student/points_guide.php
// Protected: Student Role Only | Scoring Matrix & Evaluation Criteria Reference
//
// SECURITY:
//   [x] RBAC gate — only role='student' may access
//   [x] No DB writes on this page — read-only reference document
//   [x] Scoring matrix defined in PHP, not user-supplied — no sanitisation needed
//
// BLUEPRINT REFERENCES:
//   §1  — File lives in /student/ directory
//   §3  — Scoring matrix is the authoritative source for point values
//   §9B — Student feature scope
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

// --- RBAC Gate ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}

// =============================================================================
// SCORING MATRIX — Blueprint §3 single source of truth.
// Identical structure to upload_proof.php so both pages stay in sync.
// Each category maps to: tier => [points, note]
// 'note' drives the per-card best-practice callout.
// =============================================================================

$categories = [

    'Technical Events' => [
        'icon'  => 'bi-code-slash',
        'sub'   => 'Hackathons, Ideathons, Coding Competitions',
        'tiers' => [
            '1st Place'         => 200,
            '2nd Place'         => 150,
            '3rd Place'         => 100,
            'Finalist / Top 10' => 50,
            'Participation'     => 20,
        ],
        'note'  => 'Certificate must clearly state the event name, organising institution, date, and your rank or participation status. Screenshots of online rankings are not accepted.',
    ],

    'Research & Publications' => [
        'icon'  => 'bi-journal-text',
        'sub'   => 'Patents, Papers, Certifications',
        'tiers' => [
            'Patent Filed / Published' => 400,
            'Published Paper'          => 300,
            'Official Certification'   => 50,
        ],
        'note'  => 'Published papers must appear in UGC-CARE, Scopus, IEEE, or equivalent peer-reviewed journals. Certification maximum is capped at 200 pts per student regardless of count.',
    ],

    'Extra-Curriculars & Sports' => [
        'icon'  => 'bi-trophy',
        'sub'   => 'University, State & College Level Events',
        'tiers' => [
            'University / State Level Winner' => 150,
            'College Level Winner'            => 75,
        ],
        'note'  => 'Proof must be on official institutional letterhead or carry a stamp/seal from the organising body. Inter-department events qualify at College Level only.',
    ],

    'Positions of Responsibility' => [
        'icon'  => 'bi-people',
        'sub'   => 'Leadership, Council & Committee Roles',
        'tiers' => [
            'Student Council / Club President' => 150,
            'Committee Member / Volunteer'     => 50,
        ],
        'note'  => 'Submit your official appointment letter or a signed declaration from the faculty advisor. Roles must be held for a minimum of one academic semester.',
    ],

];

// Academic points formula (Blueprint §3)
// base_cgpa × 100 — calculated automatically from the users table, not submitted.

// =============================================================================
// PAGE META
// =============================================================================

$page_title = 'Points Guide';
$active_nav = 'dashboard';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/points.css">';

require_once __DIR__ . '/../includes/header.php';

$base = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');
?>

<main class="page-main" id="main-content">
<div class="guide-wrapper">

    <!-- =========================================================================
         PAGE HEADER
         Breadcrumb trail + title + descriptive lead paragraph
    ========================================================================= -->
    <header class="guide-header">

        <nav class="guide-breadcrumb" aria-label="Breadcrumb">
            <a href="dashboard.php" class="guide-breadcrumb__link">
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                Dashboard
            </a>
            <span class="guide-breadcrumb__sep" aria-hidden="true">/</span>
            <span class="guide-breadcrumb__current">Points Guide</span>
        </nav>

        <h1 class="guide-header__title">Scoring Reference</h1>
        <p class="guide-header__lead">
            A complete reference for how achievement points are calculated and awarded
            at KJSCE. All values are fixed by the academic committee and applied
            automatically upon faculty approval.
        </p>

        <!-- Quick nav anchors for long-page scanning -->
        <div class="guide-toc" role="navigation" aria-label="Page sections">
            <span class="guide-toc__label">Jump to:</span>
            <a href="#criteria"  class="guide-toc__link">Evaluation Criteria</a>
            <a href="#academic"  class="guide-toc__link">Academic Score</a>
            <a href="#matrix"    class="guide-toc__link">Scoring Matrix</a>
            <a href="#practices" class="guide-toc__link">Submission Rules</a>
        </div>

    </header>


    <!-- =========================================================================
         SECTION 1 — EVALUATION CRITERIA
         Three bento cards explaining the three pillars of the total score.
    ========================================================================= -->
    <section id="criteria" class="guide-section" aria-label="Evaluation criteria">

        <div class="guide-section__head">
            <h2 class="guide-section__title">Evaluation Criteria</h2>
            <p class="guide-section__sub">
                Your total score is a composite of three independent components,
                each measuring a distinct dimension of academic achievement.
            </p>
        </div>

        <div class="criteria-grid">

            <div class="criteria-card">
                <div class="criteria-card__icon" aria-hidden="true">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <h3 class="criteria-card__title">Academic Performance</h3>
                <p class="criteria-card__body">
                    Your CGPA is converted into base points automatically.
                    No submission is required — the system reads your academic
                    record directly.
                </p>
                <div class="criteria-card__formula">
                    <span class="criteria-card__formula-label">Formula</span>
                    <code class="criteria-card__formula-code">CGPA × 100 pts</code>
                </div>
            </div>

            <div class="criteria-card">
                <div class="criteria-card__icon criteria-card__icon--blue" aria-hidden="true">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
                <h3 class="criteria-card__title">Verified Achievements</h3>
                <p class="criteria-card__body">
                    Points from technical events, publications, sports, and leadership
                    roles are added only after a faculty member reviews and approves
                    your submitted proof document.
                </p>
                <div class="criteria-card__formula">
                    <span class="criteria-card__formula-label">Added on approval</span>
                    <code class="criteria-card__formula-code">Per matrix below</code>
                </div>
            </div>

            <div class="criteria-card">
                <div class="criteria-card__icon criteria-card__icon--muted" aria-hidden="true">
                    <i class="bi bi-bar-chart-line-fill"></i>
                </div>
                <h3 class="criteria-card__title">Global Standing</h3>
                <p class="criteria-card__body">
                    Your rank is computed live against all active students.
                    It updates the moment any submission is approved. There is
                    no separate points component — rank reflects your total score.
                </p>
                <div class="criteria-card__formula">
                    <span class="criteria-card__formula-label">Calculation</span>
                    <code class="criteria-card__formula-code">Academic + Achievements</code>
                </div>
            </div>

        </div>

    </section>


    <!-- =========================================================================
         SECTION 2 — ACADEMIC SCORE EXPLAINER
         Single full-width callout card — clean, no table.
    ========================================================================= -->
    <section id="academic" class="guide-section" aria-label="Academic score calculation">

        <div class="guide-section__head">
            <h2 class="guide-section__title">Academic Score</h2>
            <p class="guide-section__sub">Auto-calculated — no action required from you.</p>
        </div>

        <div class="academic-card">
            <div class="academic-card__body">
                <p class="academic-card__text">
                    Your registered CGPA is multiplied by 100 to produce your academic base score.
                    This component is always included in your total and is updated each semester
                    when the academic office updates the student records.
                </p>
                <div class="academic-examples">
                    <div class="academic-example">
                        <span class="academic-example__cgpa">9.50 CGPA</span>
                        <span class="academic-example__arrow" aria-hidden="true">→</span>
                        <span class="academic-example__pts">950 pts</span>
                    </div>
                    <div class="academic-example">
                        <span class="academic-example__cgpa">8.20 CGPA</span>
                        <span class="academic-example__arrow" aria-hidden="true">→</span>
                        <span class="academic-example__pts">820 pts</span>
                    </div>
                    <div class="academic-example">
                        <span class="academic-example__cgpa">7.75 CGPA</span>
                        <span class="academic-example__arrow" aria-hidden="true">→</span>
                        <span class="academic-example__pts">775 pts</span>
                    </div>
                </div>
            </div>
            <div class="academic-card__note" role="note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>If your CGPA appears incorrect on your dashboard, contact the
                academic office — only they can update this figure in the system.</span>
            </div>
        </div>

    </section>


    <!-- =========================================================================
         SECTION 3 — SCORING MATRIX
         One card per category, 2-column on desktop.
         PHP loop drives all content — no duplication.
    ========================================================================= -->
    <section id="matrix" class="guide-section" aria-label="Scoring matrix">

        <div class="guide-section__head">
            <h2 class="guide-section__title">Scoring Matrix</h2>
            <p class="guide-section__sub">
                Fixed point values per category and tier, as defined by the
                KJSCE academic committee. Values are applied automatically on approval.
            </p>
        </div>

        <div class="matrix-grid">

            <?php foreach ($categories as $cat_name => $cat): ?>
            <div class="matrix-card" id="cat-<?= htmlspecialchars(strtolower(str_replace([' ', '&', '/'], ['-', '', ''], $cat_name)), ENT_QUOTES, 'UTF-8') ?>">

                <!-- Card header — matches dash-card__header rhythm exactly -->
                <div class="matrix-card__header">
                    <div class="matrix-card__icon" aria-hidden="true">
                        <i class="bi <?= htmlspecialchars($cat['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </div>
                    <div>
                        <h3 class="matrix-card__title"><?= htmlspecialchars($cat_name, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="matrix-card__sub"><?= htmlspecialchars($cat['sub'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>

                <!-- Tier rows — flexbox list, no table -->
                <ul class="matrix-list" aria-label="<?= htmlspecialchars($cat_name, ENT_QUOTES, 'UTF-8') ?> point values">
                    <?php foreach ($cat['tiers'] as $tier => $pts): ?>
                    <li class="matrix-row">
                        <span class="matrix-row__tier"><?= htmlspecialchars($tier, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="matrix-row__pts" aria-label="<?= (int) $pts ?> points">
                            +<?= number_format($pts) ?> pts
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Best practice callout -->
                <div class="best-practice" role="note" id="practices">
                    <i class="bi bi-info-circle best-practice__icon" aria-hidden="true"></i>
                    <p class="best-practice__text">
                        <?= htmlspecialchars($cat['note'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>

            </div><!-- /matrix-card -->
            <?php endforeach; ?>

        </div><!-- /matrix-grid -->

    </section>


    <!-- =========================================================================
         SECTION 4 — CERTIFICATION CAP NOTICE
         Full-width informational card for the certification cap rule (Blueprint §3)
    ========================================================================= -->
    <section class="guide-section" aria-label="Important limits">

        <div class="cap-notice" role="note">
            <div class="cap-notice__icon" aria-hidden="true">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="cap-notice__body">
                <h3 class="cap-notice__title">Certification Points Cap</h3>
                <p class="cap-notice__text">
                    Official certifications are awarded 50 points each, but the
                    <strong>total certification contribution is capped at 200 points per student</strong>,
                    regardless of the number of certifications submitted. This applies only to the
                    'Official Certification' tier under Research &amp; Publications.
                    All other achievement categories have no cumulative cap.
                </p>
            </div>
        </div>

    </section>


    <!-- =========================================================================
         CTA FOOTER — Upload prompt
    ========================================================================= -->
    <footer class="guide-cta" aria-label="Submit achievement">
        <div class="guide-cta__inner">
            <div class="guide-cta__text">
                <h2 class="guide-cta__title">Ready to submit an achievement?</h2>
                <p class="guide-cta__sub">
                    Upload your certificate or proof document and let faculty verify your work.
                    Points are credited automatically upon approval.
                </p>
            </div>
            <a
                href="<?= $base ?>/student/upload_proof.php"
                class="guide-cta__btn"
                aria-label="Go to achievement submission page"
            >
                <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
                Upload Achievement
            </a>
        </div>
    </footer>

</div><!-- /guide-wrapper -->
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>