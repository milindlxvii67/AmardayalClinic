<?php
require_once '../config.php';
require_staff();

$role = $_SESSION['staff_role'] ?? 'Admin';
$staffName = $_SESSION['staff_name'] ?? $_SESSION['staff_username'] ?? 'Staff';

if (!in_array($role, ['Admin', 'Reception', 'Doctor'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['appointments_csrf'])) {
    $_SESSION['appointments_csrf'] = bin2hex(random_bytes(32));
}

$appointmentsCsrf = $_SESSION['appointments_csrf'];

function appt_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function appt_status_class($status): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$status));
}

function appt_date($date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : appt_e($date);
}

function appt_time($time): string
{
    if (!$time) return '-';
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : appt_e($time);
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$today = date('Y-m-d');

$dateFilter = trim($_GET['date'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'All');
$search = trim($_GET['search'] ?? '');

if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

$allowedStatuses = [
    'All',
    'Pending',
    'Approved',
    'Rejected',
    'Checked In',
    'Completed'
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'All';
}

/*
|--------------------------------------------------------------------------
| Appointment status actions
|--------------------------------------------------------------------------
*/
$flash = $_SESSION['appointments_flash'] ?? null;
unset($_SESSION['appointments_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedCsrf === '' ||
        !hash_equals((string)($_SESSION['appointments_csrf'] ?? ''), $postedCsrf)
    ) {
        $_SESSION['appointments_flash'] = [
            'type' => 'error',
            'message' => 'Your session security token is invalid or expired. Please try again.'
        ];

        header('Location: appointments.php');
        exit;
    }

    $action = trim($_POST['action'] ?? '');
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    $actionMap = [
        'approve' => 'Approved',
        'reject'  => 'Rejected',
        'complete' => 'Completed',
    ];

    if ($appointmentId <= 0 || !isset($actionMap[$action])) {
        $_SESSION['appointments_flash'] = [
            'type' => 'error',
            'message' => 'Invalid appointment action.'
        ];
        header('Location: appointments.php');
        exit;
    }

    $newStatus = $actionMap[$action];

    $stmt = $conn->prepare(
        "SELECT id, name, status
         FROM appointments
         WHERE id = ?
         LIMIT 1"
    );

    $appointment = null;

    if ($stmt) {
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $appointment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$appointment) {
        $_SESSION['appointments_flash'] = [
            'type' => 'error',
            'message' => 'Appointment not found.'
        ];
    } else {

        $stmt = $conn->prepare(
            "UPDATE appointments
             SET status = ?
             WHERE id = ?"
        );

        if (!$stmt) {
            $_SESSION['appointments_flash'] = [
                'type' => 'error',
                'message' => 'Unable to prepare appointment update.'
            ];
        } else {

            $stmt->bind_param('si', $newStatus, $appointmentId);

            if ($stmt->execute()) {

                if (function_exists('audit')) {
                    audit(
                        'Appointment Status Updated',
                        'Appointment #' . $appointmentId .
                        ' (' . ($appointment['name'] ?? 'Patient') . ')' .
                        ' changed from ' . ($appointment['status'] ?? '-') .
                        ' to ' . $newStatus
                    );
                }

                $_SESSION['appointments_flash'] = [
                    'type' => 'success',
                    'message' => 'Appointment #' . $appointmentId . ' marked as ' . $newStatus . '.'
                ];

                $_SESSION['appointments_csrf'] = bin2hex(random_bytes(32));

            } else {
                $_SESSION['appointments_flash'] = [
                    'type' => 'error',
                    'message' => 'Unable to update appointment: ' . $stmt->error
                ];
            }

            $stmt->close();
        }
    }

    header('Location: appointments.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Summary counts
|--------------------------------------------------------------------------
*/
$summary = [
    'All' => 0,
    'Pending' => 0,
    'Approved' => 0,
    'Rejected' => 0,
    'Checked In' => 0,
    'Completed' => 0,
];

/*
 * The summary cards show today's appointments only.
 * Appointment IDs (a.id) are not reset, so numbering continues permanently.
 */
$summaryStmt = $conn->prepare(
    "SELECT status, COUNT(*) AS total
     FROM appointments
     WHERE date = ?
     GROUP BY status"
);

if ($summaryStmt) {
    $summaryStmt->bind_param('s', $today);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();

    if ($summaryResult) {
        while ($row = $summaryResult->fetch_assoc()) {
            $key = (string)$row['status'];
            $count = (int)$row['total'];

            if (isset($summary[$key])) {
                $summary[$key] = $count;
            }

            $summary['All'] += $count;
        }
    }

    $summaryStmt->close();
}

/*
|--------------------------------------------------------------------------
| Filtered appointment list
|--------------------------------------------------------------------------
*/
$appointments = [];

$sql = "
    SELECT
        a.id,
        a.patient_id,
        a.name,
        a.email,
        a.phone,
        a.clinic,
        a.disease,
        a.service,
        a.date,
        a.status,
        a.time,
        a.patient_type,
        a.problem,
        a.problem_duration,
        a.problem_duration_unit,
        a.address,
        a.district
    FROM appointments a
    WHERE 1=1
";

$types = '';
$params = [];

if ($dateFilter !== '') {
    $sql .= " AND a.date = ?";
    $types .= 's';
    $params[] = $dateFilter;
}

if ($statusFilter !== 'All') {
    $sql .= " AND a.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $sql .= "
        AND (
            a.name LIKE ?
            OR a.phone LIKE ?
            OR a.email LIKE ?
            OR a.clinic LIKE ?
            OR a.service LIKE ?
            OR a.problem LIKE ?
            OR CAST(a.id AS CHAR) LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    for ($i = 0; $i < 7; $i++) {
        $types .= 's';
        $params[] = $like;
    }
}

$sql .= "
    ORDER BY
        CASE
            WHEN a.date = ? THEN 0
            WHEN a.status = 'Pending' THEN 1
            ELSE 2
        END,
        a.date DESC,
        a.time ASC,
        a.id DESC
";

$types .= 's';
$params[] = $today;

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Today's appointments
|--------------------------------------------------------------------------
*/
$todayTotal = 0;
$todayPending = 0;
$todayApproved = 0;
$todayCompleted = 0;

$stmt = $conn->prepare(
    "SELECT status, COUNT(*) AS total
     FROM appointments
     WHERE date = ?
     GROUP BY status"
);

if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $r = $stmt->get_result();

    while ($row = $r->fetch_assoc()) {
        $count = (int)$row['total'];
        $todayTotal += $count;

        if ($row['status'] === 'Pending') {
            $todayPending = $count;
        } elseif ($row['status'] === 'Approved') {
            $todayApproved = $count;
        } elseif ($row['status'] === 'Completed') {
            $todayCompleted = $count;
        }
    }

    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Appointments | AmarDayal Clinic</title>

<link rel="stylesheet" href="<?= e(BASE_URL) ?>Styles/style.css">

<style>
*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0 !important;
    min-height:100%;
}

body{
    background:#f4f7fb;
    color:#10233f;
    font-family:"Segoe UI",Arial,sans-serif;
    overflow-x:hidden;
}

.layout{
    width:100%;
    min-height:100vh;
    display:block;
}

.content{
    margin-left:270px;
    width:calc(100% - 270px);
    min-height:100vh;
    padding:30px;
    max-width:none;
}

.appointments-page{
    max-width:1600px;
    margin:0 auto;
}

.page-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}

.kicker{
    color:#2563eb;
    font-size:11px;
    font-weight:900;
    letter-spacing:.12em;
    text-transform:uppercase;
    margin-bottom:7px;
}

.page-head h1{
    margin:0;
    color:#0f172a;
    font-size:31px;
    line-height:1.15;
}

.page-head p{
    margin:7px 0 0;
    color:#64748b;
    font-size:14px;
}

.new-appointment{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:12px 17px;
    border-radius:11px;
    background:#2563eb;
    color:#fff;
    text-decoration:none;
    font-weight:900;
    box-shadow:0 8px 18px rgba(37,99,235,.18);
}

.new-appointment:hover{
    background:#1d4ed8;
}

.alert{
    padding:14px 16px;
    border-radius:12px;
    margin-bottom:18px;
    font-weight:800;
}

.alert.success{
    background:#ecfdf5;
    border:1px solid #a7f3d0;
    color:#047857;
}

.alert.error{
    background:#fef2f2;
    border:1px solid #fecaca;
    color:#b91c1c;
}

.stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:13px;
    margin-bottom:18px;
}

.stat{
    background:#fff;
    border:1px solid #e5eaf1;
    border-radius:15px;
    padding:17px;
    box-shadow:0 7px 22px rgba(15,23,42,.04);
}

.stat-label{
    color:#64748b;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.05em;
}

.stat-value{
    margin-top:7px;
    color:#0f172a;
    font-size:25px;
    font-weight:900;
}

.stat.pending .stat-value{color:#c2410c}
.stat.approved .stat-value{color:#15803d}
.stat.completed .stat-value{color:#2563eb}

.filters{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:17px;
    padding:17px;
    margin-bottom:18px;
    box-shadow:0 7px 22px rgba(15,23,42,.04);
}

.filters form{
    display:grid;
    grid-template-columns:minmax(180px,1fr) 180px 180px auto;
    gap:11px;
    align-items:end;
}

.field label{
    display:block;
    margin-bottom:6px;
    color:#475569;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
}

.field input,
.field select{
    width:100%;
    height:43px;
    border:1px solid #cbd5e1;
    border-radius:10px;
    padding:0 12px;
    background:#fff;
    color:#0f172a;
    font:inherit;
}

.field input:focus,
.field select:focus{
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

.filter-btn,
.clear-btn{
    height:43px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0 17px;
    border-radius:10px;
    text-decoration:none;
    font-weight:900;
    cursor:pointer;
}

.filter-btn{
    border:0;
    background:#0f172a;
    color:#fff;
}

.filter-btn:hover{
    background:#1e293b;
}

.clear-btn{
    border:1px solid #cbd5e1;
    background:#fff;
    color:#334155;
}

.table-card{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
    box-shadow:0 8px 26px rgba(15,23,42,.05);
}

.table-head{
    padding:18px 20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    border-bottom:1px solid #edf2f7;
}

.table-head h2{
    margin:0;
    color:#0f172a;
    font-size:18px;
}

.result-count{
    color:#64748b;
    font-size:12px;
    font-weight:800;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
}

.appointment-table{
    width:100%;
    min-width:1120px;
    border-collapse:collapse;
}

.appointment-table th{
    padding:12px 13px;
    text-align:left;
    background:#f8fafc;
    color:#64748b;
    border-bottom:1px solid #e2e8f0;
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.05em;
    white-space:nowrap;
}

.appointment-table td{
    padding:14px 13px;
    border-bottom:1px solid #edf2f7;
    vertical-align:top;
    font-size:13px;
    color:#334155;
}

.appointment-table tbody tr:hover{
    background:#fbfdff;
}

.appointment-table tbody tr:last-child td{
    border-bottom:0;
}

.appointment-id{
    color:#2563eb;
    font-weight:900;
    white-space:nowrap;
}

.patient-name{
    color:#0f172a;
    font-weight:900;
}

.patient-sub{
    margin-top:3px;
    color:#64748b;
    font-size:11px;
}

.problem{
    max-width:220px;
    color:#334155;
    line-height:1.4;
}

.clinic{
    white-space:nowrap;
    font-weight:800;
    color:#334155;
}

.datetime strong{
    display:block;
    color:#0f172a;
}

.datetime span{
    display:block;
    margin-top:3px;
    color:#64748b;
    font-size:11px;
}

.status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 9px;
    border-radius:999px;
    font-size:10px;
    font-weight:900;
    white-space:nowrap;
}

.status.pending{
    background:#fff7ed;
    color:#c2410c;
}

.status.approved{
    background:#ecfdf5;
    color:#15803d;
}

.status.rejected{
    background:#fef2f2;
    color:#dc2626;
}

.status.checked-in{
    background:#eff6ff;
    color:#1d4ed8;
}

.status.completed{
    background:#e0e7ff;
    color:#4338ca;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    min-width:150px;
}

.action-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 9px;
    border-radius:8px;
    border:1px solid transparent;
    font-size:10px;
    font-weight:900;
    cursor:pointer;
    text-decoration:none;
}

.action-approve{
    background:#ecfdf5;
    color:#047857;
    border-color:#a7f3d0;
}

.action-reject{
    background:#fef2f2;
    color:#b91c1c;
    border-color:#fecaca;
}

.action-complete{
    background:#eff6ff;
    color:#1d4ed8;
    border-color:#bfdbfe;
}

.action-view{
    background:#f8fafc;
    color:#334155;
    border-color:#cbd5e1;
}

.inline-form{
    margin:0;
}

.empty{
    padding:65px 20px;
    text-align:center;
    color:#64748b;
}

.empty-icon{
    font-size:40px;
    margin-bottom:10px;
}

.empty strong{
    display:block;
    margin-bottom:5px;
    color:#334155;
    font-size:16px;
}

@media(max-width:1200px){
    .stats{
        grid-template-columns:repeat(3,1fr);
    }

    .filters form{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:700px){
    .content{
        margin-left:78px;
        width:calc(100% - 78px);
        padding:20px 14px;
    }

    .page-head{
        flex-direction:column;
    }

    .new-appointment{
        width:100%;
    }

    .stats{
        grid-template-columns:1fr 1fr;
    }

    .filters form{
        grid-template-columns:1fr;
    }
}

@media(max-width:520px){
    .content{
        margin-left:66px;
        width:calc(100% - 66px);
        padding:16px 10px;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .page-head h1{
        font-size:25px;
    }
}
</style>
</head>

<body>

<div class="layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="content">

        <div class="appointments-page">

            <header class="page-head">

                <div>
                    <div class="kicker">AmarDayal Clinic • Staff Portal</div>

                    <h1>Appointments</h1>

                    <p>
                        Review, approve and manage clinic appointments.
                    </p>
                </div>

                <?php if (in_array($role, ['Admin', 'Reception'], true)): ?>
                    <a class="new-appointment" href="<?= e(BASE_URL) ?>appointment.php">
                        ＋ New Appointment
                    </a>
                <?php endif; ?>

            </header>

            <?php if ($flash): ?>
                <div class="alert <?= appt_e($flash['type']) ?>">
                    <?= appt_e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <section class="stats">

                <div class="stat">
                    <div class="stat-label">All Appointments</div>
                    <div class="stat-value"><?= $summary['All'] ?></div>
                </div>

                <div class="stat pending">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?= $summary['Pending'] ?></div>
                </div>

                <div class="stat approved">
                    <div class="stat-label">Approved</div>
                    <div class="stat-value"><?= $summary['Approved'] ?></div>
                </div>

                <div class="stat">
                    <div class="stat-label">Checked In</div>
                    <div class="stat-value"><?= $summary['Checked In'] ?></div>
                </div>

                <div class="stat completed">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?= $summary['Completed'] ?></div>
                </div>

            </section>

            <section class="filters">

                <form method="get" action="appointments.php">

                    <div class="field">
                        <label>Search</label>
                        <input
                            type="search"
                            name="search"
                            value="<?= appt_e($search) ?>"
                            placeholder="Name, phone, appointment ID, problem..."
                        >
                    </div>

                    <div class="field">
                        <label>Date</label>
                        <input
                            type="date"
                            name="date"
                            value="<?= appt_e($dateFilter) ?>"
                        >
                    </div>

                    <div class="field">
                        <label>Status</label>
                        <select name="status">
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option
                                    value="<?= appt_e($status) ?>"
                                    <?= $statusFilter === $status ? 'selected' : '' ?>
                                >
                                    <?= appt_e($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex;gap:8px">

                        <button class="filter-btn" type="submit">
                            🔎 Filter
                        </button>

                        <?php if ($search !== '' || $dateFilter !== '' || $statusFilter !== 'All'): ?>
                            <a class="clear-btn" href="appointments.php">
                                Clear
                            </a>
                        <?php endif; ?>

                    </div>

                </form>

            </section>

            <section class="table-card">

                <div class="table-head">

                    <div>
                        <h2>Appointment List</h2>
                    </div>

                    <div class="result-count">
                        <?= count($appointments) ?> result(s)
                    </div>

                </div>

                <?php if ($appointments): ?>

                    <div class="table-wrap">

                        <table class="appointment-table">

                            <thead>
                                <tr>
                                    <th>Appointment</th>
                                    <th>Patient</th>
                                    <th>Date & Time</th>
                                    <th>Clinic</th>
                                    <th>Problem / Service</th>
                                    <th>Patient Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($appointments as $a): ?>

                                <?php
                                $statusClass = appt_status_class($a['status'] ?? 'Pending');
                                $patientType = ucfirst((string)($a['patient_type'] ?? ''));
                                ?>

                                <tr>

                                    <td>
                                        <div class="appointment-id">
                                            #<?= (int)$a['id'] ?>
                                        </div>

                                        <?php if (!empty($a['patient_id'])): ?>
                                            <div class="patient-sub">
                                                Patient ID <?= (int)$a['patient_id'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>

                                        <div class="patient-name">
                                            <?= appt_e($a['name'] ?: 'Patient') ?>
                                        </div>

                                        <div class="patient-sub">
                                            <?= appt_e($a['phone'] ?: '-') ?>
                                        </div>

                                        <?php if (!empty($a['email'])): ?>
                                            <div class="patient-sub">
                                                <?= appt_e($a['email']) ?>
                                            </div>
                                        <?php endif; ?>

                                    </td>

                                    <td class="datetime">

                                        <strong>
                                            <?= appt_date($a['date']) ?>
                                        </strong>

                                        <span>
                                            <?= appt_time($a['time']) ?>
                                        </span>

                                    </td>

                                    <td>
                                        <div class="clinic">
                                            <?= appt_e($a['clinic'] ?: 'Clinic') ?>
                                        </div>

                                        <?php if (!empty($a['district'])): ?>
                                            <div class="patient-sub">
                                                <?= appt_e($a['district']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>

                                        <div class="problem">
                                            <strong>
                                                <?= appt_e($a['problem'] ?: $a['disease'] ?: 'General Consultation') ?>
                                            </strong>

                                            <?php if (!empty($a['service'])): ?>
                                                <div class="patient-sub">
                                                    <?= appt_e($a['service']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($a['problem_duration'])): ?>
                                                <div class="patient-sub">
                                                    Duration:
                                                    <?= appt_e($a['problem_duration']) ?>
                                                    <?= appt_e($a['problem_duration_unit'] ?? '') ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                    </td>

                                    <td>
                                        <?= appt_e($patientType ?: '-') ?>
                                    </td>

                                    <td>

                                        <span class="status <?= appt_e($statusClass) ?>">
                                            <?= appt_e($a['status'] ?: 'Pending') ?>
                                        </span>

                                    </td>

                                    <td>

                                        <div class="actions">

                                            <?php if ($a['status'] === 'Pending'): ?>

                                                <form class="inline-form" method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= appt_e($appointmentsCsrf) ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">

                                                    <button
                                                        class="action-btn action-approve"
                                                        type="submit"
                                                    >
                                                        ✓ Approve
                                                    </button>
                                                </form>

                                                <form class="inline-form" method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= appt_e($appointmentsCsrf) ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">

                                                    <button
                                                        class="action-btn action-reject"
                                                        type="submit"
                                                    >
                                                        ✕ Reject
                                                    </button>
                                                </form>

                                            <?php elseif ($a['status'] === 'Approved'): ?>

                                                <?php if ($role === 'Admin'): ?>
                                                    <form class="inline-form" method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= appt_e($appointmentsCsrf) ?>">
                                                        <input type="hidden" name="action" value="complete">
                                                        <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">

                                                        <button
                                                            class="action-btn action-complete"
                                                            type="submit"
                                                        >
                                                            ✓ Complete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                            <?php endif; ?>

                                            <a
                                                class="action-btn action-view"
                                                href="UpdateAppointment.php?id=<?= (int)$a['id'] ?>"
                                            >
                                                View
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty">

                        <div class="empty-icon">📅</div>

                        <strong>No appointments found</strong>

                        <div>
                            Try changing the date, status or search filters.
                        </div>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>

</body>
</html>