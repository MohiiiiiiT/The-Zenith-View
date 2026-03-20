<?php
// =============================================================================
// THE ZENITH VIEW — /student/leaderboard.php
// Protected: Student Role Only | Global Leaderboard
//
// SECURITY:
//   [x] RBAC gate — only role='student' may access
//   [x] PDO prepared statements — all queries parameterised
//   [x] XSS — every echo of user data wrapped in htmlspecialchars()
//   [x] Filter inputs whitelisted server-side before use in queries
//
// BLUEPRINT REFERENCES:
//   §1  — File lives in /student/ directory
//   §2  — Reads users table: id, name, department, study_year, total_points, role
//   §4  — RBAC, PDO, XSS
//   §9B — Student feature scope
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP
// =============================================================================

require_once __DIR__ . '/includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];


// =============================================================================
// PHASE 2 — FILTER INPUTS (whitelist-validated)
// Values are checked against allowed sets — no raw user string reaches the query.
// =============================================================================

$allowed_departments = ['Comps', 'IT', 'Mech', 'EXTC', 'Other'];
$allowed_years       = ['FE', 'SE', 'TE', 'BE'];

$filter_dept = trim($_GET['dept'] ?? '');
$filter_year = trim($_GET['year'] ?? '');

if (!in_array($filter_dept, $allowed_departments, true)) { $filter_dept = ''; }
if (!in_array($filter_year, $allowed_years,       true)) { $filter_year = ''; }


// =============================================================================
// PHASE 3 — QUERY 1: TOP 50 STUDENTS
// Dynamically adds WHERE clauses for dept/year filters.
// Rank calculated in PHP loop (no window functions) for MySQL 5.x compatibility.
// =============================================================================

