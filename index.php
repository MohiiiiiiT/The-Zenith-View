<?php
// =============================================================================
// THE ZENITH VIEW — index.php
// Public Landing Page: Hero + Leaderboard Preview + Features + CTA
//
// ARCHITECTURE NOTE:
//   - The Announcement/Ticker bar is rendered by includes/header.php.
//     $ticker_html must be built HERE (before the header include) so the
//     header can consume it. Do NOT render a second ticker in the page body.
//   - All CSS lives in /assets/css/style.css
//   - Navbar + <head> = includes/header.php
//   - Footer + JS     = includes/footer.php
// =============================================================================
require_once __DIR__ . '/includes/config.php';

// --- Page meta (consumed by header.php) ------------------------------------
$page_title = 'Home';
$active_nav = 'home';

// ===========================================================================
// DATA FETCH 1 — Live Ticker items
// Built into $ticker_html BEFORE header.php is loaded, because header.php
// renders the announcement bar using this variable.
// ===========================================================================
$ticker_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.name, u.department, a.title, a.points_awarded
        FROM   achievements a
        JOIN   users u ON a.student_id = u.id
        WHERE  a.status = 'approved'
        ORDER  BY a.submitted_at DESC
        LIMIT  15
    ");
    $stmt->execute();
    $ticker_items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Zenith View] Ticker fetch error: ' . $e->getMessage());
}

// Build $ticker_html — duplicated for seamless CSS marquee loop.
$ticker_html = '';
if (!empty($ticker_items)) {
    $single = '';
    foreach ($ticker_items as $item) {
        $name  = htmlspecialchars($item['name'],       ENT_QUOTES, 'UTF-8');
        $dept  = htmlspecialchars($item['department'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($item['title'],      ENT_QUOTES, 'UTF-8');
        $pts   = (int) $item['points_awarded'];
        $single .= '<span class="ticker-item">'
            . '<i class="bi bi-patch-check-fill ticker-icon" aria-hidden="true"></i>'
            . "<strong>{$name}</strong> ({$dept}) &mdash; {$title}"
            . "<span class=\"ticker-badge\">+{$pts} pts</span>"
            . '</span>'
            . '<span class="ticker-separator" aria-hidden="true">&#9679;</span>';
    }
    $ticker_html = $single . $single; // duplicate for seamless loop
} else {
    $ticker_html = '<span class="ticker-item">'
        . '<i class="bi bi-info-circle me-1" aria-hidden="true"></i>'
        . 'Live achievement updates will appear here once submissions are approved.'
        . '</span>';
}

// ===========================================================================
// DATA FETCH 2 — Hero stat counters
// ===========================================================================
$stats = ['total_students' => 0, 'achievements_logged' => 0, 'departments' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM users       WHERE role = 'student' AND is_active = 1) AS total_students,
            (SELECT COUNT(*) FROM achievements WHERE status = 'approved')               AS achievements_logged,
            (SELECT COUNT(DISTINCT department) FROM users WHERE role = 'student' AND is_active = 1) AS departments
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) { $stats = $row; }
} catch (PDOException $e) {
    error_log('[Zenith View] Stats fetch error: ' . $e->getMessage());
}

// ===========================================================================
// DATA FETCH 3 — Top 5 students for leaderboard preview
// ===========================================================================
$top_students = [];
try {
    $stmt = $pdo->prepare("
        SELECT name, department, study_year, total_points
        FROM   users
        WHERE  role = 'student' AND is_active = 1
        ORDER  BY total_points DESC
        LIMIT  5
    ");
    $stmt->execute();
    $top_students = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Zenith View] Top students fetch error: ' . $e->getMessage());
}

// ===========================================================================
// HELPERS
// ===========================================================================

/** Return a rank medal emoji span or a plain #N span. */
function rank_medal(int $rank): string {
    return match ($rank) {
        1 => '<span class="rank-medal" title="1st Place" aria-label="Gold Medal">&#x1F947;</span>',
        2 => '<span class="rank-medal" title="2nd Place" aria-label="Silver Medal">&#x1F948;</span>',
        3 => '<span class="rank-medal" title="3rd Place" aria-label="Bronze Medal">&#x1F949;</span>',
        default => '<span class="rank-number" aria-label="Rank ' . $rank . '">#' . $rank . '</span>',
    };
}

/** Return up to 2 uppercase initials from a full name. */
function get_initials(string $name): string {
    $words    = array_filter(explode(' ', trim($name)));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= strtoupper(mb_substr($word, 0, 1));
    }
    return $initials ?: '?';
}

// ===========================================================================
// RENDER — header.php outputs <!DOCTYPE>, <head>, <body>, navbar, AND the
// announcement ticker bar (using $ticker_html built above).
// ===========================================================================
require_once __DIR__ . '/includes/header.php';
?>

<!-- ===========================================================================
     MAIN PAGE CONTENT WRAPPER
     .page-main is a flex child of <body> (flex-column layout for sticky footer).
     It holds all visible page sections between the navbar and the footer.
