<?php
// =============================================================================
// THE ZENITH VIEW — /student/upload_proof.php
// Protected: Student Role Only | Achievement Submission Portal
//
// SECURITY CHECKLIST:
//   [x] RBAC gate — only role='student' may access this page
//   [x] CSRF token — generated per session, validated on POST with hash_equals()
//   [x] PDO prepared statements — all DB writes parameterised, no interpolation
//   [x] File MIME validated via finfo_open() — extension alone is NOT trusted
//   [x] File size enforced server-side (5 MB hard cap)
//   [x] Cryptographic filename — bin2hex(random_bytes(24)) prevents enumeration
//   [x] Upload directory enforced via __DIR__ (no user-controlled path segments)
//   [x] XSS — every echo of user data wrapped in htmlspecialchars()
//   [x] Submission window check — respects system_settings 'submission_window'
//
// BLUEPRINT REFERENCES:
//   §1  — File lives in /student/ directory
//   §2  — Writes to achievements table; reads system_settings
//   §4  — RBAC, PDO, XSS rules
//   §9B — Student upload feature scope
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

// --- RBAC Gate ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}

$student_id = (int) $_SESSION['user_id'];


// =============================================================================
// PHASE 2 — SUBMISSION WINDOW CHECK
// Reads 'submission_window' from system_settings.
// If 'closed', the form is locked and no POST is processed.
// =============================================================================

$window_open = true; // optimistic default — fail open if the row is missing
try {
    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM   system_settings
        WHERE  setting_key = 'submission_window'
        LIMIT  1
    ");
    $stmt->execute();
    $setting = $stmt->fetchColumn();
    if ($setting !== false && strtolower(trim($setting)) === 'closed') {
        $window_open = false;
    }
} catch (PDOException $e) {
    error_log('[Zenith View] upload_proof: window check failed: ' . $e->getMessage());
    // Fail open — don't block students on a DB hiccup
}


// =============================================================================
// PHASE 3 — CSRF TOKEN
// =============================================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// =============================================================================
// PHASE 4 — SCORING MATRIX REFERENCE DATA (Blueprint §3)
// Drives both the frontend dropdowns and the server-side option validation.
// Stored as nested arrays: category → [tier => points]
// =============================================================================

$scoring_matrix = [
    'Technical Events' => [
        '1st Place'         => 200,
        '2nd Place'         => 150,
        '3rd Place'         => 100,
        'Finalist / Top 10' => 50,
        'Participation'     => 20,
    ],
    'Research & Technical' => [
        'Patent Filed / Published' => 400,
        'Published Paper'          => 300,
        'Official Certification'   => 50,
    ],
    'Extra-Curriculars & Sports' => [
        'University / State Winner' => 150,
        'College Winner'            => 75,
    ],
    'Positions of Responsibility' => [
        'Student Council / Club President' => 150,
        'Committee Member / Volunteer'     => 50,
    ],
];


// =============================================================================
// PHASE 5 — OUTPUT VARIABLES
// =============================================================================

$upload_error   = null;   // string: error message to display in alert
$upload_success = false;  // bool: triggers the success state
$form_values    = [];     // repopulate form on validation failure


// =============================================================================
// PHASE 6 — POST HANDLER
// Only runs if window is open and request is POST.
// =============================================================================

