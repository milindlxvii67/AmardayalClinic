<?php
require_once __DIR__ . '/../config.php';
require_staff();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$patientId = (int)($_GET['id'] ?? $_POST['patient_id'] ?? 0);
$error = '';
$success = '';

/* ============================================================
   HELPERS
   ============================================================ */
function pp_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pp_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tableSafe = str_replace('`', '``', $table);
    $columnSafe = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'"
    );

    return $cache[$key] = ($result && $result->num_rows > 0);
}

function pp_value(array $row, array $keys, string $fallback = '—'): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $fallback;
}

function pp_date($value, string $fallback = '—'): string
{
    if (!$value) {
        return $fallback;
    }

    $time = strtotime((string)$value);

    return $time ? date('d M Y', $time) : (string)$value;
}

function pp_datetime($value, string $fallback = '—'): string
{
    if (!$value) {
        return $fallback;
    }

    $time = strtotime((string)$value);

    return $time ? date('d M Y, h:i A', $time) : (string)$value;
}

function pp_status_class($status): string
{
    return strtolower(
        preg_replace('/[^a-z0-9]+/i', '-', trim((string)$status))
    );
}

/* ============================================================
   VALIDATE PATIENT ID
   ============================================================ */
if ($patientId <= 0) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/admin/patients.php');
    exit;
}

/* ============================================================
   DETECT OPTIONAL PATIENT COLUMNS
   This keeps the profile compatible with the current database
   even if optional fields are not present.
   ============================================================ */
$patientOptionalColumns = [
    'email',
    'area',
    'chronic_conditions',
];

$availablePatientColumns = [];

foreach ($patientOptionalColumns as $column) {
    if (pp_has_column($conn, 'patients', $column)) {
        $availablePatientColumns[] = $column;
    }
}

/* ============================================================
   UPDATE PATIENT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_patient'])) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $submittedToken === ''
        || !hash_equals((string)$_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    }

    $name     = trim($_POST['name'] ?? '');
    $age      = trim($_POST['age'] ?? '');
    $gender   = trim($_POST['gender'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $district = trim($_POST['district'] ?? '');

    if ($name === '') {
        $error = 'Patient name is required.';
    } elseif ($phone === '') {
        $error = 'Mobile number is required.';
    }

    $updates = [];
    $types = '';
    $values = [];

    if ($error === '') {
        $baseFields = [
            'name'     => $name,
            'age'      => $age,
            'gender'   => $gender,
            'phone'    => $phone,
            'address'  => $address,
            'district' => $district,
        ];

        foreach ($baseFields as $column => $value) {
            if (pp_has_column($conn, 'patients', $column)) {
                $updates[] = "`{$column}` = ?";
                $types .= 's';
                $values[] = $value;
            }
        }

        if (pp_has_column($conn, 'patients', 'email')) {
            $updates[] = "`email` = ?";
            $types .= 's';
            $values[] = trim($_POST['email'] ?? '');
        }

        if (pp_has_column($conn, 'patients', 'area')) {
            $updates[] = "`area` = ?";
            $types .= 's';
            $values[] = trim($_POST['area'] ?? '');
        }

        if (pp_has_column($conn, 'patients', 'chronic_conditions')) {
            $updates[] = "`chronic_conditions` = ?";
            $types .= 's';
            $values[] = trim($_POST['chronic_conditions'] ?? '');
        }

        if ($updates) {
            $types .= 'i';
            $values[] = $patientId;

            $stmt = $conn->prepare(
                "UPDATE patients SET " .
                implode(', ', $updates) .
                " WHERE id = ? LIMIT 1"
            );

            if (!$stmt) {
                $error = 'Unable to prepare patient update.';
            } else {
                $stmt->bind_param($types, ...$values);

                if ($stmt->execute()) {
                    $success = 'Patient profile updated successfully.';
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $csrfToken = $_SESSION['csrf_token'];

                    if (function_exists('audit')) {
                        audit(
                            'UPDATE_PATIENT',
                            'Patient profile updated: ID ' . $patientId
                        );
                    }
                } else {
                    $error = 'Unable to update the patient profile.';
                }

                $stmt->close();
            }
        }
    }
}

/* ============================================================
   LOAD PATIENT
   ============================================================ */
