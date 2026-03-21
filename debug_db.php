<?php
// =============================================================================
// THE ZENITH VIEW — debug_db.php
// Standalone DB diagnostic script.
//
// PLACEMENT: Drop this file in your project ROOT (zenith-view/debug_db.php),
//            the same level as login.php and index.php.
//
// ACCESS:    http://192.168.0.175/zenith-view/debug_db.php
//
// !! DELETE THIS FILE IMMEDIATELY AFTER DEBUGGING !!
//    It exposes raw database contents with no authentication.
// =============================================================================

// config.php is in zenith-view/includes/ — one level down from root
require_once __DIR__ . '/includes/config.php';

// Hard-stop if someone left this on production accidentally
if (defined('BASE_URL') && strpos(BASE_URL, 'localhost') === false
    && strpos(BASE_URL, '192.168.') === false) {
    die('Diagnostic script must not run on a public server. Delete debug_db.php now.');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Zenith View — DB Diagnostic</title>
<style>
    body  { font-family: monospace; background: #0d1117; color: #e6edf3;
            padding: 2rem; line-height: 1.6; }
    h2    { color: #58a6ff; border-bottom: 1px solid #30363d; padding-bottom: .5rem; }
    h3    { color: #ffa657; margin-top: 2rem; }
    .ok   { color: #3fb950; }
    .warn { color: #d29922; }
    .err  { color: #f85149; }
    .box  { background: #161b22; border: 1px solid #30363d; border-radius: 6px;
            padding: 1rem 1.25rem; margin: .75rem 0; overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; font-size: .85rem; }
    th    { background: #21262d; color: #58a6ff; padding: .4rem .75rem;
            text-align: left; border: 1px solid #30363d; white-space: nowrap; }
    td    { padding: .35rem .75rem; border: 1px solid #30363d; vertical-align: top; }
    tr:nth-child(even) td { background: #161b22; }
    .null { color: #6e7681; font-style: italic; }
    .pending  { color: #ffa657; font-weight: bold; }
    .approved { color: #3fb950; font-weight: bold; }
    .rejected { color: #f85149; font-weight: bold; }
</style>
</head>
<body>

<h2>&#128270; Zenith View — Database Diagnostic</h2>
<p class="warn">&#9888; Delete this file after debugging.</p>

<?php

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1 — Connection confirmation
// ─────────────────────────────────────────────────────────────────────────────
echo '<h3>1. Connection</h3>';
echo '<div class="box">';
try {
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo '<span class="ok">&#10003; PDO connected successfully.</span><br>';
    echo 'Server version: <strong>' . htmlspecialchars($ver) . '</strong><br>';
    echo 'Database: <strong>' . htmlspecialchars(DB_NAME) . '</strong>';
} catch (PDOException $e) {
    echo '<span class="err">&#10007; Connection failed: ' . htmlspecialchars($e->getMessage()) . '</span>';
}
echo '</div>';


// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2 — Row counts for all four tables
// ─────────────────────────────────────────────────────────────────────────────
echo '<h3>2. Table Row Counts</h3>';
echo '<div class="box">';
$tables = ['users', 'achievements', 'support_tickets', 'system_settings'];
foreach ($tables as $t) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $colour = ((int)$n === 0) ? 'warn' : 'ok';
        echo "<span class='{$colour}'>{$t}: <strong>{$n}</strong> row(s)</span><br>";
    } catch (PDOException $e) {
        echo "<span class='err'>{$t}: ERROR — " . htmlspecialchars($e->getMessage()) . "</span><br>";
    }
}
echo '</div>';


// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3 — Full achievements table dump
// ─────────────────────────────────────────────────────────────────────────────
echo '<h3>3. All Rows in <code>achievements</code></h3>';
try {
    $rows = $pdo->query("SELECT * FROM achievements ORDER BY id ASC")->fetchAll();

    if (empty($rows)) {
        echo '<div class="box"><span class="warn">&#9888; Table is EMPTY — no rows exist yet.</span><br>';
        echo 'You need to submit at least one achievement via the student upload form before the queue can populate.</div>';
    } else {
        echo '<div class="box">';
        echo '<table><thead><tr>';
        foreach (array_keys($rows[0]) as $col) {
            echo '<th>' . htmlspecialchars($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $col => $val) {
                $display = ($val === null)
                    ? '<span class="null">NULL</span>'
                    : htmlspecialchars((string)$val);

                // Highlight the status column so it stands out immediately
                if ($col === 'status') {
                    $cls = match($val) {
                        'pending'  => 'pending',
                        'approved' => 'approved',
                        'rejected' => 'rejected',
                        default    => 'warn',
                    };
                    $display = "<span class='{$cls}'>{$display}</span>";
                }
                echo "<td>{$display}</td>";
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
} catch (PDOException $e) {
    echo '<div class="box err">Query failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
}


// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4 — Full users table dump (password_hash masked)
// ─────────────────────────────────────────────────────────────────────────────
echo '<h3>4. All Rows in <code>users</code> (password masked)</h3>';
try {
    $rows = $pdo->query("SELECT id, name, email, role, department, study_year,
                                base_cgpa, total_points, is_active, created_at
                         FROM users ORDER BY id ASC")->fetchAll();

    if (empty($rows)) {
        echo '<div class="box"><span class="warn">&#9888; users table is EMPTY.</span></div>';
    } else {
        echo '<div class="box"><table><thead><tr>';
        foreach (array_keys($rows[0]) as $col) {
            echo '<th>' . htmlspecialchars($col) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $val) {
                echo '<td>' . ($val === null
                    ? '<span class="null">NULL</span>'
                    : htmlspecialchars((string)$val)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
} catch (PDOException $e) {
    echo '<div class="box err">Query failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
}


// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5 — The exact JOIN query from review_queue.php (no filters)
// ─────────────────────────────────────────────────────────────────────────────
echo "<h3>5. The Exact Review Queue JOIN — status = 'pending', no other filters</h3>";
try {
    $rows = $pdo->query("
        SELECT
            a.id              AS achievement_id,
            a.student_id,
            a.title,
            a.category,
            a.tier,
            a.status,
            a.submitted_at,
            u.id              AS user_id,
            u.name            AS student_name,
            u.department,
            u.study_year,
            u.is_active
        FROM  achievements a
        JOIN  users u ON u.id = a.student_id
        WHERE a.status = 'pending'
        ORDER BY a.submitted_at ASC
    ")->fetchAll();

    if (empty($rows)) {
        echo '<div class="box"><span class="warn">&#9888; JOIN returned ZERO rows.</span><br><br>';
        echo '<strong>Most likely causes:</strong><br>';
        echo '&nbsp; A) The <code>achievements</code> table has no rows at all (see Section 3).<br>';
        echo '&nbsp; B) Rows exist but <code>status</code> is NOT exactly the string <code>pending</code> ';
        echo '(check for trailing spaces, uppercase, or a different value — see Section 3).<br>';
        echo '&nbsp; C) <code>achievements.student_id</code> does not match any <code>users.id</code> ';
        echo '— the JOIN finds no matching user (broken FK seed data).<br>';
        echo '&nbsp; D) The <code>users</code> table is empty (see Section 4).</div>';
    } else {
        echo '<div class="box"><span class="ok">&#10003; JOIN returned <strong>' . count($rows) . '</strong> row(s). ';
        echo 'The query works — the issue is the department filter. See Section 6.</span>';
        echo '<table style="margin-top:.75rem"><thead><tr>';
        foreach (array_keys($rows[0]) as $col) {
            echo '<th>' . htmlspecialchars($col) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $col => $val) {
                $display = ($val === null)
                    ? '<span class="null">NULL</span>'
                    : htmlspecialchars((string)$val);
                if ($col === 'status') {
                    $display = "<span class='pending'>{$display}</span>";
                }
                echo "<td>{$display}</td>";
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
} catch (PDOException $e) {
    echo '<div class="box err">Query failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
}


// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6 — Department mismatch check
// Shows all distinct department values in both tables so you can spot
// any case/spelling difference between the teacher session and the DB.
// ─────────────────────────────────────────────────────────────────────────────
echo '<h3>6. Department Values (exact strings stored in DB)</h3>';
echo '<div class="box">';
try {
    $depts = $pdo->query("SELECT DISTINCT department FROM users ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
    echo '<strong>Distinct values in <code>users.department</code>:</strong><br>';
    if (empty($depts)) {
        echo '<span class="warn">None — users table is empty.</span><br>';
    } else {
        foreach ($depts as $d) {
            echo '&nbsp; <code>"' . htmlspecialchars($d) . '"</code> ';
            echo '(length: ' . strlen($d) . ')<br>';
        }
    }
} catch (PDOException $e) {
    echo '<span class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</span><br>';
}
echo '<br>';

// Show what the current session thinks the teacher's department is
session_start();  // already started by config.php — this is a no-op
echo '<strong>Current <code>$_SESSION[\'department\']</code>:</strong><br>';
if (isset($_SESSION['department'])) {
    echo '&nbsp; <code>"' . htmlspecialchars($_SESSION['department']) . '"</code> ';
    echo '(length: ' . strlen($_SESSION['department']) . ')';
} else {
    echo '<span class="warn">&nbsp; NOT SET — you are not logged in as a teacher, ';
    echo 'or session has expired. Log in and reload this page.</span>';
}
echo '</div>';


// ─────────────────────────────────────────────────────────────────────────────
// SECTION 7 — system_settings (submission window)
// ─────────────────────────────────────────────────────────────────────────────
echo '<h3>7. <code>system_settings</code> Table</h3>';
try {
    $rows = $pdo->query("SELECT * FROM system_settings")->fetchAll();
    if (empty($rows)) {
        echo '<div class="box"><span class="warn">Table is empty — no settings rows yet.</span>';
        echo '<br>The submission window defaults to OPEN when this row is missing.</div>';
    } else {
        echo '<div class="box"><table><thead><tr><th>id</th><th>setting_key</th><th>setting_value</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int)$row['id'] . '</td>';
            echo '<td>' . htmlspecialchars($row['setting_key']) . '</td>';
            echo '<td>' . htmlspecialchars($row['setting_value']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
} catch (PDOException $e) {
    echo '<div class="box err">Query failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

?>

<h3 style="color:#f85149; margin-top:3rem">&#9888; Reminder: Delete <code>debug_db.php</code> now.</h3>

</body>
</html>