$top_students = [];
try {
    $where_clauses = ["role = 'student'", "is_active = 1"];
    $bind_params   = [];

    if ($filter_dept !== '') {
        $where_clauses[] = 'department = :dept';
        $bind_params[':dept'] = $filter_dept;
    }
    if ($filter_year !== '') {
        $where_clauses[] = 'study_year = :year';
        $bind_params[':year'] = $filter_year;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $stmt = $pdo->prepare("
        SELECT id, name, department, study_year, total_points
        FROM   users
        WHERE  {$where_sql}
        ORDER  BY total_points DESC, name ASC
        LIMIT  50
    ");
    $stmt->execute($bind_params);
    $top_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[Zenith View] leaderboard: top-50 query failed: ' . $e->getMessage());
}


// =============================================================================
// PHASE 4 — QUERY 2: CURRENT USER'S RANK & DATA
// Fetches the current user's own row first, then counts students with strictly
// more points to derive a contextual rank.
//
// ELIGIBILITY GUARD: If an active filter (dept or year) does not match the
// current user's own attributes, they do not belong to that filtered view.
// We skip the COUNT(*) query entirely and set $my_rank = '-' to signal
// "not applicable" — preventing a misleading rank from being displayed.
// =============================================================================

$my_rank = null;
$my_data = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, name, department, study_year, total_points
        FROM   users
        WHERE  id = :id AND role = 'student' AND is_active = 1
        LIMIT  1
    ");
    $stmt->execute([':id' => $current_user_id]);
    $my_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($my_data) {

        // ── Eligibility check ────────────────────────────────────────────────
        // A mismatch on either active filter means the user is outside this
        // filtered leaderboard. Mark rank as ineligible and skip COUNT query.
        $dept_mismatch = ($filter_dept !== '' && $filter_dept !== $my_data['department']);
        $year_mismatch = ($filter_year !== '' && $filter_year !== $my_data['study_year']);

        if ($dept_mismatch || $year_mismatch) {
            $my_rank = '-';   // sentinel: "not in this filtered view"
        } else {
            // ── Contextual rank: count students ranked strictly above this user ──
            $rank_where  = ["role = 'student'", "is_active = 1",
                            "total_points > :my_pts"];
            $rank_params = [':my_pts' => (int) $my_data['total_points']];

            if ($filter_dept !== '') {
                $rank_where[]         = 'department = :dept';
                $rank_params[':dept'] = $filter_dept;
            }
            if ($filter_year !== '') {
                $rank_where[]         = 'study_year = :year';
                $rank_params[':year'] = $filter_year;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM users
                WHERE " . implode(' AND ', $rank_where));
            $stmt->execute($rank_params);
            $my_rank = (int) $stmt->fetchColumn() + 1;
        }
    }

} catch (PDOException $e) {
    error_log('[Zenith View] leaderboard: user-rank query failed: ' . $e->getMessage());
}


// =============================================================================
// PHASE 5 — HELPERS
// =============================================================================

/**
 * Returns up to 2 initials from a full name.
 * "Rohan Sharma" → "RS"  |  "Priya" → "PR"
 */
function get_initials(string $name): string {
    $parts = array_values(array_filter(explode(' ', trim($name))));
    if (count($parts) >= 2) {
        return strtoupper(
            mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1)
        );
    }
    return strtoupper(mb_substr(trim($name), 0, 2));
}

/**
 * Maps rank 1/2/3 to Bootstrap icon + CSS modifier class.
 * Returns null for ranks 4+.
 */
function medal_icon(int $rank): ?array {
    return match ($rank) {
        1 => ['icon' => 'bi-trophy-fill',      'class' => 'rank-medal--gold'],
        2 => ['icon' => 'bi-award-fill',        'class' => 'rank-medal--silver'],
        3 => ['icon' => 'bi-patch-check-fill',  'class' => 'rank-medal--bronze'],
        default => null,
    };
}


// =============================================================================
// PHASE 6 — PAGE META
// =============================================================================

$page_title = 'Leaderboard';
$active_nav = 'leaderboard';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/leaderboard.css">';

require_once __DIR__ . '/includes/header.php';

$base = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');

// Pre-compute active filter states for pill styling
$dept_is_active = $filter_dept !== '';
$year_is_active = $filter_year !== '';
?>

<main class="page-main" id="main-content">
<div class="lb-wrapper">

    <!-- =========================================================================
         PAGE HEADER
         Breadcrumb removed per design directive — top-level page, no nav clutter.
    ========================================================================= -->
    <header class="lb-header">
        <div class="lb-header__body">

            <div class="lb-header__text">
                <span class="lb-header__eyebrow">Leaderboard</span>
                <h1 class="lb-header__title">Global Rankings</h1>
                <p class="lb-header__sub">
                    Live rankings across all active students. Points are updated
                    automatically as achievements are verified by faculty.
                </p>
            </div>

            <?php if ($my_data && $my_rank !== null): ?>
            <div class="lb-header__stat" aria-label="Your current standing">
                <div class="lb-stat-block">
                    <span class="lb-stat-block__label">Your Rank</span>
                    <span class="lb-stat-block__value">
                        <?php if ($my_rank === '-'): ?>
                        &mdash;
                        <?php else: ?>
                        #<?= (int) $my_rank ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="lb-stat-block">
                    <span class="lb-stat-block__label">Your Points</span>
                    <span class="lb-stat-block__value">
                        <?= number_format((int) $my_data['total_points']) ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </header>


    <!-- =========================================================================
         CONTROL CENTER — Search + Filter Pills
         Filter pills get .filter-pill-wrap--active when a server-side value is set,
         giving them the filled accent-secondary treatment automatically on load.
    ========================================================================= -->
    <div class="lb-controls">

        <div class="lb-search-wrap" role="search">
            <i class="bi bi-search lb-search-wrap__icon" aria-hidden="true"></i>
            <input
                type="search"
                id="lbSearch"
                class="lb-search"
                placeholder="Search by name…"
                aria-label="Search students by name"
                autocomplete="off"
                spellcheck="false"
            >
        </div>

        <div class="lb-filters" role="group" aria-label="Filter leaderboard">

            <div class="filter-pill-wrap <?= $dept_is_active ? 'filter-pill-wrap--active' : '' ?>">
                <i class="bi bi-building filter-pill-wrap__icon" aria-hidden="true"></i>
                <select
                    id="filterDept"
                    class="filter-pill"
                    aria-label="Filter by department"
                    onchange="applyFilters()"
                >
                    <option value="">All Depts</option>
                    <?php foreach ($allowed_departments as $d): ?>
                    <option
                        value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($filter_dept === $d) ? 'selected' : '' ?>
                    ><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="bi bi-chevron-down filter-pill-wrap__chevron" aria-hidden="true"></i>
            </div>

            <div class="filter-pill-wrap <?= $year_is_active ? 'filter-pill-wrap--active' : '' ?>">
                <i class="bi bi-mortarboard filter-pill-wrap__icon" aria-hidden="true"></i>
                <select
                    id="filterYear"
                    class="filter-pill"
                    aria-label="Filter by year"
                    onchange="applyFilters()"
                >
                    <option value="">All Years</option>
                    <?php foreach ($allowed_years as $y): ?>
                    <option
                        value="<?= htmlspecialchars($y, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($filter_year === $y) ? 'selected' : '' ?>
                    ><?= htmlspecialchars($y, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="bi bi-chevron-down filter-pill-wrap__chevron" aria-hidden="true"></i>
            </div>

            <?php if ($dept_is_active || $year_is_active): ?>
            <a href="leaderboard.php" class="filter-pill-clear" aria-label="Clear all filters">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
                Clear
            </a>
            <?php endif; ?>

        </div>

    </div><!-- /lb-controls -->


    <!-- =========================================================================
         RANKING LIST
         Each student row is its own floating card (ranking-card).
         No shared shell. No column header row. Data is self-evident.
         Ranks 1-3 get metallic left borders + glowing avatar rings only —
         no muddy full-row background tints.
    ========================================================================= -->

    <?php if (empty($top_students)): ?>

    <div class="lb-empty-card" role="region" aria-label="No students found">
        <i class="bi bi-bar-chart-line lb-empty__icon" aria-hidden="true"></i>
        <p class="lb-empty__title">No students found</p>
        <p class="lb-empty__sub">Try adjusting your filters.</p>
    </div>

    <?php else: ?>

    <div class="ranking-list" role="list" id="rankingRows">
    <?php
        $rank = 0;
        foreach ($top_students as $s):
            $rank++;
            $is_me    = ((int) $s['id'] === $current_user_id);
            $medal    = medal_icon($rank);
            $initials = get_initials($s['name']);

            // XSS-safe values
            $e_name = htmlspecialchars($s['name'],       ENT_QUOTES, 'UTF-8');
            $e_dept = htmlspecialchars($s['department'], ENT_QUOTES, 'UTF-8');
            $e_year = htmlspecialchars($s['study_year'], ENT_QUOTES, 'UTF-8');
            $pts    = number_format((int) $s['total_points']);

            // Card classes — metallic variant replaces old full-row tint
            $card_cls = 'ranking-card';
            if ($rank === 1) $card_cls .= ' ranking-card--gold';
            if ($rank === 2) $card_cls .= ' ranking-card--silver';
            if ($rank === 3) $card_cls .= ' ranking-card--bronze';
            if ($is_me)      $card_cls .= ' ranking-card--me';

            // Avatar ring class for medal ranks
            $avatar_cls = 'lb-avatar';
            if ($rank === 1) $avatar_cls .= ' lb-avatar--gold';
            if ($rank === 2) $avatar_cls .= ' lb-avatar--silver';
            if ($rank === 3) $avatar_cls .= ' lb-avatar--bronze';
            if ($is_me)      $avatar_cls .= ' lb-avatar--me';
    ?>
        <div
            class="<?= $card_cls ?>"
            role="listitem"
            data-name="<?= strtolower($e_name) ?>"
            aria-label="Rank <?= $rank ?>, <?= $e_name ?>, <?= $e_dept ?>, <?= $e_year ?>, <?= $pts ?> points"
        >
            <!-- ── Rank / Medal cell ───────────────────────────────────────── -->
            <div class="ranking-card__rank" aria-hidden="true">
                <?php if ($medal): ?>
                <i class="bi <?= $medal['icon'] ?> rank-medal <?= $medal['class'] ?>"></i>
                <?php else: ?>
                <span class="rank-num"><?= $rank ?></span>
                <?php endif; ?>
            </div>

            <!-- ── Identity: Avatar + Name + Dept/Year meta ───────────────── -->
            <div class="ranking-card__identity">
                <div class="<?= $avatar_cls ?>" aria-hidden="true">
                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="ranking-card__nameblock">
                    <span class="ranking-card__name">
                        <?= $e_name ?>
                        <?php if ($is_me): ?>
                        <span class="you-badge" aria-label="This is you">You</span>
                        <?php endif; ?>
                    </span>
                    <span class="ranking-card__meta">
                        <?= $e_dept ?>
                        <span aria-hidden="true">&middot;</span>
                        <?= $e_year ?>
                    </span>
                </div>
            </div>

            <!-- ── Points pill — right-anchored ───────────────────────────── -->
            <div class="ranking-card__pts">
                <span class="pts-pill"><?= $pts ?><span class="pts-pill__label"> pts</span></span>
            </div>

        </div><!-- /ranking-card -->
    <?php endforeach; ?>
    </div><!-- /ranking-list -->

    <!-- JS-powered no-results message (shown by filterRows() when search yields zero) -->
    <div class="lb-no-results" id="lbNoResults" hidden aria-live="polite" role="status">
        <i class="bi bi-search" aria-hidden="true"></i>
        No students match &ldquo;<strong id="lbSearchTerm"></strong>&rdquo;
    </div>

    <?php endif; ?>

</div><!-- /lb-wrapper -->
</main>



<script>
(function () {
    'use strict';

    // ── 1. LIVE NAME SEARCH (client-side, debounced 150ms) ──────────────────

    var searchInput = document.getElementById('lbSearch');
    var noResults   = document.getElementById('lbNoResults');
    var searchTerm  = document.getElementById('lbSearchTerm');
    var rows        = document.querySelectorAll('#rankingRows .ranking-card');
    var debounce;

    function filterRows(q) {
        q = q.trim().toLowerCase();
        var hits = 0;
        rows.forEach(function (row) {
            var match = !q || (row.dataset.name || '').indexOf(q) !== -1;
            row.hidden = !match;
            if (match) hits++;
        });
        if (noResults) {
            noResults.hidden = !q || hits > 0;
            if (searchTerm) searchTerm.textContent = q;
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () { filterRows(searchInput.value); }, 150);
        });
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { searchInput.value = ''; filterRows(''); }
        });
    }


    // ── 2. FILTER NAVIGATION (server-side reload) ───────────────────────────

    window.applyFilters = function () {
        var dept   = document.getElementById('filterDept');
        var year   = document.getElementById('filterYear');
        var params = new URLSearchParams();
        if (dept && dept.value) params.set('dept', dept.value);
        if (year && year.value) params.set('year', year.value);
        var qs = params.toString();
        window.location.href = 'leaderboard.php' + (qs ? '?' + qs : '');
    };

}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>