$stmt = $conn->prepare(
    "SELECT * FROM patients WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    die('Unable to load patient profile.');
}

$stmt->bind_param('i', $patientId);
$stmt->execute();

$result = $stmt->get_result();
$patient = $result ? $result->fetch_assoc() : null;

$stmt->close();

if (!$patient) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/admin/patients.php?error=Patient+not+found');
    exit;
}

/* ============================================================
   PATIENT DISPLAY VALUES
   ============================================================ */
$patientCode = pp_value($patient, ['code'], (string)$patientId);
$name        = pp_value($patient, ['name', 'full_name', 'patient_name'], 'Unknown Patient');
$age         = pp_value($patient, ['age']);
$gender      = pp_value($patient, ['gender', 'sex']);
$phone       = pp_value($patient, ['phone', 'mobile']);
$email       = pp_value($patient, ['email']);
$address     = pp_value($patient, ['address']);
$area        = pp_value($patient, ['area']);
$district    = pp_value($patient, ['district', 'city']);
$conditions  = pp_value(
    $patient,
    ['chronic_conditions', 'conditions', 'disease', 'medical_condition']
);
$createdAt   = pp_date($patient['created_at'] ?? null);

/* ============================================================
   APPOINTMENTS
   ============================================================ */
$appointments = [];

if (pp_has_column($conn, 'appointments', 'patient_id')) {
    $stmt = $conn->prepare(
        "SELECT *
         FROM appointments
         WHERE patient_id = ?
         ORDER BY date DESC, id DESC"
    );

    if ($stmt) {
        $stmt->bind_param('i', $patientId);
        $stmt->execute();

        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $appointments[] = $row;
            }
        }

        $stmt->close();
    }
}

/* ============================================================
   VISITS / CONSULTATIONS
   ============================================================ */
$visits = [];

if (pp_has_column($conn, 'visits', 'patient_id')) {
    $stmt = $conn->prepare(
        "SELECT *
         FROM visits
         WHERE patient_id = ?
         ORDER BY visit_date DESC, id DESC"
    );

    if ($stmt) {
        $stmt->bind_param('i', $patientId);
        $stmt->execute();

        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $visits[] = $row;
            }
        }

        $stmt->close();
    }
}

/* ============================================================
   BILLS
   ============================================================ */
$bills = [];

if (pp_has_column($conn, 'bills', 'patient_id')) {
    $stmt = $conn->prepare(
        "SELECT *
         FROM bills
         WHERE patient_id = ?
         ORDER BY bill_date DESC, id DESC"
    );

    if ($stmt) {
        $stmt->bind_param('i', $patientId);
        $stmt->execute();

        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $bills[] = $row;
            }
        }

        $stmt->close();
    }
}

/* ============================================================
   REPORTS / DOCUMENTS
   ============================================================ */
$reports = [];

if (
    pp_has_column($conn, 'reports', 'patient_id')
) {
    $stmt = $conn->prepare(
        "SELECT *
         FROM reports
         WHERE patient_id = ?
         ORDER BY uploaded_at DESC, id DESC"
    );

    if ($stmt) {
        $stmt->bind_param('i', $patientId);
        $stmt->execute();

        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $reports[] = $row;
            }
        }

        $stmt->close();
    }
}

/* ============================================================
   SUMMARY NUMBERS
   ============================================================ */
$totalAppointments = count($appointments);
$totalVisits       = count($visits);
$totalBills        = count($bills);

$totalRevenue = 0;

foreach ($bills as $bill) {
    $totalRevenue += (float)($bill['net_amount'] ?? 0);
}

/* ============================================================
   LATEST VISIT
   ============================================================ */
$latestVisit = $visits[0] ?? null;

/* ============================================================
   SIDEBAR ROLE
   ============================================================ */
$role = $_SESSION['staff_role'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Patient Profile | AmarDayal Clinic</title>
<link rel="stylesheet" href="<?= e(rtrim(BASE_URL, '/') . '/Styles/style.css') ?>">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0 !important;
    background: #f4f7fb;
    overflow-x: hidden;
}

.layout {
    min-height: 100vh;
    width: 100%;
    display: block;
}

