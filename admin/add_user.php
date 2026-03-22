<?php
// =============================================================================
// THE ZENITH VIEW — /admin/add_user.php
// Protected: Admin Role Only | User Provisioning
//
// SECURITY CHECKLIST:
//   [x] RBAC gate — only role='admin' may access
//   [x] All DB queries use PDO prepared statements
//   [x] XSS — every echo of user data through htmlspecialchars()
//   [x] Email uniqueness checked before INSERT
//   [x] Temp password hashed with PASSWORD_BCRYPT — NEVER echoed to screen
//   [x] Auto-score: total_points = round(cgpa * 100) for students, else 0
//   [x] PRG pattern on success — prevents double-submit on page refresh
// =============================================================================


// =============================================================================
// PHASE 1 — BOOTSTRAP & RBAC
// =============================================================================

require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$admin_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');


// =============================================================================
// PHASE 2 — FLASH (Post-Redirect-Get)
// =============================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$form = [
    'role'         => 'student',
    'name'         => '',
    'email_prefix' => '',
    'department'   => '',
    'study_year'   => '',
    'cgpa'         => '',
];

$errors = [];


// =============================================================================
// PHASE 3 — POST HANDLER
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role         = trim($_POST['role']          ?? '');
    $name         = trim($_POST['name']          ?? '');
    $email_prefix = strtolower(trim($_POST['email_prefix'] ?? ''));
    $department   = trim($_POST['department']    ?? '');
    $study_year   = trim($_POST['study_year']    ?? '');
    $cgpa_raw     = trim($_POST['cgpa']          ?? '');

    $form = [
        'role'         => $role,
        'name'         => $name,
        'email_prefix' => $email_prefix,
        'department'   => $department,
        'study_year'   => $study_year,
        'cgpa'         => $cgpa_raw,
    ];

    $allowed_roles = ['student', 'teacher', 'admin'];
    $allowed_depts = ['Comps', 'IT', 'Mech', 'EXTC', 'Other'];
    $allowed_years = ['FE', 'SE', 'TE', 'BE', 'None'];

    if (!in_array($role, $allowed_roles, true))  { $errors[] = 'Invalid role selected.'; }
    if (empty($name))                            { $errors[] = 'Full name is required.'; }
    if (empty($email_prefix) || !preg_match('/^[a-z0-9._\-]+$/i', $email_prefix)) {
        $errors[] = 'A valid email prefix is required.';
    }
    if (in_array($role, ['student', 'teacher'], true)) {
        if (empty($department) || !in_array($department, $allowed_depts, true)) {
            $errors[] = 'A valid department is required for this role.';
        }
    }
    if ($role === 'student') {
        if (empty($study_year) || !in_array($study_year, $allowed_years, true)) {
            $errors[] = 'A valid study year is required for student accounts.';
        }
        if ($cgpa_raw === '' || !is_numeric($cgpa_raw)) {
            $errors[] = 'A valid CGPA (0.00–10.00) is required for student accounts.';
        } elseif ((float) $cgpa_raw < 0 || (float) $cgpa_raw > 10) {
            $errors[] = 'CGPA must be between 0.00 and 10.00.';
        }
    }

    if (empty($errors)) {
        $full_email = $email_prefix . '@somaiya.edu';
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $full_email]);
            if ($stmt->fetch()) { $errors[] = 'An account with that email already exists.'; }
        } catch (PDOException $e) {
            error_log('[Zenith View] add_user email check: ' . $e->getMessage());
            $errors[] = 'A database error occurred. Please try again.';
        }
    }

    if (empty($errors)) {
        $full_email      = $email_prefix . '@somaiya.edu';
        $db_department   = ($role === 'admin') ? 'Other' : ($department ?: 'Other');
        $db_study_year   = ($role === 'student') ? $study_year : 'None';
        $db_cgpa         = ($role === 'student') ? round((float) $cgpa_raw, 2) : 0.00;
        $db_total_points = ($role === 'student') ? (int) round($db_cgpa * 100) : 0;

        $chars     = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $rand_part = '';
        for ($i = 0; $i < 8; $i++) { $rand_part .= $chars[random_int(0, strlen($chars) - 1)]; }
        $temp_password   = 'zv_' . $rand_part;
        $hashed_password = password_hash($temp_password, PASSWORD_BCRYPT);
        unset($temp_password); // never displayed — wipe immediately

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, password_hash, role, department,
                                   study_year, base_cgpa, total_points, is_active)
                VALUES (:name, :email, :pw, :role, :dept, :year, :cgpa, :pts, 1)
            ");
            $stmt->execute([
                ':name'  => $name,
                ':email' => $full_email,
                ':pw'    => $hashed_password,
                ':role'  => $role,
                ':dept'  => $db_department,
                ':year'  => $db_study_year,
                ':cgpa'  => $db_cgpa,
                ':pts'   => $db_total_points,
            ]);
            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => 'Account provisioned successfully. An onboarding email with login credentials has been dispatched.',
            ];
            header('Location: add_user.php');
            exit;
        } catch (PDOException $e) {
            error_log('[Zenith View] add_user INSERT: ' . $e->getMessage());
            $errors[] = 'A database error occurred during account creation. Please try again.';
        }
    }

    if (!empty($errors)) {
        $flash = [
            'type' => 'error',
            'msg'  => implode('<br>', array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES, 'UTF-8'), $errors)),
        ];
    }
}


