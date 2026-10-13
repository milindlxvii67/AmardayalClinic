<?php
require_once '../config.php';
require_staff();

$role = $_SESSION['staff_role'] ?? '';
$staffUsername = $_SESSION['staff_username'] ?? '';
$staffName = $_SESSION['staff_name'] ?? $staffUsername ?: 'Administrator';

if ($role !== 'Admin') {
    redirect('index.php');
}

function adm_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function adm_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function adm_get_users(mysqli $conn): array
{
    $rows = [];
    $result = $conn->query(
        "SELECT id, username, role, name, email, phone, status, created_at
         FROM users
         ORDER BY
            CASE LOWER(role)
                WHEN 'admin' THEN 1
                WHEN 'doctor' THEN 2
                WHEN 'reception' THEN 3
                ELSE 4
            END,
            id ASC"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

$success = '';
$error = '';

/* -----------------------------------------------------------
   Ensure audit / holiday tables exist
----------------------------------------------------------- */
$conn->query("
    CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NULL,
        action VARCHAR(120) NULL,
        details TEXT NULL,
        timestamp DATETIME NULL,
        INDEX idx_audit_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS clinic_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_date DATE NOT NULL UNIQUE,
        holiday_name VARCHAR(180) NOT NULL,
        tradition VARCHAR(80) DEFAULT 'Clinic Holiday',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* -----------------------------------------------------------
   POST actions
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* -------------------------------------------------------
       Add staff
    ------------------------------------------------------- */
    if ($action === 'add_staff') {

        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $newRole = strtolower(trim($_POST['role'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            $error = 'Please enter a valid username. Use 3–60 letters, numbers, dots, underscores or hyphens.';
        } elseif ($fullName === '') {
            $error = 'Please enter the staff member name.';
        } elseif (!in_array($newRole, ['admin', 'doctor', 'reception'], true)) {
            $error = 'Please select a valid staff role.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must contain at least 8 characters.';
        } else {

            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");

            if (!$stmt) {
                $error = 'Unable to check the username.';
            } else {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $error = 'That username already exists.';
                } else {

                    $hash = adm_hash_password($password);

                    $stmt = $conn->prepare(
                        "INSERT INTO users
                            (username, password, role, name, status)
                         VALUES (?, ?, ?, ?, 'active')"
                    );

                    if (!$stmt) {
                        $error = 'Unable to create the staff account.';
                    } else {

                        $stmt->bind_param(
                            'ssss',
                            $username,
                            $hash,
                            $newRole,
                            $fullName
                        );

                        if ($stmt->execute()) {
                            $success = 'Staff account created successfully.';

                            if (function_exists('audit')) {
                                audit(
                                    $staffUsername ?: $staffName,
                                    'CREATE_STAFF',
                                    'Created ' . ucfirst($newRole) . ' account: ' . $username
                                );
                            }
                        } else {
                            $error = 'Could not create the staff account: ' . $stmt->error;
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }

    /* -------------------------------------------------------
       Toggle account
    ------------------------------------------------------- */
    elseif ($action === 'toggle_staff') {

        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $error = 'Invalid staff account.';
        } else {

            $stmt = $conn->prepare(
                "SELECT id, username, role, status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                $user = null;
            }

            if (!$user) {
                $error = 'Staff account not found.';
            } elseif ($user['username'] === $staffUsername) {
                $error = 'You cannot deactivate your own account while signed in.';
            } else {

                $newStatus = (strtolower((string)$user['status']) === 'active')
                    ? 'inactive'
                    : 'active';

                $stmt = $conn->prepare(
                    "UPDATE users SET status = ? WHERE id = ?"
                );

                if ($stmt) {
                    $stmt->bind_param('si', $newStatus, $userId);

                    if ($stmt->execute()) {
                        $success = ($newStatus === 'active')
                            ? 'Staff account activated.'
                            : 'Staff account deactivated.';

                        if (function_exists('audit')) {
                            audit(
                                $staffUsername ?: $staffName,
                                ($newStatus === 'active') ? 'ACTIVATE_STAFF' : 'DEACTIVATE_STAFF',
                                $user['username'] . ' (' . $user['role'] . ')'
                            );
                        }
                    } else {
                        $error = 'Unable to update the account.';
                    }

                    $stmt->close();
                }
            }
        }
    }

    /* -------------------------------------------------------
       Reset staff password
    ------------------------------------------------------- */
    elseif ($action === 'reset_staff_password') {

        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($userId <= 0) {
            $error = 'Invalid staff account.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must contain at least 8 characters.';
        } else {

            $stmt = $conn->prepare(
                "SELECT username FROM users WHERE id = ? LIMIT 1"
            );

            $username = '';

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $username = $row['username'] ?? '';
                $stmt->close();
            }

            if ($username === '') {
                $error = 'Staff account not found.';
            } else {

                $hash = adm_hash_password($newPassword);

                $stmt = $conn->prepare(
                    "UPDATE users SET password = ? WHERE id = ?"
                );

                if ($stmt) {
                    $stmt->bind_param('si', $hash, $userId);

                    if ($stmt->execute()) {
                        $success = 'Password updated for ' . $username . '.';

                        if (function_exists('audit')) {
                            audit(
                                $staffUsername ?: $staffName,
                                'RESET_STAFF_PASSWORD',
                                'Password reset for ' . $username
                            );
                        }
                    } else {
                        $error = 'Unable to update the password.';
                    }

                    $stmt->close();
                }
            }
        }
    }

    /* -------------------------------------------------------
       Add clinic holiday
    ------------------------------------------------------- */
    elseif ($action === 'add_holiday') {

        $holidayDate = trim($_POST['holiday_date'] ?? '');
        $holidayName = trim($_POST['holiday_name'] ?? '');
        $tradition = trim($_POST['tradition'] ?? 'Clinic Holiday');

        $dateObject = DateTime::createFromFormat('Y-m-d', $holidayDate);

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d') !== $holidayDate
        ) {
            $error = 'Please choose a valid holiday date.';
        } elseif ($holidayName === '') {
            $error = 'Please enter the holiday name.';
        } else {

            $stmt = $conn->prepare(
                "INSERT INTO clinic_holidays
                    (holiday_date, holiday_name, tradition)
                 VALUES (?, ?, ?)"
            );

            if ($stmt) {
                $stmt->bind_param(
                    'sss',
                    $holidayDate,
                    $holidayName,
                    $tradition
                );

                if ($stmt->execute()) {
                    $success = 'Clinic holiday added successfully.';

                    if (function_exists('audit')) {
                        audit(
                            $staffUsername ?: $staffName,
                            'ADD_HOLIDAY',
                            $holidayDate . ' - ' . $holidayName
                        );
                    }
                } else {
                    $error = (
                        $conn->errno === 1062
                            ? 'A holiday is already registered for that date.'
                            : 'Unable to add the holiday.'
                    );
                }

                $stmt->close();
            }
        }
    }

    /* -------------------------------------------------------
       Delete holiday
    ------------------------------------------------------- */
    elseif ($action === 'delete_holiday') {

        $holidayId = (int)($_POST['holiday_id'] ?? 0);

        if ($holidayId <= 0) {
            $error = 'Invalid holiday.';
        } else {

            $stmt = $conn->prepare(
                "DELETE FROM clinic_holidays WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $holidayId);

                if ($stmt->execute()) {
                    $success = 'Holiday removed.';

                    if (function_exists('audit')) {
                        audit(
                            $staffUsername ?: $staffName,
                            'DELETE_HOLIDAY',
                            'Holiday ID ' . $holidayId
                        );
                    }
                } else {
                    $error = 'Unable to remove the holiday.';
                }

                $stmt->close();
            }
        }
    }

    /* -------------------------------------------------------
       Change own password
    ------------------------------------------------------- */
    elseif ($action === 'change_my_password') {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '') {
            $error = 'Enter both your current and new password.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must contain at least 8 characters.';
        } else {

            $stmt = $conn->prepare(
                "SELECT password
                 FROM users
                 WHERE username = ?
                 LIMIT 1"
            );

            $currentHash = '';

            if ($stmt) {
                $stmt->bind_param('s', $staffUsername);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $currentHash = $row['password'] ?? '';
                $stmt->close();
            }

            /*
             * Support the PHP password_hash() format used by the web app.
             * Also support the legacy SHA-256 salted hash from the original
             * desktop application, so existing accounts are not locked out.
             */
            $valid = false;

            if ($currentHash !== '') {
                $valid = password_verify($currentPassword, $currentHash);

                if (!$valid) {
                    $legacyHash = hash(
                        'sha256',
                        'amardayal_clinic_enterprise_salt_v2' . $currentPassword
                    );

                    $valid = hash_equals($currentHash, $legacyHash);
                }
            }

            if (!$valid) {
                $error = 'Current password is incorrect.';
            } else {

                $newHash = adm_hash_password($newPassword);

                $stmt = $conn->prepare(
                    "UPDATE users
                     SET password = ?
                     WHERE username = ?"
                );

                if ($stmt) {
                    $stmt->bind_param(
                        'ss',
                        $newHash,
                        $staffUsername
                    );

                    if ($stmt->execute()) {
                        $success = 'Your password has been changed successfully.';

                        if (function_exists('audit')) {
                            audit(
                                $staffUsername ?: $staffName,
                                'CHANGE_OWN_PASSWORD',
                                'Administrator changed own password'
                            );
                        }
                    } else {
                        $error = 'Unable to change your password.';
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/* -----------------------------------------------------------
   Data for page
----------------------------------------------------------- */
$users = adm_get_users($conn);

$holidays = [];

$result = $conn->query(
    "SELECT id, holiday_date, holiday_name, tradition, created_at
     FROM clinic_holidays
     ORDER BY holiday_date ASC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $holidays[] = $row;
    }
}

$auditRows = [];

$result = $conn->query(
    "SELECT id, username, action, details, timestamp
     FROM audit_log
     ORDER BY id DESC
     LIMIT 100"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $auditRows[] = $row;
    }
}

$activeUsers = 0;
$doctorUsers = 0;
$receptionUsers = 0;

foreach ($users as $u) {
    if (strtolower((string)$u['status']) === 'active') {
        $activeUsers++;
    }

    if (strtolower((string)$u['role']) === 'doctor') {
        $doctorUsers++;
    }

    if (strtolower((string)$u['role']) === 'reception') {
        $receptionUsers++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administration | AmarDayal Clinic</title>
<link rel="stylesheet" href="../Styles/style.css">

<style>
*{box-sizing:border-box}

html,body{
    width:100%;
    min-height:100%;
    margin:0;
    padding:0;
    overflow-x:hidden;
}

body{
    background:#f4f7fb;
    color:#10233f;
    font-family:"Segoe UI",Arial,sans-serif;
}

/* =========================================================
   SHARED ADMIN SIDEBAR / CONTENT LAYOUT
   sidebar.php is the single shared sidebar for the portal.
   ========================================================= */

.layout{
    display:block !important;
    position:relative;
    width:100%;
    min-height:100vh;
    margin:0;
    padding:0;
}

/*
 * The shared sidebar used by the admin portal is 290px wide.
 * Reserve its complete width so Administration never renders
 * underneath it.
 */

.layout > .main{
    position:relative;
    display:block;
    width:calc(100% - 290px) !important;
    max-width:none !important;
    min-width:0;
    min-height:100vh;
    margin-left:290px !important;
    padding:26px;
    box-sizing:border-box;
    overflow:visible;
}

.page-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px
}

.page-header h1{
    margin:0;
    font-size:30px
}

.page-header p{
    margin:6px 0 0;
    color:#64748b
}

.user-box{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:12px 16px;
    box-shadow:0 5px 18px rgba(15,23,42,.05);
    color:#64748b;
    font-size:13px
}

.user-box strong{
    display:block;
    color:#10233f;
    margin-top:3px
}

.alert{
    border-radius:13px;
    padding:13px 16px;
    margin-bottom:18px;
    font-weight:700
}

.alert.success{
    background:#ecfdf5;
    border:1px solid #a7f3d0;
    color:#047857
}

.alert.error{
    background:#fef2f2;
    border:1px solid #fecaca;
    color:#b91c1c
}

.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px
}

.stat{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:18px;
    box-shadow:0 5px 18px rgba(15,23,42,.05)
}

.stat span{
    display:block;
    color:#64748b;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.05em
}

.stat strong{
    display:block;
    font-size:27px;
    margin-top:6px
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
    margin-bottom:18px
}

.card{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:18px;
    box-shadow:0 5px 18px rgba(15,23,42,.05);
    overflow:hidden
}

.card-head{
    padding:18px 20px;
    border-bottom:1px solid #e5e7eb;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px
}

.card-head h2{
    margin:0;
    font-size:18px
}

.card-head small{
    color:#64748b
}

.card-body{
    padding:20px
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:13px
}

.field.full{
    grid-column:1/-1
}

label{
    display:block;
    font-size:12px;
    font-weight:900;
    margin-bottom:6px
}

input,select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:10px;
    padding:10px 11px;
    font:inherit;
    color:#10233f;
    background:#fff
}

input:focus,select:focus{
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.1)
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:10px;
    padding:10px 14px;
    font-weight:900;
    cursor:pointer;
    text-decoration:none
}

.btn-primary{background:#2563eb;color:#fff}
.btn-success{background:#059669;color:#fff}
.btn-secondary{background:#f1f5f9;color:#334155}
.btn-danger{background:#fef2f2;color:#b91c1c}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:15px
}

.table-wrap{
    overflow:auto
}

table{
    width:100%;
    border-collapse:collapse
}

th,td{
    padding:12px 14px;
    border-bottom:1px solid #edf2f7;
    text-align:left;
    font-size:13px;
    vertical-align:middle
}

th{
    background:#f8fafc;
    color:#475569;
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.04em
}

.badge{
    display:inline-flex;
    padding:5px 9px;
    border-radius:999px;
    font-size:10px;
    font-weight:900
}

.badge.active{
    background:#ecfdf5;
    color:#047857
}

.badge.off{
    background:#fef2f2;
    color:#b91c1c
}

.badge.admin{
    background:#ede9fe;
    color:#6d28d9
}

.badge.doctor{
    background:#eff6ff;
    color:#1d4ed8
}

.badge.reception{
    background:#fff7ed;
    color:#c2410c
}

.inline-form{
    display:inline
}

.small-input{
    width:145px;
    padding:7px 8px
}

.empty{
    text-align:center;
    color:#64748b;
    padding:30px
}

.security-note{
    padding:13px 15px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1e40af;
    border-radius:12px;
    font-size:12px;
    line-height:1.6;
    margin-bottom:15px
}

@media(max-width:1100px){

    .stats{grid-template-columns:repeat(2,1fr)}
    .grid{grid-template-columns:1fr}

    .layout > .main{
        width:calc(100% - 290px) !important;
        margin-left:290px !important;
    }
}

@media(max-width:700px){

    .layout{
        display:block !important;
    }

    .layout > .main{
        width:100% !important;
        margin-left:0 !important;
        min-height:auto;
        padding:18px;
    }

    .stats{grid-template-columns:1fr}

    .page-header{
        flex-direction:column
    }

    .form-grid{
        grid-template-columns:1fr
    }

    .field.full{
        grid-column:auto
    }
}
</style>
</head>

<body>

<div class="layout">

<?php require_once __DIR__ . '/sidebar.php'; ?>

<main class="main">

<div class="page-header">

    <div>
        <h1>Administration</h1>
        <p>Staff accounts, security, clinic holidays and system audit.</p>
    </div>

    <div class="user-box">
        Administrator
        <strong><?= adm_e($staffName) ?></strong>
    </div>

</div>

<?php if ($success !== ''): ?>
    <div class="alert success"><?= adm_e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert error"><?= adm_e($error) ?></div>
<?php endif; ?>

<section class="stats">

    <div class="stat">
        <span>Total Staff</span>
        <strong><?= number_format(count($users)) ?></strong>
    </div>

    <div class="stat">
        <span>Active Accounts</span>
        <strong><?= number_format($activeUsers) ?></strong>
    </div>

    <div class="stat">
        <span>Doctors</span>
        <strong><?= number_format($doctorUsers) ?></strong>
    </div>

    <div class="stat">
        <span>Reception Staff</span>
        <strong><?= number_format($receptionUsers) ?></strong>
    </div>

</section>

<div class="grid">

<!-- CREATE STAFF -->
<section class="card">

    <div class="card-head">
        <div>
            <h2>Create Staff Account</h2>
            <small>Add Admin, Doctor or Reception users.</small>
        </div>
    </div>

    <div class="card-body">

        <div class="security-note">
            Staff passwords are stored using PHP's secure password hashing.
            Give each staff member an individual account rather than sharing credentials.
        </div>

        <form method="post">

            <input type="hidden" name="action" value="add_staff">

            <div class="form-grid">

                <div class="field">
                    <label>Full Name</label>
                    <input
                        type="text"
                        name="full_name"
                        placeholder="e.g. Dr. Tenzin..."
                        required
                    >
                </div>

                <div class="field">
                    <label>Username</label>
                    <input
                        type="text"
                        name="username"
                        placeholder="e.g. doctor2"
                        required
                    >
                </div>

                <div class="field">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="doctor">Doctor</option>
                        <option value="reception">Reception</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div class="field">
                    <label>Initial Password</label>
                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        placeholder="Minimum 8 characters"
                        required
                    >
                </div>

            </div>

            <div class="actions">
                <button class="btn btn-primary" type="submit">
                    + Create Account
                </button>
            </div>

        </form>

    </div>

</section>

<!-- CHANGE OWN PASSWORD -->
<section class="card">

    <div class="card-head">
        <div>
            <h2>My Security</h2>
            <small>Change your administrator password.</small>
        </div>
    </div>

    <div class="card-body">

        <form method="post">

            <input type="hidden" name="action" value="change_my_password">

            <div class="field" style="margin-bottom:13px">
                <label>Current Password</label>
                <input
                    type="password"
                    name="current_password"
                    required
                >
            </div>

            <div class="field">
                <label>New Password</label>
                <input
                    type="password"
                    name="new_password"
                    minlength="8"
                    placeholder="Minimum 8 characters"
                    required
                >
            </div>

            <div class="actions">
                <button class="btn btn-success" type="submit">
                    🔐 Change Password
                </button>
            </div>

        </form>

    </div>

</section>

</div>

<!-- STAFF ACCOUNTS -->
<section class="card" style="margin-bottom:18px">

    <div class="card-head">

        <div>
            <h2>Staff Accounts</h2>
            <small>Manage access to the clinic management system.</small>
        </div>

    </div>

    <div class="table-wrap">

    <table>

        <thead>
            <tr>
                <th>Staff</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($users as $user): ?>

            <tr>

                <td>
                    <strong><?= adm_e($user['name']) ?></strong>
                </td>

                <td><?= adm_e($user['username']) ?></td>

                <td>
                    <span class="badge <?= adm_e(strtolower((string)$user['role'])) ?>">
                        <?= adm_e($user['role']) ?>
                    </span>
                </td>

                <td>
                    <span class="badge <?= strtolower((string)$user['status']) === 'active'  ? 'active' : 'off' ?>">
                        <?= strtolower((string)$user['status']) === 'active'  ? 'Active' : 'Disabled' ?>
                    </span>
                </td>

                <td>
                    <?= $user['created_at']
                        ? adm_e(date('d M Y', strtotime($user['created_at'])))
                        : '—'
                    ?>
                </td>

                <td>

                    <?php if ($user['username'] === $staffUsername): ?>

                        <span style="font-size:12px;color:#64748b">
                            Current account
                        </span>

                    <?php else: ?>

                        <form
                            class="inline-form"
                            method="post"
                            onsubmit="return confirm('Change this account status?');"
                        >
                            <input type="hidden" name="action" value="toggle_staff">
                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                            <button class="btn btn-secondary" type="submit">
                                <?= strtolower((string)$user['status']) === 'active'  ? 'Disable' : 'Activate' ?>
                            </button>
                        </form>

                        <form
                            class="inline-form"
                            method="post"
                            style="margin-left:5px"
                            onsubmit="return confirm('Reset this staff member password?');"
                        >
                            <input type="hidden" name="action" value="reset_staff_password">
                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                            <input
                                class="small-input"
                                type="password"
                                name="new_password"
                                minlength="8"
                                placeholder="New password"
                                required
                            >

                            <button class="btn btn-primary" type="submit">
                                Reset
                            </button>
                        </form>

                    <?php endif; ?>

                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    </div>

</section>

<div class="grid">

<!-- HOLIDAYS -->
<section class="card">

    <div class="card-head">

        <div>
            <h2>Clinic Holidays</h2>
            <small>These dates can be used by appointment scheduling.</small>
        </div>

    </div>

    <div class="card-body">

        <form method="post">

            <input type="hidden" name="action" value="add_holiday">

            <div class="form-grid">

                <div class="field">
                    <label>Date</label>
                    <input type="date" name="holiday_date" required>
                </div>

                <div class="field">
                    <label>Holiday Name</label>
                    <input
                        type="text"
                        name="holiday_name"
                        placeholder="e.g. Clinic Holiday"
                        required
                    >
                </div>

                <div class="field full">
                    <label>Tradition / Category</label>
                    <input
                        type="text"
                        name="tradition"
                        value="Clinic Holiday"
                    >
                </div>

            </div>

            <div class="actions">
                <button class="btn btn-primary" type="submit">
                    + Add Holiday
                </button>
            </div>

        </form>

        <div style="margin-top:20px">

        <?php if (!$holidays): ?>

            <div class="empty">
                No database holidays have been added.
            </div>

        <?php else: ?>

            <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Holiday</th>
                        <th>Category</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($holidays as $holiday): ?>

                    <tr>

                        <td>
                            <?= adm_e(date('d M Y', strtotime($holiday['holiday_date']))) ?>
                        </td>

                        <td>
                            <strong><?= adm_e($holiday['holiday_name']) ?></strong>
                        </td>

                        <td><?= adm_e($holiday['tradition']) ?></td>

                        <td>

                            <form
                                method="post"
                                onsubmit="return confirm('Remove this holiday?');"
                            >
                                <input type="hidden" name="action" value="delete_holiday">
                                <input type="hidden" name="holiday_id" value="<?= (int)$holiday['id'] ?>">

                                <button class="btn btn-danger" type="submit">
                                    Remove
                                </button>
                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

            </div>

        <?php endif; ?>

        </div>

    </div>

</section>

<!-- SYSTEM INFO -->
<section class="card">

    <div class="card-head">
        <div>
            <h2>System Information</h2>
            <small>Current management system configuration.</small>
        </div>
    </div>

    <div class="card-body">

        <div class="security-note">
            <strong>Access control</strong><br>
            Administration is restricted to the Admin role.
        </div>

        <div class="metric-list">

            <div style="display:flex;justify-content:space-between;padding:11px 0;border-bottom:1px solid #edf2f7">
                <span>Clinic Management</span>
                <strong>AmarDayal Clinic</strong>
            </div>

            <div style="display:flex;justify-content:space-between;padding:11px 0;border-bottom:1px solid #edf2f7">
                <span>Database</span>
                <strong>MySQL</strong>
            </div>

            <div style="display:flex;justify-content:space-between;padding:11px 0;border-bottom:1px solid #edf2f7">
                <span>Staff Accounts</span>
                <strong><?= number_format(count($users)) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;padding:11px 0;border-bottom:1px solid #edf2f7">
                <span>Audit Records Shown</span>
                <strong><?= number_format(count($auditRows)) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;padding:11px 0">
                <span>Clinic Holidays</span>
                <strong><?= number_format(count($holidays)) ?></strong>
            </div>

        </div>

    </div>

</section>

</div>

<!-- AUDIT LOG -->
<section class="card">

    <div class="card-head">

        <div>
            <h2>System Audit Trail</h2>
            <small>Latest 100 recorded administrative activities.</small>
        </div>

        <a class="btn btn-secondary" href="administration.php">
            ↻ Refresh
        </a>

    </div>

    <div class="table-wrap">

    <?php if (!$auditRows): ?>

        <div class="empty">
            No audit records are available yet.
        </div>

    <?php else: ?>

        <table>

            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($auditRows as $log): ?>

                <tr>

                    <td>
                        <?= $log['timestamp']
                            ? adm_e(date('d M Y H:i', strtotime($log['timestamp'])))
                            : '—'
                        ?>
                    </td>

                    <td>
                        <strong><?= adm_e($log['username']) ?></strong>
                    </td>

                    <td>
                        <span class="badge admin">
                            <?= adm_e($log['action']) ?>
                        </span>
                    </td>

                    <td><?= adm_e($log['details']) ?></td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>

    </div>

</section>

</main>

</div>

</body>
</html>