if ($window_open && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── 6.1 CSRF validation ──────────────────────────────────────────────────
    $submitted_token = trim($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
        $upload_error = 'Session expired. Please refresh the page and try again.';
        error_log('[Zenith View] upload_proof: CSRF mismatch, IP=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }

    // ── 6.2 Text field capture & sanitisation ────────────────────────────────
    if (!$upload_error) {
        $title       = trim($_POST['title']       ?? '');
        $category    = trim($_POST['category']    ?? '');
        $tier        = trim($_POST['tier']        ?? '');
        $event_date  = trim($_POST['event_date']  ?? '');
        $description = trim($_POST['description'] ?? '');

        // Stash for form repopulation on error
        $form_values = compact('title', 'category', 'tier', 'event_date', 'description');

        // ── 6.2a Required field presence ────────────────────────────────────
        if ($title === '' || $category === '' || $tier === '' || $event_date === '') {
            $upload_error = 'Please fill in all required fields (Title, Category, Tier, and Date).';
        }
    }

    // ── 6.3 Category & Tier whitelist validation ─────────────────────────────
    if (!$upload_error) {
        if (!isset($scoring_matrix[$category])) {
            $upload_error = 'Invalid category selected. Please choose from the dropdown.';
        } elseif (!isset($scoring_matrix[$category][$tier])) {
            $upload_error = 'Invalid tier for the selected category. Please reselect.';
        }
    }

    // ── 6.4 Date validation ──────────────────────────────────────────────────
    if (!$upload_error) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $event_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $event_date) {
            $upload_error = 'Invalid date format. Please use the date picker.';
        } elseif ($date_obj > new DateTime('today')) {
            $upload_error = 'Event date cannot be in the future.';
        }
    }

    // ── 6.5 File upload validation ───────────────────────────────────────────
    $proof_file_path = null;
    if (!$upload_error) {

        if (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $upload_error = 'A proof file (PDF, JPG, or PNG) is required.';
        } elseif ($_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
            $php_upload_errors = [
                UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form size limit.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temp directory.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: could not write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            ];
            $upload_error = $php_upload_errors[$_FILES['proof_file']['error']]
                          ?? 'An unknown upload error occurred.';
        }
    }

    // Size check (5 MB = 5 × 1024 × 1024 bytes)
    if (!$upload_error) {
        $max_bytes = 5 * 1024 * 1024;
        if ($_FILES['proof_file']['size'] > $max_bytes) {
            $upload_error = 'File is too large. Maximum allowed size is 5 MB.';
        }
    }

    // MIME type — finfo reads actual file magic bytes, not the browser-supplied type
    if (!$upload_error) {
        $allowed_mimes = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/jpg'       => 'jpg',
            'image/png'       => 'png',
        ];
        $finfo     = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = finfo_file($finfo, $_FILES['proof_file']['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed_mimes[$real_mime])) {
            $upload_error = 'Invalid file type. Only PDF, JPG, and PNG files are accepted.';
        }
    }

    // ── 6.6 Cryptographic file rename and move ───────────────────────────────
    if (!$upload_error) {
        $safe_ext    = $allowed_mimes[$real_mime];
        $new_filename = 'proof_' . bin2hex(random_bytes(24)) . '.' . $safe_ext;
        $upload_dir   = __DIR__ . '/../uploads/proofs/';
        $target_path  = $upload_dir . $new_filename;

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $target_path)) {
            $upload_error = 'Server error: could not save the uploaded file. Please try again.';
            error_log('[Zenith View] upload_proof: move_uploaded_file failed, target=' . $target_path);
        } else {
            $proof_file_path = 'uploads/proofs/' . $new_filename;
        }
    }

    // ── 6.7 Database insert ──────────────────────────────────────────────────
    if (!$upload_error) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO achievements
                    (student_id, category, tier, title, proof_file_path,
                     points_awarded, status, submitted_at)
                VALUES
                    (:student_id, :category, :tier, :title, :proof_file_path,
                     0, 'pending', NOW())
            ");
            $stmt->execute([
                ':student_id'      => $student_id,
                ':category'        => $category,
                ':tier'            => $tier,
                ':title'           => $title,
                ':proof_file_path' => $proof_file_path,
            ]);

            // Success — regenerate CSRF, clear form, set success flag
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $form_values            = [];
            $upload_success         = true;

        } catch (PDOException $e) {
            error_log('[Zenith View] upload_proof: DB insert failed: ' . $e->getMessage());
            $upload_error = 'A database error occurred. Your submission was not saved. Please try again.';
            // Clean up the orphaned file if DB write fails
            if ($proof_file_path && file_exists(__DIR__ . '/../' . $proof_file_path)) {
                unlink(__DIR__ . '/../' . $proof_file_path);
            }
        }
    }

} // end POST handler