=========================================================================== -->
<main class="page-main" id="main-content">

    <!-- =======================================================================
         HERO SECTION
         Two-column: left = copy + stats | right = live rankings card
    ======================================================================= -->
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="container">
            <div class="row align-items-center g-4 g-lg-5">

                <!-- Left Column: Copy & Stats -->
                <div class="col-12 col-lg-6">

                    <div class="hero-accent-bar">
                        <span class="eyebrow-rule" aria-hidden="true"></span>
                        <span class="hero-eyebrow">Official Academic Achievement Platform</span>
                    </div>

                    <h1 class="hero-title" id="hero-title">
                        Where Merit Gets<br>Its <span class="highlight">Recognition.</span>
                    </h1>

                    <p class="hero-description">
                        The Zenith View is KJSCE's centralised leaderboard for tracking student
                        achievements — from hackathon wins and published research to sports
                        victories and leadership positions. Transparent, merit-based, and live.
                    </p>

                    <div class="hero-cta-group">
                        <a href="<?= BASE_URL ?>/leaderboard.php" class="btn-hero-primary">
                            <i class="bi bi-bar-chart-steps" aria-hidden="true"></i>
                            View Leaderboard
                        </a>

                        <?php if (!$is_logged_in): ?>
                            <!-- Guest: prompt to log in -->
                            <a href="<?= BASE_URL ?>/login.php" class="btn-hero-outline">
                                <i class="bi bi-person-badge" aria-hidden="true"></i>
                                Student Login
                            </a>

                        <?php elseif ($session_role === 'student'): ?>
                            <!-- Logged-in student: go straight to their dashboard -->
                            <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn-hero-outline">
                                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                                My Dashboard
                            </a>

                        <?php elseif ($session_role === 'teacher'): ?>
                            <!-- Logged-in teacher: go to their review queue -->
                            <a href="<?= BASE_URL ?>/teacher/review_queue.php" class="btn-hero-outline">
                                <i class="bi bi-clipboard2-check" aria-hidden="true"></i>
                                Review Queue
                            </a>

                        <?php elseif ($session_role === 'admin'): ?>
                            <!-- Logged-in admin: go to settings panel -->
                            <a href="<?= BASE_URL ?>/admin/settings.php" class="btn-hero-outline">
                                <i class="bi bi-gear-fill" aria-hidden="true"></i>
                                Admin Panel
                            </a>
                        <?php endif; ?>

                    </div>

                    <div class="hero-stats" aria-label="Platform statistics">
                        <div class="hero-stat-item">
                            <span class="hero-stat-number"><?= number_format((int) $stats['total_students']) ?></span>
                            <span class="hero-stat-label">Students</span>
                        </div>
                        <div class="hero-stat-item">
                            <span class="hero-stat-number"><?= number_format((int) $stats['achievements_logged']) ?></span>
                            <span class="hero-stat-label">Achievements</span>
                        </div>
                        <div class="hero-stat-item">
                            <span class="hero-stat-number"><?= number_format((int) $stats['departments']) ?></span>
                            <span class="hero-stat-label">Departments</span>
                        </div>
                    </div>

                </div><!-- /col left -->

                <!-- Right Column: Live Rankings Card -->
                <div class="col-12 col-lg-5 offset-lg-1">
                    <div class="hero-visual-panel" aria-label="Live leaderboard preview">

                        <div class="panel-header">
                            <span class="live-dot" aria-hidden="true"></span>
                            Live Rankings &mdash; Top Performers
                        </div>

                        <?php if (!empty($top_students)):
                            $mock_rank = 0;
                            foreach ($top_students as $s):
                                $mock_rank++;
                        ?>
                        <div class="mock-rank-row">
                            <div class="mock-rank-badge <?= ($mock_rank === 1) ? 'top' : '' ?>" aria-label="Rank <?= $mock_rank ?>">
                                #<?= $mock_rank ?>
                            </div>
                            <div class="mock-avatar" aria-hidden="true">
                                <?= get_initials(htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8')) ?>
                            </div>
                            <div class="mock-name-dept">
                                <span class="mock-name"><?= htmlspecialchars($s['name'],       ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="mock-dept"><?= htmlspecialchars($s['department'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($s['study_year'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="mock-pts" aria-label="<?= (int) $s['total_points'] ?> points">
                                <?= number_format((int) $s['total_points']) ?> pts
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-hourglass-split empty-state-icon" aria-hidden="true"></i>
                            <p class="empty-state-text">Rankings will appear here once student data is loaded.</p>
                        </div>
                        <?php endif; ?>

                        <a href="<?= BASE_URL ?>/leaderboard.php" class="panel-footer-link">
                            View full leaderboard <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>

                    </div><!-- /hero-visual-panel -->
                </div><!-- /col right -->

            </div><!-- /row -->
        </div><!-- /container -->
    </section><!-- /hero-section -->


    <!-- =======================================================================
         SECTION: TOP 5 LEADERBOARD PREVIEW
    ======================================================================= -->
    <section class="section-preview" aria-labelledby="preview-title">
        <div class="container">

            <div class="row align-items-end mb-4">
                <div class="col-12 col-md-8">
                    <span class="section-eyebrow" aria-hidden="true">Rankings</span>
                    <h2 class="section-title" id="preview-title">Top 5 This Semester</h2>
                    <p class="section-subtitle">Scores update instantly the moment faculty approve an achievement.</p>
                </div>
                <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                    <a href="<?= BASE_URL ?>/leaderboard.php" class="view-all-link" aria-label="View the full leaderboard">
                        Full Leaderboard <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <div class="zv-card">
                <div class="table-responsive">
                    <table class="table zv-table" aria-label="Top 5 students by total points">
                        <thead>
                            <tr>
                                <th scope="col" style="width:65px;">Rank</th>
                                <th scope="col">Student Name</th>
                                <th scope="col" class="d-none d-sm-table-cell">Department</th>
                                <th scope="col" class="d-none d-md-table-cell">Year</th>
                                <th scope="col" class="text-end">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_students)):
                                $rank = 0;
                                foreach ($top_students as $student):
                                    $rank++;
                            ?>
                            <tr>
                                <td><?= rank_medal($rank) ?></td>
                                <td><span style="font-weight:600;"><?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="d-none d-sm-table-cell">
                                    <span class="dept-badge"><?= htmlspecialchars($student['department'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <span class="year-badge"><?= htmlspecialchars($student['study_year'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="text-end points-cell"><?= number_format((int) $student['total_points']) ?></td>
                            </tr>
                            <?php endforeach; ?>

                            <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox empty-state-icon" aria-hidden="true"></i>
                                        <p class="empty-state-text">
                                            No student data yet. The leaderboard will populate once
                                            achievements are submitted and approved by faculty.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- /zv-card -->

        </div><!-- /container -->
    </section><!-- /section-preview -->


    <!-- =======================================================================
         SECTION: HOW IT WORKS (Feature Cards)
    ======================================================================= -->
    <section class="section-features" aria-labelledby="how-it-works-title">
        <div class="container">

            <div class="row mb-4">
                <div class="col-12 text-center">
                    <span class="section-eyebrow" aria-hidden="true">The Platform</span>
                    <h2 class="section-title" id="how-it-works-title">How The Zenith View Works</h2>
                    <p class="section-subtitle mt-2">A transparent, four-stage pipeline from submission to live recognition.</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon" aria-hidden="true"><i class="bi bi-cloud-upload-fill"></i></div>
                        <span class="feature-step">Step 01</span>
                        <h3 class="feature-title">Submit Proof</h3>
                        <p class="feature-desc">Students upload certificate PDFs or images for their achievements — hackathons, papers, sports, and leadership roles.</p>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon" aria-hidden="true"><i class="bi bi-person-check-fill"></i></div>
                        <span class="feature-step">Step 02</span>
                        <h3 class="feature-title">Faculty Review</h3>
                        <p class="feature-desc">Designated faculty verify each submission and approve or reject it with mandatory written remarks.</p>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon" aria-hidden="true"><i class="bi bi-calculator-fill"></i></div>
                        <span class="feature-step">Step 03</span>
                        <h3 class="feature-title">Auto-Scoring</h3>
                        <p class="feature-desc">On approval, points are automatically calculated using the official scoring matrix and added to the student's total.</p>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon" aria-hidden="true"><i class="bi bi-trophy-fill"></i></div>
                        <span class="feature-step">Step 04</span>
                        <h3 class="feature-title">Live Rankings</h3>
                        <p class="feature-desc">The public leaderboard reflects every approval instantly, giving students full visibility into where they stand.</p>
                    </div>
                </div>
            </div><!-- /row g-3 -->

        </div><!-- /container -->
    </section><!-- /section-features -->


    <!-- =======================================================================
         SECTION: CTA BANNER
         Session-aware: hidden for teachers and admins (not their call-to-action).
         Personalised for logged-in students. Default for guests.
    ======================================================================= -->
    <?php if ($session_role !== 'teacher' && $session_role !== 'admin'): ?>
    <section class="section-cta" aria-label="Get started call to action">
        <div class="container">
            <div class="cta-inner">

                <?php if ($is_logged_in && $session_role === 'student'): ?>
                    <!-- Logged-in student: personalised upload prompt -->
                    <div>
                        <h3 class="cta-title">Have a new certificate to upload?</h3>
                        <p class="cta-subtitle">Head to your dashboard to submit a new achievement for faculty review.</p>
                    </div>
                    <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn-cta">
                        <i class="bi bi-cloud-upload" aria-hidden="true"></i>
                        Go to Dashboard
                    </a>

                <?php else: ?>
                    <!-- Guest: default sign-up prompt -->
                    <div>
                        <h3 class="cta-title">Ready to showcase your achievements?</h3>
                        <p class="cta-subtitle">Log in with your institutional credentials to submit and track your progress.</p>
                    </div>
                    <a href="<?= BASE_URL ?>/login.php" class="btn-cta">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                        Get Started
                    </a>
                <?php endif; ?>

            </div>
        </div>
    </section><!-- /section-cta -->
    <?php endif; ?>

</main><!-- /page-main -->


<?php
// footer.php outputs: back-to-top btn, <footer>, Bootstrap JS, main.js, </body>, </html>
require_once __DIR__ . '/includes/footer.php';
?>