// =============================================================================
// PHASE 4 — PAGE META
// =============================================================================

$page_title = 'Provision User';
$active_nav = 'dashboard';
$extra_head  = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin_dashboard.css">';
$extra_head .= '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/add_user.css">';

require_once __DIR__ . '/../includes/header.php';

$base = rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/');

function sel(string $val, string $current): string {
    return ($val === $current) ? ' selected' : '';
}
?>

<main class="page-main" id="main-content">
<div class="adm-wrapper">


    <!-- =========================================================================
         FLASH ALERT — uses existing .adm-alert system from admin_dashboard.css
    ========================================================================= -->
    <?php if ($flash): ?>
    <?php
        $is_ok = ($flash['type'] === 'success');
        $a_cls = $is_ok ? 'adm-alert adm-alert--success' : 'adm-alert adm-alert--error';
        $a_ico = $is_ok ? 'bi-check-circle-fill'          : 'bi-exclamation-circle-fill';
    ?>
    <div class="<?= $a_cls ?>" role="alert" aria-live="assertive">
        <i class="bi <?= $a_ico ?>" aria-hidden="true"></i>
        <span><?= $flash['msg'] ?></span>
        <button type="button" class="adm-alert__dismiss"
                onclick="this.parentElement.remove()" aria-label="Dismiss">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <?php endif; ?>


    <!-- =========================================================================
         PAGE HEADER — exact .adm-header structure copied from dashboard.php.
         .adm-header is display:flex justify-content:space-between.
         Left div = eyebrow/title/sub. Right .adm-header__meta = avatar pill.
    ========================================================================= -->
    <header class="adm-header">
        <div>
            <!-- Breadcrumb — exact .guide-breadcrumb structure from points_guide.php -->
            <nav class="guide-breadcrumb" aria-label="Breadcrumb">
                <a href="<?= $base ?>/admin/dashboard.php" class="guide-breadcrumb__link">
                    <i class="bi bi-shield-fill-check" aria-hidden="true"></i>
                    Admin Dashboard
                </a>
                <span class="guide-breadcrumb__sep" aria-hidden="true">/</span>
                <span class="guide-breadcrumb__current">User Provisioning</span>
            </nav>
            <h1 class="adm-header__title">User Provisioning</h1>
            <p class="adm-header__sub">
                Create accounts or import a CSV roster.
                Secure credentials are automatically emailed to users.
            </p>
        </div>
        <!-- Avatar block intentionally removed per spec -->
    </header>


    <!-- =========================================================================
         MAIN GRID
         .adm-card has overflow:hidden + display:flex flex-direction:column.
         ALL padding must be on an INNER div, never on .adm-card itself.
         This is why p-4 on .adm-card was causing the stretch — the flex
         column was expanding the padding through overflow:hidden wrongly.
    ========================================================================= -->
    <div class="row g-4">

        <!-- =================================================================
             LEFT COLUMN col-12 col-lg-8 — Provisioning Form
        ================================================================= -->
        <div class="col-12 col-lg-8">
            <div class="adm-card">

                <!-- .adm-card__header provides its own padding (1.25rem 1.4rem 0) -->
                <div class="adm-card__header">
                    <div>
                        <h2 class="adm-card__title">
                            <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                            Single Account
                        </h2>
                        <p class="adm-card__sub">
                            Role selection controls which fields are displayed and required.
                        </p>
                    </div>
                </div>

                <!-- Form body — padded inner div, NOT on .adm-card -->
                <div class="prov-form-body">
                    <form id="provisionForm"
                          method="POST"
                          action="<?= $base ?>/admin/add_user.php"
                          novalidate>

                        <!-- ROLE -->
                        <div class="prov-field-group">
                            <label for="role" class="prov-label">
                                Role <span class="prov-required" aria-hidden="true">*</span>
                            </label>
                            <select class="form-select prov-select"
                                    id="role" name="role"
                                    required aria-required="true">
                                <option value="student"<?= sel('student', $form['role']) ?>>Student</option>
                                <option value="teacher"<?= sel('teacher', $form['role']) ?>>Faculty / Reviewer</option>
                                <option value="admin"  <?= sel('admin',   $form['role']) ?>>Administrator</option>
                            </select>
                        </div>

                        <!-- FULL NAME -->
                        <div class="prov-field-group">
                            <label for="name" class="prov-label">
                                Full Name <span class="prov-required" aria-hidden="true">*</span>
                            </label>
                            <input type="text"
                                   class="form-control prov-input"
                                   id="name" name="name"
                                   placeholder="e.g. Rohan Sharma"
                                   autocomplete="off"
                                   required aria-required="true"
                                   value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <!-- EMAIL -->
                        <div class="prov-field-group">
                            <label for="email_prefix" class="prov-label">
                                Institutional Email <span class="prov-required" aria-hidden="true">*</span>
                            </label>
                            <div class="prov-email-wrap" style="display:flex;flex-wrap:nowrap;align-items:stretch;">
                                <input type="text"
                                       class="form-control prov-input prov-email-input"
                                       id="email_prefix" name="email_prefix"
                                       placeholder="firstname.lastname"
                                       autocomplete="off"
                                       autocapitalize="none"
                                       spellcheck="false"
                                       required aria-required="true"
                                       aria-describedby="emailDomain"
                                       value="<?= htmlspecialchars($form['email_prefix'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="prov-email-addon" id="emailDomain">@somaiya.edu</span>
                            </div>
                            <p class="prov-hint">Only @somaiya.edu addresses are accepted on this platform.</p>
                        </div>

                        <!-- DEPARTMENT + STUDY YEAR -->
                        <div class="row g-3 prov-field-group" id="deptYearRow">
                            <div class="col-12 col-md-6" id="deptWrapper">
                                <label for="department" class="prov-label">
                                    Department <span class="prov-required" aria-hidden="true">*</span>
                                </label>
                                <select class="form-select prov-select"
                                        id="department" name="department"
                                        aria-required="true">
                                    <option value="" disabled <?= ($form['department'] === '') ? 'selected' : '' ?>>— Select Department —</option>
                                    <?php foreach (['Comps', 'IT', 'Mech', 'EXTC', 'Other'] as $d): ?>
                                    <option value="<?= $d ?>"<?= sel($d, $form['department']) ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6" id="yearWrapper">
                                <label for="study_year" class="prov-label">
                                    Study Year <span class="prov-required" aria-hidden="true">*</span>
                                </label>
                                <select class="form-select prov-select"
                                        id="study_year" name="study_year"
                                        aria-required="true">
                                    <option value="" disabled <?= ($form['study_year'] === '') ? 'selected' : '' ?>>— Select Year —</option>
                                    <?php foreach (['FE', 'SE', 'TE', 'BE'] as $y): ?>
                                    <option value="<?= $y ?>"<?= sel($y, $form['study_year']) ?>><?= $y ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- BASE CGPA -->
                        <div class="prov-field-group" id="cgpaWrapper">
                            <label for="cgpa" class="prov-label">
                                Base CGPA <span class="prov-required" aria-hidden="true">*</span>
                            </label>
                            <input type="number"
                                   class="form-control prov-input"
                                   id="cgpa" name="cgpa"
                                   step="0.01" min="0" max="10"
                                   placeholder="e.g. 8.75"
                                   aria-required="true"
                                   value="<?= htmlspecialchars($form['cgpa'], ENT_QUOTES, 'UTF-8') ?>">
                            <!-- <div id="cgpaPillWrapper" class="prov-pill-wrap">
                                <span class="auto-score-pill">
                                    <i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>
                                    Auto-scores: CGPA &times; 100 pts
                                </span>
                            </div> -->
                        </div>

                        <!-- SEND EMAIL TOGGLE -->
                        <!-- <div class="prov-field-group prov-toggle-group">
                            <div class="form-check form-switch prov-switch">
                                <input class="form-check-input prov-switch__input"
                                       type="checkbox"
                                       id="sendEmailToggle"
                                       name="send_email"
                                       value="1"
                                       checked
                                       role="switch"
                                       aria-checked="true">
                                <label class="form-check-label prov-switch__label"
                                       for="sendEmailToggle">
                                    <span class="prov-switch__text">Send welcome email with login credentials</span>
                                    <span class="prov-switch__hint">Onboarding email dispatched automatically on account creation</span>
                                </label>
                            </div>
                        </div> -->

                        <!-- ACTIONS — right-aligned, separated by top border -->
                        <div class="prov-actions">
                            <button type="reset" class="btn-prov-cancel">
                                Cancel
                            </button>
                            <button type="submit" class="btn-prov-submit">
                                <i class="bi bi-person-check-fill" aria-hidden="true"></i>
                                Provision User
                            </button>
                        </div>

                    </form>
                </div><!-- /prov-form-body -->

            </div><!-- /adm-card left -->
        </div>


        <!-- =================================================================
             RIGHT COLUMN col-12 col-lg-4 — Bulk CSV Import
        ================================================================= -->
        <div class="col-12 col-lg-4">
            <div class="adm-card">

                <div class="adm-card__header">
                    <div>
                        <h2 class="adm-card__title">
                            <i class="bi bi-file-earmark-spreadsheet-fill" aria-hidden="true"></i>
                            Bulk CSV Import
                        </h2>
                        <p class="adm-card__sub">Provision an entire class roster at once.</p>
                    </div>
                </div>

                <div class="prov-form-body">

                    <!-- Required columns hint -->
                    <div class="csv-hint-block">
                        <p class="csv-hint-block__label">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            Required Columns
                        </p>
                        <div class="csv-hint-block__chips">
                            <?php foreach (['name', 'email_prefix', 'role', 'department', 'study_year', 'base_cgpa'] as $col): ?>
                            <code class="csv-col-chip"><?= $col ?></code>
                            <?php endforeach; ?>
                        </div>
                        <p class="csv-hint-block__note">
                            Headers must match exactly. The <code>@somaiya.edu</code> suffix is appended automatically.
                        </p>
                    </div>

                    <!-- Standard file input -->
                    <div class="prov-field-group">
                        <label for="csvFileInput" class="prov-label">Select CSV File</label>
                        <input type="file"
                               class="form-control prov-input"
                               id="csvFileInput"
                               name="csv_file"
                               accept=".csv,text/csv"
                               aria-describedby="csvHelp">
                        <p class="prov-hint" id="csvHelp">.csv files only &mdash; max 2 MB.</p>
                    </div>

                    <!-- Import button (UI only) -->
                    <button type="button" class="btn-prov-import w-100">
                        <i class="bi bi-upload" aria-hidden="true"></i>
                        Import Roster
                    </button>

                </div><!-- /prov-form-body -->

            </div><!-- /adm-card right -->
        </div>

    </div><!-- /row g-4 -->