// =============================================================================
// PHASE 7 — PAGE META
// =============================================================================

$page_title = 'Upload Achievement';
$active_nav = 'dashboard';
$extra_head = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/upload.css">';


// =============================================================================
// PHASE 8 — RENDER
// =============================================================================

require_once __DIR__ . '/../includes/header.php';

$e_csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
$base   = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');
?>

<main class="page-main" id="main-content">
<div class="upload-wrapper">

    <!-- =========================================================================
         PAGE HEADER
    ========================================================================= -->
    <header class="upload-header">
        <nav class="guide-breadcrumb" aria-label="Breadcrumb">
            <a href="dashboard.php" class="guide-breadcrumb__link">
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                Dashboard
            </a>
            <span class="guide-breadcrumb__sep" aria-hidden="true">/</span>
            <span class="guide-breadcrumb__current">Submit Achievement</span>
        </nav>
        <h1 class="upload-header__title">Submit Achievement</h1>
        <p class="upload-header__sub">
            Upload your certificate or proof. Faculty will verify and points
            are awarded automatically upon approval.
        </p>
    </header>


    <?php if (!$window_open): ?>
    <!-- =========================================================================
         WINDOW CLOSED STATE
    ========================================================================= -->
    <div class="upload-state-card" role="alert">
        <div class="state-icon state-icon--locked" aria-hidden="true">
            <i class="bi bi-lock-fill"></i>
        </div>
        <h2 class="state-title">Submissions are currently closed</h2>
        <p class="state-body">
            The submission window has been closed by the administrator.
            Please check back later or contact the helpdesk if you believe this is an error.
        </p>
        <a href="tickets.php" class="btn-state-action btn-state-action--outline">
            <i class="bi bi-headset" aria-hidden="true"></i>
            Contact Helpdesk
        </a>
    </div>

    <?php elseif ($upload_success): ?>
    <!-- =========================================================================
         SUCCESS STATE
    ========================================================================= -->
    <div class="upload-state-card" role="region" aria-label="Submission successful">
        <div class="state-icon state-icon--success" aria-hidden="true">
            <i class="bi bi-patch-check-fill"></i>
        </div>
        <h2 class="state-title">Submission received!</h2>
        <p class="state-body">
            Your achievement has been queued for faculty review.
            Track its status from your dashboard — points are awarded automatically on approval.
        </p>
        <div class="state-actions">
            <a href="upload_proof.php" class="btn-state-action btn-state-action--primary">
                <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
                Submit Another
            </a>
            <a href="dashboard.php" class="btn-state-action btn-state-action--outline">
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                Go to Dashboard
            </a>
        </div>
    </div>

    <?php else: ?>
    <!-- =========================================================================
         ASYMMETRIC TWO-COLUMN LAYOUT
         Left  (~65%): form card
         Right (~35%): two stacked helper bento cards
         Stacks to single column below 992px (Bootstrap lg breakpoint).
    ========================================================================= -->

    <?php if ($upload_error): ?>
    <div class="upload-alert" role="alert" aria-live="assertive" id="upload-alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <span><?= htmlspecialchars($upload_error, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <div class="upload-layout">

        <!-- =================================================================
             LEFT COLUMN — The Form Card (~65%)
        ================================================================= -->
        <div class="upload-layout__main">
            <div class="upload-card">
                <form
                    id="uploadForm"
                    method="POST"
                    action="<?= $base ?>/student/upload_proof.php"
                    enctype="multipart/form-data"
                    novalidate
                >
                    <input type="hidden" name="csrf_token" value="<?= $e_csrf ?>">

                    <!-- ── 1. Achievement Title (full width) ──────────────── -->
                    <div class="field-group">
                        <label class="field-label" for="title">
                            Achievement Title <span class="field-required" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="text"
                            id="title"
                            name="title"
                            class="field-input"
                            placeholder="e.g., 1st Place — Smart India Hackathon 2024"
                            maxlength="255"
                            required
                            autocomplete="off"
                            value="<?= htmlspecialchars($form_values['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <!-- ── 2. Category + Tier (50/50 side-by-side) ────────── -->
                    <div class="field-row">

                        <div class="field-group">
                            <label class="field-label" for="category">
                                Category <span class="field-required" aria-hidden="true">*</span>
                            </label>
                            <div class="select-wrap">
                                <select id="category" name="category" class="field-select" required>
                                    <option value="" disabled <?= empty($form_values['category']) ? 'selected' : '' ?>>
                                        Select category…
                                    </option>
                                    <?php foreach (array_keys($scoring_matrix) as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= (($form_values['category'] ?? '') === $cat) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="bi bi-chevron-down select-arrow" aria-hidden="true"></i>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="tier">
                                Tier / Level <span class="field-required" aria-hidden="true">*</span>
                            </label>
                            <div class="select-wrap">
                                <select id="tier" name="tier" class="field-select" required>
                                    <option value="" disabled <?= empty($form_values['tier']) ? 'selected' : '' ?>>
                                        Select tier…
                                    </option>
                                    <?php if (!empty($form_values['category']) && isset($scoring_matrix[$form_values['category']])): ?>
                                        <?php foreach ($scoring_matrix[$form_values['category']] as $t => $pts): ?>
                                        <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= (($form_values['tier'] ?? '') === $t) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <i class="bi bi-chevron-down select-arrow" aria-hidden="true"></i>
                            </div>
                        </div>

                    </div><!-- /field-row -->

                    <!-- ── 3. Points hint pill ────────────────────────────── -->
                    <div class="points-hint-wrap">
                        <div class="points-hint" id="pointsHint" role="note" aria-label="Points information">
                            <i class="bi bi-gem" aria-hidden="true"></i>
                            <span id="pointsHintText">Points awarded based on Category &amp; Tier</span>
                        </div>
                    </div>

                    <!-- ── 4. Event / Issue Date (full width) ─────────────── -->
                    <div class="field-group">
                        <label class="field-label" for="event_date">
                            Event / Issue Date <span class="field-required" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="date"
                            id="event_date"
                            name="event_date"
                            class="field-input field-input--date"
                            required
                            max="<?= date('Y-m-d') ?>"
                            value="<?= htmlspecialchars($form_values['event_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <!-- ── 5. Description (full width, optional) ──────────── -->
                    <div class="field-group">
                        <label class="field-label" for="description">
                            Description
                            <span class="field-optional">(optional)</span>
                        </label>
                        <textarea
                            id="description"
                            name="description"
                            class="field-input field-input--textarea"
                            placeholder="Briefly describe the achievement, organiser, or any relevant context…"
                            rows="3"
                            maxlength="1000"
                        ><?= htmlspecialchars($form_values['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        <span class="field-charcount" id="descCharCount" aria-live="polite">0 / 1000</span>
                    </div>

                    <!-- ── 6. Dropzone — compact ~120px, full width ────────── -->
                    <div class="field-group">
                        <label class="field-label" id="dropzone-label">
                            Proof Document <span class="field-required" aria-hidden="true">*</span>
                        </label>

                        <input
                            type="file"
                            id="proofFile"
                            name="proof_file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            aria-labelledby="dropzone-label"
                            class="dropzone-hidden-input"
                            required
                        >

                        <div
                            class="dropzone"
                            id="dropzone"
                            role="button"
                            tabindex="0"
                            aria-label="Click or drag and drop your proof file here"
                        >
                            <!-- Idle state -->
                            <div class="dropzone__idle" id="dropzoneIdle">
                                <i class="bi bi-cloud-arrow-up dropzone__icon" aria-hidden="true"></i>
                                <span class="dropzone__text">
                                    <span class="dropzone__browse">Click to upload</span>
                                    or drag &amp; drop
                                    <span class="dropzone__sep" aria-hidden="true">&bull;</span>
                                    <span class="dropzone__constraints">PDF, JPG, PNG — max 5 MB</span>
                                </span>
                            </div>

                            <!-- File selected preview -->
                            <div class="dropzone__preview" id="dropzonePreview" hidden>
                                <i class="bi bi-file-earmark-check dropzone__file-icon" aria-hidden="true" id="dropzoneFileIcon"></i>
                                <div class="dropzone__file-info">
                                    <span class="dropzone__file-name" id="dropzoneFileName"></span>
                                    <span class="dropzone__file-size" id="dropzoneFileSize"></span>
                                </div>
                                <button type="button" class="dropzone__remove" id="dropzoneRemove" aria-label="Remove file">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <p class="field-error" id="fileError" hidden role="alert" aria-live="assertive"></p>
                    </div>

                    <!-- ── 7. Card footer: notice + submit button ─────────── -->
                    <div class="upload-card__footer">
                        <p class="submit-notice">
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            Stored securely. Faculty reviews within 2–3 working days.
                        </p>
                        <button
                            type="submit"
                            class="btn-submit-upload"
                            id="submitBtn"
                            aria-label="Submit achievement for faculty review"
                        >
                            <span class="btn-submit-upload__text">
                                <i class="bi bi-send-fill" aria-hidden="true"></i>
                                Submit for Review
                            </span>
                            <span class="btn-submit-upload__spinner" aria-hidden="true"></span>
                        </button>
                    </div>

                </form>
            </div><!-- /upload-card -->
        </div><!-- /upload-layout__main -->


        <!-- =================================================================
             RIGHT COLUMN — Helper Bento Cards (~35%)
             Stacks below the form on mobile (< 992px).
        ================================================================= -->
        <div class="upload-layout__aside">

            <!-- ── Helper Card 1: Submission Guidelines ───────────────────── -->
            <div class="helper-card">
                <div class="helper-card__header">
                    <h2 class="helper-card__title">Submission Guidelines</h2>
                    <p class="helper-card__sub">Follow these to get approved faster</p>
                </div>
                <ul class="guidelines-list" aria-label="Submission guidelines">
                    <li class="guidelines-list__item">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>Ensure your <strong>full name</strong> is clearly visible on the document.</span>
                    </li>
                    <li class="guidelines-list__item">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>File must be <strong>PDF, JPG, or PNG</strong> (Max <strong>5 MB</strong>).</span>
                    </li>
                    <li class="guidelines-list__item">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>Faculty review typically takes <strong>2–3 working days</strong>.</span>
                    </li>
                </ul>
            </div><!-- /helper-card guidelines -->

            <!-- ── Helper Card 2: Points Callout ─────────────────────────── -->
            <div class="helper-card helper-card--points">
                <div class="helper-card__header">
                    <h2 class="helper-card__title">How are points calculated?</h2>
                </div>
                <p class="helper-card__body">
                    Each category and tier maps to a fixed point value in the official
                    scoring matrix. Technical wins, research papers, sports victories,
                    and leadership roles all count toward your leaderboard rank.
                </p>
                <a href="<?= $base ?>/student/points_guide.php" class="btn-view-guide">
                    <i class="bi bi-table" aria-hidden="true"></i>
                    View Points Distribution
                </a>
            </div><!-- /helper-card points -->

        </div><!-- /upload-layout__aside -->

    </div><!-- /upload-layout -->

    <?php endif; ?>

</div><!-- /upload-wrapper -->
</main>


<!-- =============================================================================
     SCORING MATRIX — JSON for JS tier cascade + points hint.
============================================================================= -->
<script>
var scoringMatrix = <?= json_encode($scoring_matrix, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script>
(function () {
    'use strict';

    // ── Element references ─────────────────────────────────────────────────────
    var form          = document.getElementById('uploadForm');
    var categoryEl    = document.getElementById('category');
    var tierEl        = document.getElementById('tier');
    var pointsHint    = document.getElementById('pointsHint');
    var pointsHintTxt = document.getElementById('pointsHintText');
    var descEl        = document.getElementById('description');
    var descCount     = document.getElementById('descCharCount');
    var fileInput     = document.getElementById('proofFile');
    var dropzone      = document.getElementById('dropzone');
    var dzIdle        = document.getElementById('dropzoneIdle');
    var dzPreview     = document.getElementById('dropzonePreview');
    var dzFileName    = document.getElementById('dropzoneFileName');
    var dzFileSize    = document.getElementById('dropzoneFileSize');
    var dzFileIcon    = document.getElementById('dropzoneFileIcon');
    var dzRemove      = document.getElementById('dropzoneRemove');
    var fileError     = document.getElementById('fileError');
    var submitBtn     = document.getElementById('submitBtn');

    if (!form) return; // absent on success/closed states


    // ═══════════════════════════════════════════════════════════════════════════
    // 1. CATEGORY → TIER CASCADE + POINTS HINT
    // ═══════════════════════════════════════════════════════════════════════════

    function populateTiers(cat, selectedTier) {
        if (!tierEl) return;
        tierEl.innerHTML = '<option value="" disabled selected>Select tier…</option>';
        if (!cat || !scoringMatrix[cat]) { updateHint(null); return; }
        var tiers = scoringMatrix[cat];
        Object.keys(tiers).forEach(function (t) {
            var opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if (t === selectedTier) opt.selected = true;
            tierEl.appendChild(opt);
        });
        updateHint(selectedTier && tiers[selectedTier] ? tiers[selectedTier] : null);
    }

    function updateHint(pts) {
        if (!pointsHint || !pointsHintTxt) return;
        if (pts === null) {
            pointsHintTxt.textContent = 'Points awarded based on Category & Tier';
            pointsHint.removeAttribute('data-active');
        } else {
            pointsHintTxt.textContent = '+' + pts.toLocaleString() + ' pts on approval';
            pointsHint.setAttribute('data-active', 'true');
        }
    }

    if (categoryEl) {
        categoryEl.addEventListener('change', function () {
            populateTiers(this.value, '');
        });
    }

    if (tierEl) {
        tierEl.addEventListener('change', function () {
            var tiers = (scoringMatrix[categoryEl ? categoryEl.value : ''] || {});
            updateHint(tiers[this.value] || null);
        });
    }

    // Restore cascade on page reload after a failed POST
    if (categoryEl && categoryEl.value) {
        populateTiers(categoryEl.value, tierEl ? tierEl.value : '');
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // 2. DESCRIPTION CHARACTER COUNTER
    // ═══════════════════════════════════════════════════════════════════════════

    function updateCharCount() {
        if (!descEl || !descCount) return;
        var n = descEl.value.length;
        descCount.textContent = n + ' / 1000';
        descCount.classList.toggle('field-charcount--warn', n > 900);
    }

    if (descEl) {
        descEl.addEventListener('input', updateCharCount);
        updateCharCount();
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // 3. FILE HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    var ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    var MAX_BYTES     = 5 * 1024 * 1024;

    function fmtBytes(b) {
        if (b < 1024)        return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function iconForMime(mime) {
        if (mime === 'application/pdf') return 'bi bi-file-earmark-pdf';
        if (mime.startsWith('image/'))  return 'bi bi-file-earmark-image';
        return 'bi bi-file-earmark-check';
    }

    function showFileError(msg) {
        if (!fileError) return;
        fileError.textContent = msg;
        fileError.hidden = false;
        if (dropzone) dropzone.classList.add('dropzone--error');
    }

    function clearFileError() {
        if (!fileError) return;
        fileError.hidden = true;
        fileError.textContent = '';
        if (dropzone) dropzone.classList.remove('dropzone--error');
    }

    function showPreview(file) {
        clearFileError();
        if (dzFileName) dzFileName.textContent = file.name;
        if (dzFileSize) dzFileSize.textContent  = fmtBytes(file.size);
        if (dzFileIcon) dzFileIcon.className     = iconForMime(file.type);
        if (dzIdle)     dzIdle.hidden    = true;
        if (dzPreview)  dzPreview.hidden = false;
        if (dropzone) {
            dropzone.classList.add('dropzone--has-file');
            dropzone.classList.remove('dropzone--drag-over', 'dropzone--error');
        }
    }

    function clearFile() {
        if (fileInput) fileInput.value = '';
        if (dzIdle)    dzIdle.hidden    = false;
        if (dzPreview) dzPreview.hidden = true;
        if (dropzone)  dropzone.classList.remove('dropzone--has-file', 'dropzone--error');
        clearFileError();
    }

    function validateFile(file) {
        if (!file) return;
        if (!ALLOWED_TYPES.includes(file.type)) {
            showFileError('Invalid file type. Only PDF, JPG, and PNG are accepted.');
            clearFile();
            return;
        }
        if (file.size > MAX_BYTES) {
            showFileError('File is too large (' + fmtBytes(file.size) + '). Maximum is 5 MB.');
            clearFile();
            return;
        }
        showPreview(file);
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // 4. DROPZONE INTERACTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            validateFile(this.files[0] || null);
        });
    }

    if (dropzone) {
        dropzone.addEventListener('click', function (e) {
            if (dzRemove && e.target.closest('#dropzoneRemove')) return;
            if (fileInput) fileInput.click();
        });

        dropzone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (fileInput) fileInput.click();
            }
        });

        dropzone.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            dropzone.classList.add('dropzone--drag-over');
        });

        dropzone.addEventListener('dragleave', function (e) {
            if (!dropzone.contains(e.relatedTarget)) {
                dropzone.classList.remove('dropzone--drag-over');
            }
        });

        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropzone.classList.remove('dropzone--drag-over');
            var file = e.dataTransfer.files[0] || null;
            if (!file) return;
            var dt = new DataTransfer();
            dt.items.add(file);
            if (fileInput) fileInput.files = dt.files;
            validateFile(file);
        });
    }

    if (dzRemove) {
        dzRemove.addEventListener('click', function (e) {
            e.stopPropagation();
            clearFile();
        });
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // 5. FORM SUBMIT GUARD
    // ═══════════════════════════════════════════════════════════════════════════

    if (form) {
        form.addEventListener('submit', function (e) {
            var file = fileInput ? (fileInput.files[0] || null) : null;

            if (!file) {
                e.preventDefault();
                showFileError('Please attach a file before submitting.');
                if (dropzone) dropzone.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (!ALLOWED_TYPES.includes(file.type)) {
                e.preventDefault();
                showFileError('Invalid file type. Only PDF, JPG, and PNG are accepted.');
                return;
            }
            if (file.size > MAX_BYTES) {
                e.preventDefault();
                showFileError('File exceeds 5 MB. Please use a smaller file.');
                return;
            }

            if (submitBtn) {
                submitBtn.classList.add('btn-submit-upload--loading');
                submitBtn.setAttribute('aria-disabled', 'true');
            }
        });
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // 6. AUTO-DISMISS ERROR ALERT
    // ═══════════════════════════════════════════════════════════════════════════

    var alertEl = document.getElementById('upload-alert');
    if (alertEl) {
        setTimeout(function () {
            alertEl.style.transition = 'opacity 0.4s ease';
            alertEl.style.opacity    = '0';
            setTimeout(function () {
                if (alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
            }, 420);
        }, 8000);
    }

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>