.content {
    margin-left: 270px;
    width: calc(100% - 270px);
    min-height: 100vh;
    padding: 0;
    max-width: none;
}

.profile-page {
    max-width: 1450px;
    margin: 0 auto;
    padding: 28px 34px 50px;
}

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 22px;
}

.page-head h1 {
    margin: 0 0 5px;
    color: #12345a;
}

.page-head p {
    margin: 0;
    color: #718096;
}

.head-actions {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
}

.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 15px;
    border-radius: 11px;
    border: 1px solid #d9e2ec;
    background: #fff;
    color: #17365d;
    text-decoration: none;
    font-weight: 800;
    cursor: pointer;
}

.action-btn.primary {
    border-color: #2563eb;
    background: #2563eb;
    color: #fff;
}

.action-btn.danger {
    color: #b91c1c;
    background: #fff;
}

.alert {
    padding: 13px 16px;
    border-radius: 12px;
    margin-bottom: 18px;
    font-weight: 700;
}

.alert.success {
    color: #166534;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
}

.alert.error {
    color: #b91c1c;
    background: #fef2f2;
    border: 1px solid #fecaca;
}

/* Hero */
.profile-hero {
    background: linear-gradient(135deg, #ffffff, #f3f8ff);
    border: 1px solid #dbe6f2;
    border-radius: 22px;
    padding: 25px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, .06);
    margin-bottom: 18px;
}

.profile-hero-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 23px;
}

.identity {
    display: flex;
    align-items: center;
    gap: 15px;
}

.avatar {
    width: 68px;
    height: 68px;
    flex: 0 0 68px;
    border-radius: 19px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #2563eb, #0f766e);
    color: #fff;
    font-size: 25px;
    font-weight: 900;
    box-shadow: 0 12px 25px rgba(37, 99, 235, .2);
}

.identity h2 {
    margin: 0 0 5px;
    color: #102f50;
    font-size: 25px;
}

.identity p {
    margin: 0;
    color: #718096;
}

.patient-code {
    padding: 10px 14px;
    border-radius: 11px;
    background: #eaf2ff;
    color: #1d4ed8;
    font-weight: 900;
    white-space: nowrap;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.stat {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 15px;
}

.stat-label {
    display: block;
    color: #8a9bb0;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 6px;
}

.stat strong {
    font-size: 21px;
    color: #17365d;
}

/* Cards */
.grid {
    display: grid;
    grid-template-columns: 1.05fr .95fr;
    gap: 18px;
    margin-bottom: 18px;
}

.card {
    background: #fff;
    border: 1px solid #dfe7f0;
    border-radius: 18px;
    padding: 21px;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .04);
}

.card h2 {
    margin: 0 0 4px;
    color: #12345a;
    font-size: 18px;
}

.card-sub {
    color: #718096;
    font-size: 13px;
    margin-bottom: 17px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 11px;
}

.info {
    border: 1px solid #e6edf5;
    background: #f8fafc;
    border-radius: 12px;
    padding: 12px;
}

.info.full {
    grid-column: 1 / -1;
}

.info span {
    display: block;
    font-size: 10px;
    color: #94a3b8;
    text-transform: uppercase;
    font-weight: 900;
    letter-spacing: .04em;
    margin-bottom: 5px;
}

.info strong {
    display: block;
    color: #233d5b;
    word-break: break-word;
}

.edit-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 13px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.field.full {
    grid-column: 1 / -1;
}

.field label {
    font-size: 12px;
    color: #53677d;
    font-weight: 800;
}

.field input,
.field select,
.field textarea {
    width: 100%;
    border: 1px solid #d7e1eb;
    border-radius: 10px;
    padding: 11px 12px;
    background: #fff;
    color: #17365d;
    font: inherit;
}

.field textarea {
    min-height: 82px;
    resize: vertical;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 15px;
}

/* Timeline */
.timeline {
    display: grid;
    gap: 12px;
}

.timeline-item {
    position: relative;
    padding: 16px 17px 16px 20px;
    border: 1px solid #e1e8f0;
    border-radius: 14px;
    background: #f8fafc;
}

.timeline-top {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
}

.timeline-title {
    font-weight: 900;
    color: #17365d;
}

