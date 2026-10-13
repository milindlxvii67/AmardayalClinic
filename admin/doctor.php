<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_staff();

$role = $_SESSION['staff_role'] ?? '';
if (!in_array($role, ['Admin', 'Doctor'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

function adc_doctor_has_column(mysqli $conn, string $table, string $column): bool
{
    $table  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $r && $r->num_rows > 0;
}

function adc_doctor_ensure_appointment_columns(mysqli $conn): void
{
    $columns = [
        'token_no'       => "INT NULL",
        'checked_in_at'  => "DATETIME NULL",
        'checked_in_by'  => "VARCHAR(120) NULL",
        'visit_id'       => "INT NULL",
    ];

    foreach ($columns as $name => $definition) {
        if (!adc_doctor_has_column($conn, 'appointments', $name)) {
            @$conn->query("ALTER TABLE appointments ADD COLUMN `$name` $definition");
        }
    }
}

function doctor_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function doctor_flash(string $type, string $message): void
{
    $_SESSION['doctor_flash'] = ['type' => $type, 'message' => $message];
}

adc_doctor_ensure_appointment_columns($conn);

$today = date('Y-m-d');
$selectedVisitId = (int)($_GET['visit_id'] ?? $_POST['visit_id'] ?? 0);
$action = $_POST['action'] ?? '';

/* ============================================================
   SAVE CONSULTATION
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_consultation') {
    $visitId      = (int)($_POST['visit_id'] ?? 0);
    $diagnosis    = trim($_POST['diagnosis'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $labTests     = trim($_POST['lab_tests'] ?? '');

    if ($visitId <= 0) {
        doctor_flash('error', 'Invalid visit selected.');
        header('Location: doctor.php');
        exit;
    }

    $stmt = $conn->prepare("
        SELECT v.id, v.patient_id, v.opd_serial, v.visit_date, v.status,
               p.name, p.code
        FROM visits v
        INNER JOIN patients p ON p.id = v.patient_id
        WHERE v.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        doctor_flash('error', 'Unable to load the selected visit.');
        header('Location: doctor.php');
        exit;
    }

    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $visitCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$visitCheck) {
        doctor_flash('error', 'Visit record not found.');
        header('Location: doctor.php');
        exit;
    }

    if ($diagnosis === '') {
        doctor_flash('error', 'Please enter the clinical diagnosis before completing the consultation.');
        header('Location: doctor.php?visit_id=' . $visitId);
        exit;
    }

    $consultedAt = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        UPDATE visits
        SET diagnosis = ?,
            prescription = ?,
            lab_tests = ?,
            status = 'Completed',
            consulted_at = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        doctor_flash('error', 'Unable to prepare the consultation update.');
        header('Location: doctor.php?visit_id=' . $visitId);
        exit;
    }

    $stmt->bind_param(
        'ssssi',
        $diagnosis,
        $prescription,
        $labTests,
        $consultedAt,
        $visitId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        doctor_flash('error', 'The consultation could not be saved.');
        header('Location: doctor.php?visit_id=' . $visitId);
        exit;
    }
    $stmt->close();

    /* Mark linked appointment completed. */
    if (adc_doctor_has_column($conn, 'appointments', 'visit_id')) {
        $stmt = $conn->prepare("UPDATE appointments SET status='Completed' WHERE visit_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $visitId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /* Fallback: same patient + same date if visit_id is not linked. */
    $stmt = $conn->prepare("
        UPDATE appointments
        SET status='Completed'
        WHERE patient_id = ?
          AND date = ?
          AND status IN ('Checked In','Waiting','Approved')
    ");
    if ($stmt) {
        $stmt->bind_param('is', $visitCheck['patient_id'], $visitCheck['visit_date']);
        $stmt->execute();
        $stmt->close();
    }

    if (function_exists('audit')) {
        audit(
            'COMPLETE_CONSULTATION',
            'OPD-' . $visitCheck['opd_serial'] . ' completed for AD-' . $visitCheck['code']
        );
    }

    doctor_flash(
        'success',
        'Consultation completed successfully for ' .
        $visitCheck['name'] . ' — OPD-' . $visitCheck['opd_serial'] . '.'
    );

    header('Location: doctor.php?visit_id=' . $visitId);
    exit;
}

/* ============================================================
   QUEUE
   ============================================================ */
$queue = [];

$stmt = $conn->prepare("
    SELECT
        v.id AS visit_id,
        v.opd_serial,
        v.visit_date,
        v.main_conditions,
        v.symptoms,
        v.duration_number,
        v.duration_unit,
        v.status AS visit_status,
        p.id AS patient_id,
        p.code,
        p.name,
        p.age,
        p.gender,
        p.mobile,
        p.address,
        p.area,
        p.district,
        a.id AS appointment_id,
        a.time AS appointment_time,
        a.status AS appointment_status,
        a.token_no,
        a.checked_in_at
    FROM visits v
    INNER JOIN patients p ON p.id = v.patient_id
    LEFT JOIN appointments a
        ON a.visit_id = v.id
    WHERE v.visit_date = ?
      AND v.status IN ('Waiting','Checked In')
    ORDER BY
        CASE
            WHEN a.token_no IS NULL THEN 999999
            ELSE a.token_no
        END ASC,
        v.id ASC
");

if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $queue[] = $row;
    }
    $stmt->close();
}

/* Also show today's Approved appointments which have not yet produced a visit.
   This makes the doctor screen useful even if an older database has no visit_id. */
$approved = [];

$stmt = $conn->prepare("
    SELECT
        a.id AS appointment_id,
        a.patient_id,
        a.name AS appointment_name,
        a.phone,
        a.time AS appointment_time,
        a.status AS appointment_status,
        a.token_no,
        p.code,
        p.name,
        p.age,
        p.gender,
        p.mobile,
        p.address,
        p.area,
        p.district
    FROM appointments a
    LEFT JOIN patients p ON p.id = a.patient_id
    WHERE a.date = ?
      AND a.status IN ('Approved','Checked In','Waiting')
      AND (a.visit_id IS NULL OR a.visit_id = 0)
    ORDER BY
        CASE WHEN a.token_no IS NULL THEN 999999 ELSE a.token_no END ASC,
        a.id ASC
");

if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $approved[] = $row;
    }
    $stmt->close();
}

/* ============================================================
   SELECTED VISIT
   ============================================================ */
$visit = null;
$patient = null;
$history = [];

if ($selectedVisitId > 0) {
    $stmt = $conn->prepare("
        SELECT
            v.*,
            p.code,
            p.name,
            p.age,
            p.gender,
            p.mobile,
            p.address,
            p.area,
            p.district,
            p.chronic_conditions
        FROM visits v
        INNER JOIN patients p ON p.id = v.patient_id
        WHERE v.id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $selectedVisitId);
        $stmt->execute();
        $visit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($visit) {
        $patient = $visit;

        $stmt = $conn->prepare("
            SELECT
                v.id,
                v.opd_serial,
                v.visit_date,
                v.main_conditions,
                v.symptoms,
                v.duration_number,
                v.duration_unit,
                v.diagnosis,
                v.prescription,
                v.lab_tests,
                v.status,
                v.consulted_at
            FROM visits v
            WHERE v.patient_id = ?
              AND v.id <> ?
            ORDER BY v.visit_date DESC, v.id DESC
            LIMIT 20
        ");

        if ($stmt) {
            $stmt->bind_param('ii', $visit['patient_id'], $selectedVisitId);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
            $stmt->close();
        }
    }
}

$flash = $_SESSION['doctor_flash'] ?? null;
unset($_SESSION['doctor_flash']);

$totalQueue = count($queue) + count($approved);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Doctor Consultation | AmarDayal Clinic</title>
<link rel="stylesheet" href="../Styles/style.css">
<style>
*{box-sizing:border-box}
body{margin:0;padding:0!important;background:#f4f7fb;color:#10233f;font-family:"Segoe UI",Arial,sans-serif;overflow-x:hidden}
.doctor-layout{min-height:100vh;width:100%;display:block}
.content{margin-left:270px;width:calc(100% - 270px);min-height:100vh;padding:30px;max-width:none}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}
.page-head h1{margin:0;font-size:30px;color:#0f172a}
.page-head p{margin:6px 0 0;color:#64748b}
.today-badge{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 16px;font-weight:800;color:#334155}
.alert{padding:14px 16px;border-radius:12px;margin-bottom:18px;font-weight:700}
.alert.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.stat{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 6px 18px rgba(15,23,42,.04)}
.stat .label{font-size:12px;color:#64748b;font-weight:800;text-transform:uppercase}
.stat .value{font-size:28px;font-weight:900;margin-top:5px;color:#0f172a}
.workspace{display:grid;grid-template-columns:390px minmax(0,1fr);gap:18px;align-items:start}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 8px 25px rgba(15,23,42,.05);overflow:hidden}
.card-head{padding:18px 20px;border-bottom:1px solid #edf2f7;display:flex;justify-content:space-between;align-items:center;gap:12px}
.card-head h2{margin:0;font-size:17px}
.card-head span{font-size:12px;color:#64748b;font-weight:700}
.queue{padding:12px}
.queue-item{display:block;text-decoration:none;color:inherit;border:1px solid #e2e8f0;border-radius:14px;padding:14px;margin-bottom:10px;background:#fff;transition:.15s}
.queue-item:hover{border-color:#93c5fd;background:#f8fbff}
.queue-item.active{border:2px solid #2563eb;background:#eff6ff}
.q-top{display:flex;justify-content:space-between;gap:10px;align-items:center}
.token{background:#0f766e;color:#fff;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900}
.opd{font-size:12px;font-weight:900;color:#2563eb}
.q-name{font-weight:900;font-size:15px;margin-top:8px}
.q-meta{font-size:12px;color:#64748b;margin-top:4px}
.q-problem{font-size:13px;color:#334155;margin-top:8px;line-height:1.4}
.no-queue{padding:35px 20px;text-align:center;color:#64748b}
.consult{padding:20px}
.patient-banner{background:linear-gradient(135deg,#0f766e,#155e75);color:#fff;border-radius:16px;padding:20px;margin-bottom:18px}
.patient-banner-top{display:flex;justify-content:space-between;gap:15px}
.patient-banner h2{margin:0;font-size:22px}
.patient-banner p{margin:5px 0 0;color:#dff7f4}
.patient-id{font-weight:900;background:rgba(255,255,255,.15);padding:7px 10px;border-radius:9px;height:max-content}
.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px}
.info-box{background:rgba(255,255,255,.1);padding:10px;border-radius:10px}
.info-box small{display:block;color:#b9e7e3;font-size:10px;text-transform:uppercase;font-weight:800}
.info-box strong{display:block;margin-top:3px;font-size:13px}
.section-title{font-size:15px;font-weight:900;margin:22px 0 10px;color:#0f172a}
.complaint{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
.readonly{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px}
.readonly small{display:block;color:#64748b;font-size:10px;font-weight:900;text-transform:uppercase;margin-bottom:4px}
textarea{width:100%;min-height:125px;border:1px solid #cbd5e1;border-radius:12px;padding:12px;font:inherit;resize:vertical;outline:none}
textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:15px}
.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:10px;padding:11px 16px;font-weight:900;cursor:pointer}
.btn-primary{background:#2563eb;color:#fff}.btn-primary:hover{background:#1d4ed8}
.btn-secondary{background:#e2e8f0;color:#334155}
.history{margin-top:20px}
.history-item{border:1px solid #e2e8f0;border-radius:13px;padding:14px;margin-bottom:10px}
.history-head{display:flex;justify-content:space-between;gap:10px}
.history-head strong{color:#0f172a}
.history-item p{margin:7px 0;color:#475569;font-size:13px;white-space:pre-wrap}
.empty-panel{padding:70px 30px;text-align:center;color:#64748b}
.empty-panel .icon{font-size:45px;margin-bottom:10px}
@media(max-width:1100px){
 .stats{grid-template-columns:repeat(2,1fr)}
 .workspace{grid-template-columns:1fr}
 .info-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:700px){
 .content{margin-left:78px;width:calc(100% - 78px);padding:16px}
 .stats{grid-template-columns:1fr}
 .page-head{flex-direction:column}
 .complaint{grid-template-columns:1fr}
 .info-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:520px){
 .content{margin-left:66px;width:calc(100% - 66px);padding:14px}
}
</style>
</head>
<body>
<div class="doctor-layout">

<?php include __DIR__ . '/sidebar.php'; ?>

<main class="content">

<div class="page-head">
    <div>
        <h1>Doctor Consultation</h1>
        <p>Queue → Patient History → Diagnosis → Prescription → Lab Advice → Complete Visit</p>
    </div>
    <div class="today-badge">Today · <?= doctor_e(date('d M Y')) ?></div>
</div>

<?php if ($flash): ?>
    <div class="alert <?= doctor_e($flash['type']) ?>">
        <?= doctor_e($flash['message']) ?>
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="label">Today's Active Queue</div>
        <div class="value"><?= $totalQueue ?></div>
    </div>
    <div class="stat">
        <div class="label">Waiting Visits</div>
        <div class="value"><?= count($queue) ?></div>
    </div>
    <div class="stat">
        <div class="label">Previous Visits</div>
        <div class="value"><?= count($history) ?></div>
    </div>
    <div class="stat">
        <div class="label">Doctor</div>
        <div class="value" style="font-size:18px">
            <?= doctor_e($_SESSION['staff_name'] ?? $_SESSION['staff_username'] ?? 'Doctor') ?>
        </div>
    </div>
</div>

<div class="workspace">

<section class="card">
    <div class="card-head">
        <h2>Today's Queue</h2>
        <span><?= $totalQueue ?> active</span>
    </div>

    <div class="queue">

    <?php foreach ($queue as $q): ?>
        <a class="queue-item <?= ($selectedVisitId === (int)$q['visit_id']) ? 'active' : '' ?>"
           href="doctor.php?visit_id=<?= (int)$q['visit_id'] ?>">

            <div class="q-top">
                <span class="opd">OPD-<?= (int)$q['opd_serial'] ?></span>
                <?php if (!empty($q['token_no'])): ?>
                    <span class="token">Token <?= (int)$q['token_no'] ?></span>
                <?php endif; ?>
            </div>

            <div class="q-name"><?= doctor_e($q['name']) ?></div>

            <div class="q-meta">
                AD-<?= doctor_e($q['code']) ?>
                · <?= doctor_e($q['age'] ?: '-') ?> yrs
                · <?= doctor_e($q['gender'] ?: '-') ?>
                · <?= doctor_e($q['mobile'] ?: '-') ?>
            </div>

            <div class="q-problem">
                <?= doctor_e($q['main_conditions'] ?: 'No chief complaint recorded') ?>
                <?php if (!empty($q['duration_number'])): ?>
                    · <?= doctor_e($q['duration_number'] . ' ' . ($q['duration_unit'] ?? '')) ?>
                <?php endif; ?>
            </div>

        </a>
    <?php endforeach; ?>

    <?php foreach ($approved as $a): ?>
        <div class="queue-item" style="background:#fffbeb;border-color:#fde68a">
            <div class="q-top">
                <span class="opd">Appointment</span>
                <?php if (!empty($a['token_no'])): ?>
                    <span class="token">Token <?= (int)$a['token_no'] ?></span>
                <?php endif; ?>
            </div>
            <div class="q-name"><?= doctor_e($a['name'] ?: $a['appointment_name']) ?></div>
            <div class="q-meta">
                <?= doctor_e($a['appointment_time'] ?: '-') ?>
                · <?= doctor_e($a['phone'] ?: $a['mobile'] ?: '-') ?>
            </div>
            <div class="q-problem">Approved appointment not yet linked to an OPD visit. Reception should check in the patient first.</div>
        </div>
    <?php endforeach; ?>

    <?php if (!$queue && !$approved): ?>
        <div class="no-queue">
            <div style="font-size:34px">✓</div>
            <strong>No active patients in today's doctor queue.</strong>
            <p>Checked-in patients will appear here.</p>
        </div>
    <?php endif; ?>

    </div>
</section>

<section class="card">

<?php if ($visit && $patient): ?>

<div class="consult">

    <div class="patient-banner">
        <div class="patient-banner-top">
            <div>
                <h2><?= doctor_e($patient['name']) ?></h2>
                <p>OPD-<?= (int)$visit['opd_serial'] ?> · Patient ID AD-<?= doctor_e($patient['code']) ?></p>
            </div>
            <div class="patient-id"><?= doctor_e($visit['status'] ?: 'Pending') ?></div>
        </div>

        <div class="info-grid">
            <div class="info-box">
                <small>Age</small>
                <strong><?= doctor_e($patient['age'] ?: '-') ?> years</strong>
            </div>
            <div class="info-box">
                <small>Gender</small>
                <strong><?= doctor_e($patient['gender'] ?: '-') ?></strong>
            </div>
            <div class="info-box">
                <small>Mobile</small>
                <strong><?= doctor_e($patient['mobile'] ?: '-') ?></strong>
            </div>
            <div class="info-box">
                <small>District</small>
                <strong><?= doctor_e($patient['district'] ?: '-') ?></strong>
            </div>
        </div>
    </div>

    <div class="section-title">Current Visit</div>

    <div class="complaint">
        <div class="readonly">
            <small>Chief Complaint / Problem</small>
            <?= doctor_e($visit['main_conditions'] ?: '-') ?>
        </div>
        <div class="readonly">
            <small>Duration</small>
            <?= doctor_e(trim(($visit['duration_number'] ?? '') . ' ' . ($visit['duration_unit'] ?? '')) ?: '-') ?>
        </div>
        <div class="readonly">
            <small>Visit Date</small>
            <?= doctor_e($visit['visit_date'] ?: '-') ?>
        </div>
    </div>

    <div class="readonly" style="margin-top:10px">
        <small>Symptoms / Patient Complaint Details</small>
        <?= nl2br(doctor_e($visit['symptoms'] ?: 'No symptoms recorded.')) ?>
    </div>

    <div class="readonly" style="margin-top:10px">
        <small>Address / Area</small>
        <?= doctor_e(trim(($patient['address'] ?? '') . ' ' . ($patient['area'] ?? '')) ?: '-') ?>
    </div>

    <div class="readonly" style="margin-top:10px">
        <small>Chronic / Previous Conditions</small>
        <?= doctor_e($patient['chronic_conditions'] ?: 'None recorded.') ?>
    </div>

    <form method="post" action="doctor.php">
        <input type="hidden" name="action" value="save_consultation">
        <input type="hidden" name="visit_id" value="<?= (int)$visit['id'] ?>">

        <div class="section-title">Clinical Diagnosis <span style="color:#dc2626">*</span></div>
        <textarea name="diagnosis" required placeholder="Enter clinical diagnosis, findings and assessment..."><?= doctor_e($visit['diagnosis'] ?? '') ?></textarea>

        <div class="section-title">Prescription / Medicines & Dosage</div>
        <textarea name="prescription" placeholder="Medicine name, dose, frequency, duration and instructions..."><?= doctor_e($visit['prescription'] ?? '') ?></textarea>

        <div class="section-title">Lab Tests / Radiology / Physiotherapy / Advice</div>
        <textarea name="lab_tests" placeholder="Recommended investigations, therapy, follow-up or other advice..."><?= doctor_e($visit['lab_tests'] ?? '') ?></textarea>

        <div class="actions">
            <button class="btn btn-primary" type="submit">
                ✓ Save & Complete Consultation
            </button>
            <a class="btn btn-secondary" href="doctor.php">
                Clear Selection
            </a>
        </div>
    </form>

    <div class="history">
        <div class="section-title">Previous Visit History</div>

        <?php if ($history): ?>
            <?php foreach ($history as $h): ?>
                <div class="history-item">
                    <div class="history-head">
                        <strong>OPD-<?= (int)$h['opd_serial'] ?> · <?= doctor_e($h['visit_date']) ?></strong>
                        <span style="font-size:12px;color:#64748b">
                            <?= doctor_e($h['status'] ?: '-') ?>
                        </span>
                    </div>

                    <p><strong>Problem:</strong> <?= doctor_e($h['main_conditions'] ?: '-') ?></p>
                    <p><strong>Diagnosis:</strong> <?= doctor_e($h['diagnosis'] ?: 'Not recorded') ?></p>
                    <p><strong>Prescription:</strong> <?= doctor_e($h['prescription'] ?: 'Not recorded') ?></p>
                    <p><strong>Lab / Advice:</strong> <?= doctor_e($h['lab_tests'] ?: 'Not recorded') ?></p>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="readonly">No previous visit records found for this patient.</div>
        <?php endif; ?>
    </div>

</div>

<?php else: ?>

<div class="empty-panel">
    <div class="icon">🩺</div>
    <h2>Select a patient</h2>
    <p>Choose a checked-in patient from the queue to open the consultation.</p>
    <p style="margin-top:8px">The consultation screen will show the patient's details and previous visit history.</p>
</div>

<?php endif; ?>

</section>
</div>

</main>
</div>
</body>
</html>