<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_staff();

$role = $_SESSION['staff_role'] ?? '';
if (!in_array($role, ['Admin', 'Reception', 'Doctor'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

function ua_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ua_flash(string $type, string $message): void
{
    $_SESSION['ua_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    ua_flash('error', 'Invalid appointment ID.');
    header('Location: appointments.php');
    exit;
}

/* ============================================================
   LOAD APPOINTMENT
   ============================================================ */
$stmt = $conn->prepare("
    SELECT
        a.*,
        p.code AS patient_code,
        p.name AS registered_name,
        p.age AS patient_age,
        p.gender AS patient_gender,
        p.phone AS patient_mobile,
        p.phone AS patient_phone,
        p.address AS patient_address,
        p.district AS patient_district
    FROM appointments a
    LEFT JOIN patients p ON p.id = a.patient_id
    WHERE a.id = ?
    LIMIT 1
");

if (!$stmt) {
    exit('Unable to load appointment: ' . ua_e($conn->error));
}

$stmt->bind_param('i', $id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    ua_flash('error', 'Appointment not found.');
    header('Location: appointments.php');
    exit;
}

/* ============================================================
   PROCESS UPDATE
   ============================================================ */
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? 'save';

    /* --------------------------------------------------------
       STATUS ACTIONS
       -------------------------------------------------------- */
    if (in_array($action, ['approve', 'reject', 'complete'], true)) {

        $newStatus = match ($action) {
            'approve'  => 'Approved',
            'reject'   => 'Rejected',
            'complete' => 'Completed',
            default    => $appointment['status']
        };

        if ($action === 'complete' && $role !== 'Admin') {
            $error = 'Only an Admin can manually complete an appointment from this page.';
        } elseif ($action === 'approve' && !in_array($role, ['Admin', 'Reception'], true)) {
            $error = 'Only Admin or Reception can approve appointments.';
        } elseif ($action === 'reject' && !in_array($role, ['Admin', 'Reception'], true)) {
            $error = 'Only Admin or Reception can reject appointments.';
        } else {

            $oldStatus = $appointment['status'];

            $stmt = $conn->prepare("
                UPDATE appointments
                SET status = ?
                WHERE id = ?
            ");

            if (!$stmt) {
                $error = 'Unable to update appointment status: ' . $conn->error;
            } else {

                $stmt->bind_param('si', $newStatus, $id);

                if (!$stmt->execute()) {
                    $error = 'Unable to update appointment status: ' . $stmt->error;
                } else {

                    if (function_exists('audit')) {
                        audit(
                            'APPOINTMENT_STATUS',
                            'Appointment #' . $id .
                            ': ' . $oldStatus . ' → ' . $newStatus
                        );
                    }

                    ua_flash(
                        'success',
                        'Appointment status changed from ' .
                        $oldStatus . ' to ' . $newStatus . '.'
                    );

                    header('Location: UpdateAppointment.php?id=' . $id);
                    exit;
                }

                $stmt->close();
            }
        }
    }

    /* --------------------------------------------------------
       SAVE EDITED APPOINTMENT
       -------------------------------------------------------- */
    if ($action === 'save' && $error === '') {

        $name       = trim($_POST['name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $clinic     = trim($_POST['clinic'] ?? '');
        $service    = trim($_POST['service'] ?? '');
        $date       = trim($_POST['date'] ?? '');
        $time       = trim($_POST['time'] ?? '');
        $patientType = trim($_POST['patient_type'] ?? '');
        $problem    = trim($_POST['problem'] ?? '');
        $duration   = trim($_POST['problem_duration'] ?? '');
        $durationUnit = trim($_POST['problem_duration_unit'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $district   = trim($_POST['district'] ?? '');
        $status     = trim($_POST['status'] ?? $appointment['status']);

        $allowedStatuses = [
            'Pending',
            'Approved',
            'Rejected',
            'Checked In',
            'Completed'
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = $appointment['status'];
        }

        if ($name === '') {
            $error = 'Patient name is required.';
        } elseif ($phone === '') {
            $error = 'Phone number is required.';
        } elseif ($clinic === '') {
            $error = 'Please select a clinic branch.';
        } elseif ($date === '') {
            $error = 'Appointment date is required.';
        } elseif ($time === '') {
            $error = 'Appointment time is required.';
        } elseif ($problem === '') {
            $error = 'Please enter the disease / problem.';
        }

        /* Validate date/time. */
        if ($error === '') {
            $dateObj = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
                $error = 'Please enter a valid appointment date.';
            }
        }

        if ($error === '') {
            $timeObj = DateTime::createFromFormat('H:i', $time);

            if (!$timeObj) {
                $timeObj = DateTime::createFromFormat('H:i:s', $time);
            }

            if (!$timeObj) {
                $error = 'Please enter a valid appointment time.';
            } else {
                $time = $timeObj->format('H:i:s');
            }
        }

        /* Check for another appointment at the same slot. */
        if ($error === '') {

            $stmt = $conn->prepare("
                SELECT id
                FROM appointments
                WHERE date = ?
                  AND time = ?
                  AND clinic = ?
                  AND id <> ?
                  AND status IN ('Pending','Approved','Checked In','Completed')
                LIMIT 1
            ");

            if ($stmt) {

                $stmt->bind_param(
                    'sssi',
                    $date,
                    $time,
                    $clinic,
                    $id
                );

                $stmt->execute();
                $busy = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($busy) {
                    $error = 'That appointment time is already booked for this clinic. Please select another time.';
                }
            }
        }

        if ($error === '') {

            /*
             * Keep disease and problem synchronized because the
             * current appointment workflow stores the selected
             * patient problem in both fields.
             */
            $disease = $problem;

            $stmt = $conn->prepare("
                UPDATE appointments
                SET
                    name = ?,
                    email = ?,
                    phone = ?,
                    clinic = ?,
                    disease = ?,
                    service = ?,
                    date = ?,
                    status = ?,
                    time = ?,
                    patient_type = ?,
                    problem = ?,
                    problem_duration = ?,
                    problem_duration_unit = ?,
                    address = ?,
                    district = ?
                WHERE id = ?
            ");

            if (!$stmt) {
                $error = 'Unable to prepare appointment update: ' . $conn->error;
            } else {

                $stmt->bind_param(
                    'sssssssssssssssi',
                    $name,
                    $email,
                    $phone,
                    $clinic,
                    $disease,
                    $service,
                    $date,
                    $status,
                    $time,
                    $patientType,
                    $problem,
                    $duration,
                    $durationUnit,
                    $address,
                    $district,
                    $id
                );

                if (!$stmt->execute()) {
                    $error = 'Unable to save appointment: ' . $stmt->error;
                } else {

                    if (function_exists('audit')) {
                        audit(
                            'APPOINTMENT_UPDATED',
                            'Appointment #' . $id .
                            ' updated by ' .
                            ($_SESSION['staff_name'] ?? $_SESSION['staff_username'] ?? 'Staff')
                        );
                    }

                    ua_flash(
                        'success',
                        'Appointment #' . $id . ' has been updated successfully.'
                    );

                    $stmt->close();

                    header('Location: UpdateAppointment.php?id=' . $id);
                    exit;
                }

                $stmt->close();
            }
        }
    }
}

/* ============================================================
   RELOAD AFTER VALIDATION ERROR / FLASH
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {

    $appointment['name'] = $_POST['name'] ?? $appointment['name'];
    $appointment['email'] = $_POST['email'] ?? $appointment['email'];
    $appointment['phone'] = $_POST['phone'] ?? $appointment['phone'];
    $appointment['clinic'] = $_POST['clinic'] ?? $appointment['clinic'];
    $appointment['service'] = $_POST['service'] ?? $appointment['service'];
    $appointment['date'] = $_POST['date'] ?? $appointment['date'];
    $appointment['time'] = $_POST['time'] ?? $appointment['time'];
    $appointment['patient_type'] = $_POST['patient_type'] ?? $appointment['patient_type'];
    $appointment['problem'] = $_POST['problem'] ?? $appointment['problem'];
    $appointment['disease'] = $_POST['problem'] ?? $appointment['disease'];
    $appointment['problem_duration'] = $_POST['problem_duration'] ?? $appointment['problem_duration'];
    $appointment['problem_duration_unit'] = $_POST['problem_duration_unit'] ?? $appointment['problem_duration_unit'];
    $appointment['address'] = $_POST['address'] ?? $appointment['address'];
    $appointment['district'] = $_POST['district'] ?? $appointment['district'];
    $appointment['status'] = $_POST['status'] ?? $appointment['status'];
}

$flash = $_SESSION['ua_flash'] ?? null;
unset($_SESSION['ua_flash']);

$patientName = $appointment['registered_name']
    ?: $appointment['name']
    ?: 'Patient';

$patientCode = $appointment['patient_code'] ?? '';

$currentTime = $appointment['time'] ?? '';
if ($currentTime !== '') {
    $currentTime = substr($currentTime, 0, 5);
}

$statusClass = match ($appointment['status']) {
    'Approved' => 'approved',
    'Rejected' => 'rejected',
    'Checked In' => 'checked',
    'Completed' => 'completed',
    default => 'pending'
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Update Appointment #<?= (int)$id ?> | AmarDayal Clinic</title>

<style>
:root{
    --bg:#f5f7fb;
    --card:#fff;
    --text:#172033;
    --muted:#64748b;
    --line:#e5e7eb;
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --green:#15803d;
    --green-bg:#ecfdf5;
    --orange:#c2410c;
    --orange-bg:#fff7ed;
    --red:#b91c1c;
    --red-bg:#fef2f2;
    --blue-bg:#eff6ff;
    --shadow:0 14px 38px rgba(15,23,42,.07);
}

*{box-sizing:border-box}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,Segoe UI,Arial,sans-serif;
}

.layout{
    min-height:100vh;
    display:flex;
}

.content{
    flex:1;
    min-width:0;
    min-height:100vh;
    padding:30px;
    margin-left:290px;
    width:calc(100% - 290px);
    box-sizing:border-box;
}

@media (min-width:1201px){
    .content{
        margin-left:290px;
        width:calc(100% - 290px);
    }
}

@media (max-width:1200px) and (min-width:701px){
    .content{
        margin-left:260px;
        width:calc(100% - 260px);
    }
}

.back{
    display:inline-flex;
    text-decoration:none;
    color:#475569;
    font-weight:800;
    font-size:12px;
    margin-bottom:14px;
}

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px;
}

.page-head h1{
    margin:0;
    font-size:29px;
    letter-spacing:-.6px;
}

.page-head p{
    margin:6px 0 0;
    color:var(--muted);
}

.id-badge{
    background:#fff;
    border:1px solid var(--line);
    border-radius:13px;
    padding:10px 14px;
    font-size:12px;
    font-weight:900;
}

.alert{
    padding:13px 16px;
    border-radius:13px;
    margin-bottom:18px;
    font-size:13px;
    font-weight:700;
}

.alert.success{
    background:var(--green-bg);
    color:var(--green);
    border:1px solid #bbf7d0;
}

.alert.error{
    background:var(--red-bg);
    color:var(--red);
    border:1px solid #fecaca;
}

.grid{
    display:grid;
    grid-template-columns:minmax(0,1.45fr) minmax(310px,.7fr);
    gap:20px;
    align-items:start;
    min-width:0;
}

.card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:19px;
    box-shadow:var(--shadow);
    overflow:hidden;
}

.card-head{
    padding:18px 21px;
    border-bottom:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.card-head h2{
    margin:0;
    font-size:16px;
}

.card-head span{
    color:var(--muted);
    font-size:11px;
    font-weight:800;
}

.card-body{
    padding:21px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
}

.field{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.field.full{
    grid-column:1/-1;
}

.field label{
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.45px;
    color:#64748b;
}

.field input,
.field select,
.field textarea{
    width:100%;
    border:1px solid #dbe1ea;
    background:#fff;
    color:#172033;
    border-radius:11px;
    padding:11px 12px;
    font:inherit;
    outline:none;
}

.field textarea{
    min-height:100px;
    resize:vertical;
}

.field input:focus,
.field select:focus,
.field textarea:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 3px #eff6ff;
}

.readonly{
    background:#f8fafc!important;
    color:#64748b!important;
}

.form-actions{
    margin-top:20px;
    padding-top:18px;
    border-top:1px solid var(--line);
    display:flex;
    justify-content:flex-end;
    gap:9px;
    flex-wrap:wrap;
}

.btn{
    border:0;
    border-radius:10px;
    padding:10px 14px;
    text-decoration:none;
    font-size:11px;
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{background:var(--primary-dark)}

.btn-light{
    background:#f1f5f9;
    color:#334155;
}

.btn-green{
    background:#15803d;
    color:#fff;
}

.btn-red{
    background:#b91c1c;
    color:#fff;
}

.btn-orange{
    background:#ea580c;
    color:#fff;
}

.status{
    display:inline-flex;
    width:max-content;
    border-radius:999px;
    padding:7px 11px;
    font-size:10px;
    font-weight:900;
}

.status.pending{background:var(--orange-bg);color:var(--orange)}
.status.approved{background:var(--green-bg);color:var(--green)}
.status.checked{background:var(--blue-bg);color:#1d4ed8}
.status.completed{background:#f1f5f9;color:#475569}
.status.rejected{background:var(--red-bg);color:var(--red)}

.summary{
    padding:20px;
}

.patient{
    padding:16px;
    background:#f8fafc;
    border:1px solid var(--line);
    border-radius:15px;
}

.patient-name{
    font-size:18px;
    font-weight:900;
}

.patient-code{
    margin-top:4px;
    color:#2563eb;
    font-size:11px;
    font-weight:900;
}

.patient-details{
    margin-top:13px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:9px;
}

.detail{
    font-size:11px;
}

.detail b{
    display:block;
    color:#94a3b8;
    text-transform:uppercase;
    font-size:8px;
    margin-bottom:3px;
}

.detail span{
    font-weight:800;
}

.info-list{
    margin-top:17px;
    border-top:1px solid var(--line);
}

.info-row{
    display:flex;
    justify-content:space-between;
    gap:15px;
    padding:12px 0;
    border-bottom:1px solid #edf0f4;
    font-size:11px;
}

.info-row .label{
    color:var(--muted);
}

.info-row .value{
    text-align:right;
    font-weight:900;
}

.status-actions{
    margin-top:18px;
}

.status-actions h3{
    font-size:12px;
    margin:0 0 10px;
}

.action-buttons{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.note{
    margin-top:16px;
    padding:12px;
    border-radius:12px;
    background:#eff6ff;
    color:#1e40af;
    font-size:10px;
    line-height:1.5;
}

@media(max-width:1000px){
    .grid{grid-template-columns:1fr}
}

@media(max-width:700px){
    .content{
        margin-left:72px;
        width:calc(100% - 72px);
        padding:17px;
    }
    .page-head{flex-direction:column}
    .form-grid{grid-template-columns:1fr}
    .patient-details{grid-template-columns:1fr}
}
</style>
</head>

<body>

<div class="layout">

<?php include __DIR__ . '/sidebar.php'; ?>

<main class="content">

<a class="back" href="appointments.php">← Back to Appointments</a>

<header class="page-head">

    <div>
        <h1>Update Appointment</h1>
        <p>Edit appointment details, patient information and booking status.</p>
    </div>

    <div class="id-badge">
        Appointment #<?= (int)$id ?>
    </div>

</header>

<?php if ($flash): ?>
    <div class="alert <?= ua_e($flash['type']) ?>">
        <?= ua_e($flash['message']) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert error">
        <?= ua_e($error) ?>
    </div>
<?php endif; ?>

<div class="grid">

<section class="card">

    <div class="card-head">
        <h2>Appointment Details</h2>
        <span>Editable fields</span>
    </div>

    <div class="card-body">

        <form method="post" action="UpdateAppointment.php?id=<?= (int)$id ?>">

            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="save">

            <div class="form-grid">

                <div class="field">
                    <label>Patient Name</label>
                    <input
                        type="text"
                        name="name"
                        value="<?= ua_e($appointment['name']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Patient Type</label>
                    <select name="patient_type">
                        <option value="new" <?= strtolower($appointment['patient_type'] ?? '') === 'new' ? 'selected' : '' ?>>
                            New Patient
                        </option>
                        <option value="old" <?= strtolower($appointment['patient_type'] ?? '') === 'old' ? 'selected' : '' ?>>
                            Existing Patient
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Phone Number</label>
                    <input
                        type="tel"
                        name="phone"
                        value="<?= ua_e($appointment['phone']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Email</label>
                    <input
                        type="email"
                        name="email"
                        value="<?= ua_e($appointment['email']) ?>"
                        placeholder="Optional"
                    >
                </div>

                <div class="field">
                    <label>Clinic Branch</label>
                    <select name="clinic" required>
                        <option value="">Select Branch</option>
                        <option value="Sankisa" <?= ($appointment['clinic'] ?? '') === 'Sankisa' ? 'selected' : '' ?>>
                            Sankisa
                        </option>
                        <option value="Mainpuri" <?= ($appointment['clinic'] ?? '') === 'Mainpuri' ? 'selected' : '' ?>>
                            Mainpuri
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Service</label>
                    <input
                        type="text"
                        name="service"
                        value="<?= ua_e($appointment['service']) ?>"
                        placeholder="e.g. Joint Pain Consultation"
                    >
                </div>

                <div class="field">
                    <label>Appointment Date</label>
                    <input
                        type="date"
                        name="date"
                        value="<?= ua_e($appointment['date']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Appointment Time</label>
                    <input
                        type="time"
                        name="time"
                        value="<?= ua_e($currentTime) ?>"
                        required
                    >
                </div>

                <div class="field full">
                    <label>Disease / Problem</label>
                    <input
                        type="text"
                        name="problem"
                        value="<?= ua_e($appointment['problem'] ?: $appointment['disease']) ?>"
                        placeholder="Enter patient's main problem"
                        required
                    >
                </div>

                <div class="field">
                    <label>Duration</label>
                    <input
                        type="text"
                        name="problem_duration"
                        value="<?= ua_e($appointment['problem_duration']) ?>"
                        placeholder="e.g. 3"
                    >
                </div>

                <div class="field">
                    <label>Duration Unit</label>
                    <select name="problem_duration_unit">
                        <?php
                        $unit = strtolower((string)($appointment['problem_duration_unit'] ?? ''));
                        ?>
                        <option value="">Select Unit</option>
                        <option value="Days" <?= $unit === 'days' ? 'selected' : '' ?>>Days</option>
                        <option value="Weeks" <?= $unit === 'weeks' ? 'selected' : '' ?>>Weeks</option>
                        <option value="Months" <?= $unit === 'months' ? 'selected' : '' ?>>Months</option>
                        <option value="Years" <?= $unit === 'years' ? 'selected' : '' ?>>Years</option>
                    </select>
                </div>

                <div class="field">
                    <label>District</label>
                    <input
                        type="text"
                        name="district"
                        value="<?= ua_e($appointment['district']) ?>"
                    >
                </div>

                <div class="field">
                    <label>Status</label>

                    <?php if ($role === 'Admin'): ?>

                        <select name="status">
                            <?php foreach (['Pending','Approved','Rejected','Checked In','Completed'] as $status): ?>
                                <option
                                    value="<?= ua_e($status) ?>"
                                    <?= $appointment['status'] === $status ? 'selected' : '' ?>
                                >
                                    <?= ua_e($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php else: ?>

                        <input
                            class="readonly"
                            type="text"
                            value="<?= ua_e($appointment['status']) ?>"
                            readonly
                        >

                    <?php endif; ?>

                </div>

                <div class="field full">
                    <label>Address</label>
                    <textarea name="address"><?= ua_e($appointment['address']) ?></textarea>
                </div>

            </div>

            <div class="form-actions">

                <a class="btn btn-light" href="appointments.php">
                    Cancel
                </a>

                <button class="btn btn-primary" type="submit">
                    ✓ Save Changes
                </button>

            </div>

        </form>

    </div>

</section>

<aside>

<section class="card">

    <div class="card-head">
        <h2>Appointment Summary</h2>
        <span><?= ua_e($appointment['status']) ?></span>
    </div>

    <div class="summary">

        <div class="patient">

            <div class="patient-name">
                <?= ua_e($patientName) ?>
            </div>

            <?php if ($patientCode !== ''): ?>
                <div class="patient-code">
                    Patient ID: AD-<?= ua_e($patientCode) ?>
                </div>
            <?php endif; ?>

            <div class="patient-details">

                <div class="detail">
                    <b>Age</b>
                    <span><?= ua_e($appointment['patient_age'] ?: '—') ?></span>
                </div>

                <div class="detail">
                    <b>Gender</b>
                    <span><?= ua_e($appointment['patient_gender'] ?: '—') ?></span>
                </div>

                <div class="detail">
                    <b>Mobile</b>
                    <span><?= ua_e($appointment['patient_mobile'] ?: $appointment['phone'] ?: '—') ?></span>
                </div>

                <div class="detail">
                    <b>District</b>
                    <span><?= ua_e($appointment['patient_district'] ?: $appointment['district'] ?: '—') ?></span>
                </div>

            </div>

        </div>

        <div class="info-list">

            <div class="info-row">
                <span class="label">Appointment No.</span>
                <span class="value">#<?= (int)$id ?></span>
            </div>

            <div class="info-row">
                <span class="label">Date</span>
                <span class="value">
                    <?= $appointment['date']
                        ? ua_e(date('d M Y', strtotime($appointment['date'])))
                        : '—'
                    ?>
                </span>
            </div>

            <div class="info-row">
                <span class="label">Time</span>
                <span class="value">
                    <?= $currentTime
                        ? ua_e(date('h:i A', strtotime($currentTime)))
                        : '—'
                    ?>
                </span>
            </div>

            <div class="info-row">
                <span class="label">Clinic</span>
                <span class="value">
                    <?= ua_e($appointment['clinic'] ?: '—') ?>
                </span>
            </div>

            <div class="info-row">
                <span class="label">Token</span>
                <span class="value">
                    <?= !empty($appointment['token_no'])
                        ? 'Token ' . (int)$appointment['token_no']
                        : 'Not checked in'
                    ?>
                </span>
            </div>

            <div class="info-row">
                <span class="label">OPD Visit</span>
                <span class="value">
                    <?= !empty($appointment['visit_id'])
                        ? 'OPD visit #' . (int)$appointment['visit_id']
                        : 'Not created'
                    ?>
                </span>
            </div>

            <div class="info-row">
                <span class="label">Status</span>
                <span class="value">
                    <span class="status <?= $statusClass ?>">
                        <?= ua_e($appointment['status']) ?>
                    </span>
                </span>
            </div>

        </div>

        <div class="status-actions">

            <h3>Quick Status Actions</h3>

            <div class="action-buttons">

                <?php if (in_array($role, ['Admin','Reception'], true) && $appointment['status'] === 'Pending'): ?>

                    <form method="post">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="approve">

                        <button class="btn btn-green" type="submit">
                            ✓ Approve
                        </button>
                    </form>

                    <form method="post">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="reject">

                        <button class="btn btn-red" type="submit">
                            ✕ Reject
                        </button>
                    </form>

                <?php endif; ?>

                <?php if ($role === 'Admin' && $appointment['status'] !== 'Completed'): ?>

                    <form method="post">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="complete">

                        <button class="btn btn-orange" type="submit">
                            ✓ Complete
                        </button>
                    </form>

                <?php endif; ?>

                <?php if ($appointment['status'] === 'Approved' && empty($appointment['visit_id']) && in_array($role, ['Admin','Reception'], true)): ?>

                    <a class="btn btn-primary" href="reception.php">
                        → Check In
                    </a>

                <?php endif; ?>

                <?php if (!empty($appointment['visit_id'])): ?>

                    <a
                        class="btn btn-light"
                        href="doctor.php?visit_id=<?= (int)$appointment['visit_id'] ?>"
                    >
                        Open Doctor Visit
                    </a>

                <?php endif; ?>

            </div>

        </div>

        <div class="note">
            <strong>Workflow:</strong>
            Pending → Approved → Reception Check-In → Token / OPD →
            Doctor Consultation → Completed.
        </div>

    </div>

</section>

</aside>

</div>

</main>
</div>

</body>
</html>