.timeline-meta {
    color: #718096;
    font-size: 12px;
    margin-top: 4px;
}

.timeline-text {
    margin-top: 10px;
    color: #40556d;
    line-height: 1.55;
}

.pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    background: #eef2f7;
    color: #475569;
    white-space: nowrap;
}

.pill.pending,
.pill.waiting {
    background: #fff7ed;
    color: #c2410c;
}

.pill.approved,
.pill.completed {
    background: #ecfdf5;
    color: #15803d;
}

.pill.rejected {
    background: #fef2f2;
    color: #dc2626;
}

.pill.checked-in {
    background: #eff6ff;
    color: #1d4ed8;
}

/* Tables */
.table-wrap {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 760px;
}

.data-table th,
.data-table td {
    padding: 12px 11px;
    text-align: left;
    border-bottom: 1px solid #edf1f5;
    vertical-align: middle;
}

.data-table th {
    color: #718096;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
}

.data-table td {
    color: #334e68;
    font-size: 13px;
}

.table-link {
    display: inline-flex;
    padding: 7px 10px;
    border-radius: 8px;
    background: #eff6ff;
    color: #1d4ed8;
    text-decoration: none;
    font-weight: 800;
    font-size: 11px;
}

/* Empty */
.empty {
    padding: 27px 15px;
    border: 1px dashed #cbd5e1;
    border-radius: 13px;
    background: #f8fafc;
    text-align: center;
    color: #718096;
}

/* Print */
.print-only {
    display: none;
}

@media print {
    body {
        background: #fff !important;
    }

    .sidebar,
    .page-head,
    .head-actions,
    .edit-card,
    .no-print {
        display: none !important;
    }

    .content {
        margin: 0 !important;
        padding: 0 !important;
    }

    .profile-page {
        max-width: none;
    }

    .card,
    .profile-hero {
        box-shadow: none;
        break-inside: avoid;
    }

    .print-only {
        display: block;
    }
}

@media(max-width: 900px) {
    .content {
        margin-left: 78px;
        width: calc(100% - 78px);
    }
}

@media(max-width: 520px) {
    .content {
        margin-left: 66px;
        width: calc(100% - 66px);
    }
}