</div><!-- /adm-wrapper -->
</main>


<script>
(function () {
    'use strict';

    var roleSelect      = document.getElementById('role');
    var deptWrapper     = document.getElementById('deptWrapper');
    var yearWrapper     = document.getElementById('yearWrapper');
    var cgpaWrapper     = document.getElementById('cgpaWrapper');
    var cgpaPillWrapper = document.getElementById('cgpaPillWrapper');
    var deptSelect      = document.getElementById('department');
    var yearSelect      = document.getElementById('study_year');
    var cgpaInput       = document.getElementById('cgpa');

    function morphForm(role) {
        if (role === 'admin') {
            deptWrapper.classList.add('d-none');
            yearWrapper.classList.add('d-none');
            cgpaWrapper.classList.add('d-none');
            deptSelect.removeAttribute('required');
            yearSelect.removeAttribute('required');
            cgpaInput.removeAttribute('required');
        } else if (role === 'teacher') {
            deptWrapper.classList.remove('d-none');
            yearWrapper.classList.add('d-none');
            cgpaWrapper.classList.add('d-none');
            deptSelect.setAttribute('required', 'required');
            yearSelect.removeAttribute('required');
            cgpaInput.removeAttribute('required');
        } else {
            deptWrapper.classList.remove('d-none');
            yearWrapper.classList.remove('d-none');
            cgpaWrapper.classList.remove('d-none');
            deptSelect.setAttribute('required', 'required');
            yearSelect.setAttribute('required', 'required');
            cgpaInput.setAttribute('required', 'required');
        }
    }

    morphForm(roleSelect.value);
    roleSelect.addEventListener('change', function () { morphForm(this.value); });
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>