@media(max-width: 1050px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .grid {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 700px) {
    .page-head,
    .profile-hero-top {
        flex-direction: column;
        align-items: flex-start;
    }

    .stats,
    .info-grid,
    .edit-grid {
        grid-template-columns: 1fr;
    }

    .info.full,
    .field.full {
        grid-column: auto;
    }

    .head-actions {
        width: 100%;
    }

    .head-actions .action-btn {
        flex: 1;
    }

    .patient-code {
        align-self: stretch;
        text-align: center;
    }
}
</style>
</head>

<body>

<div class="layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="content profile-page">

        <div class="page-head">
            <div>
                <h1>Patient Profile</h1>
                <p>Complete clinical, appointment and billing history.</p>
            </div>

            <div class="head-actions">
                <a class="action-btn" href="<?= e(rtrim(BASE_URL, '/') . '/admin/patients.php') ?>">← Patients</a>
                <a class="action-btn" href="<?= e(rtrim(BASE_URL, '/') . '/appointment.php?patient_id=' . (int)$patientId) ?>">＋ Appointment</a>
                <button class="action-btn primary" type="button" onclick="window.print()">🖨 Print</button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert success"><?= pp_e($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= pp_e($error) ?></div>
        <?php endif; ?>

        <div class="profile-hero">

            <div class="profile-hero-top">

                <div class="identity">
                    <div class="avatar">
                        <?= pp_e(strtoupper(substr(trim($name), 0, 1))) ?>
                    </div>

                    <div>
                        <h2><?= pp_e($name) ?></h2>
                        <p>
                            Patient ID: AD-<?= pp_e($patientCode) ?>
                            · Registered <?= pp_e($createdAt) ?>
                        </p>
                    </div>
                </div>

                <div class="patient-code">
                    AD-<?= pp_e($patientCode) ?>
                </div>

            </div>

            <div class="stats">

                <div class="stat">
                    <span class="stat-label">Appointments</span>
                    <strong><?= $totalAppointments ?></strong>
                </div>

                <div class="stat">
                    <span class="stat-label">OPD Visits</span>
                    <strong><?= $totalVisits ?></strong>
                </div>

                <div class="stat">
                    <span class="stat-label">Invoices</span>
                    <strong><?= $totalBills ?></strong>
                </div>

                <div class="stat">
                    <span class="stat-label">Total Billing</span>
                    <strong>₹<?= number_format($totalRevenue, 2) ?></strong>
                </div>

            </div>
        </div>

        <div class="grid">

            <section class="card">
                <h2>Patient Information</h2>
                <div class="card-sub">Basic information stored in the patient master record.</div>

                <div class="info-grid">

                    <div class="info">
                        <span>Full Name</span>
                        <strong><?= pp_e($name) ?></strong>
                    </div>

                    <div class="info">
                        <span>Mobile</span>
                        <strong><?= pp_e($phone) ?></strong>
                    </div>

                    <div class="info">
                        <span>Age</span>
                        <strong><?= pp_e($age) ?></strong>
                    </div>

                    <div class="info">
                        <span>Gender</span>
                        <strong><?= pp_e($gender) ?></strong>
                    </div>

                    <div class="info">
                        <span>District</span>
                        <strong><?= pp_e($district) ?></strong>
                    </div>

                    <div class="info">
                        <span>Area / Locality</span>
                        <strong><?= pp_e($area) ?></strong>
                    </div>

                    <div class="info">
                        <span>Email</span>
                        <strong><?= pp_e($email) ?></strong>
                    </div>

                    <div class="info">
                        <span>Registered</span>
                        <strong><?= pp_e($createdAt) ?></strong>
                    </div>

                    <div class="info full">
                        <span>Address</span>
                        <strong><?= pp_e($address) ?></strong>
                    </div>

                    <div class="info full">
                        <span>Medical / Chronic Conditions</span>
                        <strong><?= pp_e($conditions === '—' ? 'No conditions recorded.' : $conditions) ?></strong>
                    </div>

                </div>
            </section>

            <section class="card edit-card">
                <h2>Edit Patient</h2>
                <div class="card-sub">Update the master patient record. Patient ID cannot be changed.</div>

                <form method="post">

                    <input type="hidden" name="csrf_token" value="<?= pp_e($csrfToken) ?>">
                    <input type="hidden" name="patient_id" value="<?= (int)$patientId ?>">

                    <div class="edit-grid">

                        <div class="field">
                            <label>Patient Name *</label>
                            <input name="name" value="<?= pp_e($name === 'Unknown Patient' ? '' : $name) ?>" required>
                        </div>

                        <div class="field">
                            <label>Mobile *</label>
                            <input name="phone" value="<?= pp_e($phone === '—' ? '' : $phone) ?>" required>
                        </div>

                        <div class="field">
                            <label>Age</label>
                            <input name="age" value="<?= pp_e($age === '—' ? '' : $age) ?>">
                        </div>

                        <div class="field">
                            <label>Gender</label>
                            <select name="gender">
                                <?php
                                $genderValue = $gender === '—' ? '' : $gender;
                                $genderOptions = ['', 'Male', 'Female', 'Other'];
                                foreach ($genderOptions as $option):
                                ?>
                                    <option value="<?= pp_e($option) ?>" <?= $genderValue === $option ? 'selected' : '' ?>>
                                        <?= $option === '' ? 'Select Gender' : pp_e($option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (pp_has_column($conn, 'patients', 'email')): ?>
                            <div class="field">
                                <label>Email</label>
                                <input type="email" name="email" value="<?= pp_e($email === '—' ? '' : $email) ?>">
                            </div>
                        <?php endif; ?>

                        <?php if (pp_has_column($conn, 'patients', 'area')): ?>
                            <div class="field">
                                <label>Area / Locality</label>
                                <input name="area" value="<?= pp_e($area === '—' ? '' : $area) ?>">
                            </div>
                        <?php endif; ?>

                        <div class="field">
                            <label>District</label>
                            <input name="district" value="<?= pp_e($district === '—' ? '' : $district) ?>">
                        </div>

                        <div class="field full">
                            <label>Address</label>
                            <input name="address" value="<?= pp_e($address === '—' ? '' : $address) ?>">
                        </div>

                        <?php if (pp_has_column($conn, 'patients', 'chronic_conditions')): ?>
                            <div class="field full">
                                <label>Medical / Chronic Conditions</label>
                                <textarea name="chronic_conditions"><?= pp_e($conditions === '—' ? '' : $conditions) ?></textarea>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="form-actions">
                        <button class="action-btn primary" name="save_patient" value="1" type="submit">
                            ✓ Save Patient
                        </button>
                    </div>

                </form>
            </section>

        </div>

        <section class="card" style="margin-bottom:18px;">
            <h2>Latest Consultation</h2>
            <div class="card-sub">Most recent OPD consultation recorded for this patient.</div>

            <?php if ($latestVisit): ?>

                <div class="info-grid">

                    <div class="info">
                        <span>OPD Serial</span>
                        <strong>OPD-<?= pp_e($latestVisit['opd_serial'] ?? '—') ?></strong>
                    </div>

                    <div class="info">
                        <span>Visit Date</span>
                        <strong><?= pp_e(pp_date($latestVisit['visit_date'] ?? null)) ?></strong>
                    </div>

                    <div class="info">
                        <span>Status</span>
                        <strong>
                            <?= pp_e($latestVisit['status'] ?? '—') ?>
                        </strong>
                    </div>

                    <div class="info">
                        <span>Created By</span>
                        <strong><?= pp_e($latestVisit['created_by'] ?? '—') ?></strong>
                    </div>

                    <div class="info full">
                        <span>Main Condition</span>
                        <strong><?= pp_e($latestVisit['main_conditions'] ?? '—') ?></strong>
                    </div>

                    <div class="info full">
                        <span>Symptoms</span>
                        <strong><?= pp_e($latestVisit['symptoms'] ?? '—') ?></strong>
                    </div>

                    <div class="info full">
                        <span>Diagnosis</span>
                        <strong><?= pp_e($latestVisit['diagnosis'] ?? '—') ?></strong>
                    </div>

                    <div class="info full">
                        <span>Prescription</span>
                        <strong><?= pp_e($latestVisit['prescription'] ?? '—') ?></strong>
                    </div>

                    <div class="info full">
                        <span>Lab Advice / Tests</span>
                        <strong><?= pp_e($latestVisit['lab_tests'] ?? '—') ?></strong>
                    </div>

                </div>

            <?php else: ?>

                <div class="empty">No OPD consultation has been recorded for this patient.</div>

            <?php endif; ?>
        </section>

        <section class="card" style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:17px;">
                <div>
                    <h2>Appointment History</h2>
                    <div class="card-sub" style="margin:4px 0 0;">All appointments linked to this patient.</div>
                </div>

                <span class="pill"><?= $totalAppointments ?> Total</span>
            </div>

            <?php if ($appointments): ?>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Appointment</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Clinic</th>
                            <th>Service</th>
                            <th>Problem</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($appointments as $a): ?>

                            <?php
                            $aStatus = $a['status'] ?? '—';
                            ?>

                            <tr>
                                <td>#<?= pp_e($a['id'] ?? '—') ?></td>
                                <td><?= pp_e(pp_date($a['date'] ?? null)) ?></td>
                                <td><?= pp_e($a['time'] ?? '—') ?></td>
                                <td><?= pp_e($a['clinic'] ?? '—') ?></td>
                                <td><?= pp_e($a['service'] ?? '—') ?></td>
                                <td><?= pp_e($a['problem'] ?? ($a['disease'] ?? '—')) ?></td>
                                <td>
                                    <span class="pill <?= pp_e(pp_status_class($aStatus)) ?>">
                                        <?= pp_e($aStatus) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="table-link"
                                       href="<?= e(rtrim(BASE_URL, '/') . '/admin/UpdateAppointment.php?id=' . (int)($a['id'] ?? 0)) ?>">
                                        Manage
                                    </a>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty">No appointments recorded for this patient.</div>

            <?php endif; ?>
        </section>

        <section class="card" style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:17px;">
                <div>
                    <h2>OPD / Consultation History</h2>
                    <div class="card-sub" style="margin:4px 0 0;">Permanent visit records and doctor consultations.</div>
                </div>

                <span class="pill"><?= $totalVisits ?> Visits</span>
            </div>

            <?php if ($visits): ?>

                <div class="timeline">

                    <?php foreach ($visits as $v): ?>

                        <div class="timeline-item">

                            <div class="timeline-top">
                                <div class="timeline-title">
                                    OPD-<?= pp_e($v['opd_serial'] ?? $v['id'] ?? '—') ?>
                                </div>

                                <span class="pill <?= pp_e(pp_status_class($v['status'] ?? '')) ?>">
                                    <?= pp_e($v['status'] ?? '—') ?>
                                </span>
                            </div>

                            <div class="timeline-meta">
                                <?= pp_e(pp_date($v['visit_date'] ?? null)) ?>
                                · <?= pp_e($v['created_by'] ?? 'Clinic Staff') ?>
                            </div>

                            <?php if (!empty($v['main_conditions'])): ?>
                                <div class="timeline-text">
                                    <strong>Condition:</strong>
                                    <?= pp_e($v['main_conditions']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($v['diagnosis'])): ?>
                                <div class="timeline-text">
                                    <strong>Diagnosis:</strong>
                                    <?= pp_e($v['diagnosis']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($v['prescription'])): ?>
                                <div class="timeline-text">
                                    <strong>Prescription:</strong>
                                    <?= pp_e($v['prescription']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($v['lab_tests'])): ?>
                                <div class="timeline-text">
                                    <strong>Lab Tests:</strong>
                                    <?= pp_e($v['lab_tests']) ?>
                                </div>
                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty">No OPD visits found.</div>

            <?php endif; ?>
        </section>

        <section class="card" style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:17px;">
                <div>
                    <h2>Billing History</h2>
                    <div class="card-sub" style="margin:4px 0 0;">Invoices and payments associated with this patient.</div>
                </div>

                <span class="pill">₹<?= number_format($totalRevenue, 2) ?></span>
            </div>

            <?php if ($bills): ?>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Total</th>
                            <th>Discount</th>
                            <th>Net</th>
                            <th>Payment</th>
                            <th>Created By</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($bills as $bill): ?>

                            <tr>
                                <td><strong><?= pp_e($bill['invoice_no'] ?? '—') ?></strong></td>
                                <td><?= pp_e(pp_date($bill['bill_date'] ?? null)) ?></td>
                                <td><?= pp_e($bill['category'] ?? '—') ?></td>
                                <td>₹<?= number_format((float)($bill['total_amount'] ?? 0), 2) ?></td>
                                <td>₹<?= number_format((float)($bill['discount'] ?? 0), 2) ?></td>
                                <td><strong>₹<?= number_format((float)($bill['net_amount'] ?? 0), 2) ?></strong></td>
                                <td><?= pp_e($bill['payment_mode'] ?? '—') ?></td>
                                <td><?= pp_e($bill['created_by'] ?? '—') ?></td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty">No billing records found for this patient.</div>

            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Medical Reports / Documents</h2>
            <div class="card-sub">Files associated with this patient record.</div>

            <?php if ($reports): ?>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Document</th>
                            <th>Visit</th>
                            <th>Uploaded</th>
                            <th>Open</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($reports as $report): ?>

                            <tr>
                                <td><?= pp_e($report['original_name'] ?? basename((string)($report['file_path'] ?? 'Report'))) ?></td>
                                <td>
                                    <?= !empty($report['visit_id'])
                                        ? 'OPD-' . pp_e($report['visit_id'])
                                        : '—' ?>
                                </td>
                                <td><?= pp_e(pp_datetime($report['uploaded_at'] ?? null)) ?></td>
                                <td>
                                    <?php if (!empty($report['file_path'])): ?>
                                        <a class="table-link"
                                           target="_blank"
                                           rel="noopener"
                                           href="<?= e(rtrim(BASE_URL, '/') . '/' . ltrim((string)$report['file_path'], '/\\')) ?>">
                                            Open
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty">No medical reports or documents have been uploaded.</div>

            <?php endif; ?>
        </section>

    </main>
</div>

</body>
</html>
