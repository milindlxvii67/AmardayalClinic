<?php
require_once '../config.php';
require_staff();

/* ============================================================
   PATIENT DIRECTORY + PATIENT PROFILE
   ============================================================ */

$term = trim($_GET['q'] ?? '');
$patientId = (int)($_GET['id'] ?? 0);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['patient_csrf'])) {
    $_SESSION['patient_csrf'] = bin2hex(random_bytes(32));
}

/* Detect optional/renamed columns so this page works with the existing database schema. */
function patient_column_exists(mysqli $db, string $column): bool
{
    // Read the real schema and compare exact column names. Using SHOW COLUMNS LIKE
    // is unsafe here because underscores in names are SQL LIKE wildcards.
    static $columnCache = null;
    if ($columnCache === null) {
        $columnCache = [];
        $result = $db->query("SHOW COLUMNS FROM `patients`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (isset($row['Field'])) {
                    $columnCache[] = (string)$row['Field'];
                }
            }
            $result->free();
        }
    }
    return in_array($column, $columnCache, true);
}

function patient_first_existing_column(mysqli $db, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (patient_column_exists($db, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function patient_sql_column(mysqli $db, string $column, string $alias): string
{
    return patient_column_exists($db, $column) ? "`$column` AS `$alias`" : "NULL AS `$alias`";
}

function patient_bind_dynamic(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $refs = [];
    foreach ($values as $key => &$value) {
        $refs[$key] = &$value;
    }
    return $stmt->bind_param($types, ...$refs);
}

$phoneColumn = patient_column_exists($conn, 'phone')
    ? 'phone'
    : (patient_column_exists($conn, 'mobile') ? 'mobile' : null);
$patientPhone = $phoneColumn ? "`$phoneColumn` AS `phone`" : "NULL AS `phone`";
$patientEmail = patient_sql_column($conn, 'email', 'email');
$areaColumn = patient_first_existing_column(
    $conn,
    ['area', 'locality', 'area_locality', 'locality_area', 'village', 'town']
);
$patientArea = $areaColumn
    ? "`$areaColumn` AS `area`"
    : "NULL AS `area`";
$patientDistrict = patient_sql_column($conn, 'district', 'district');
$conditionsColumn = patient_first_existing_column(
    $conn,
    [
        'chronic_conditions', 'medical_conditions', 'conditions',
        'medical_history', 'medical_condition', 'disease',
        'diseases', 'health_conditions', 'problem', 'problems'
    ]
);

/*
 * The current patients table has no medical-condition column.
 * Add a nullable column automatically so the edit form can save this field.
 * Existing patient records are preserved.
 */
if (!$conditionsColumn) {
    $addConditionsColumn = $conn->query(
        "ALTER TABLE `patients` ADD COLUMN `chronic_conditions` TEXT NULL"
    );
    if ($addConditionsColumn || patient_column_exists($conn, 'chronic_conditions')) {
        $conditionsColumn = 'chronic_conditions';
    }
}
$patientConditions = $conditionsColumn
    ? "`$conditionsColumn` AS `chronic_conditions`"
    : "NULL AS `chronic_conditions`";
/* Detect whichever registration-date column exists in this installation. */
$registeredDateColumn = patient_first_existing_column(
    $conn,
    [
        'created_at', 'registered_at', 'registration_date',
        'registered_date', 'date_registered', 'created_on',
        'date_created', 'joining_date'
    ]
);

/*
 * If the table has no registration-date field, add a nullable one.
 * NULL is intentional for old records: do not invent a registration date.
 */
if (!$registeredDateColumn) {
    $addRegisteredDate = $conn->query(
        "ALTER TABLE `patients` ADD COLUMN `registered_at` DATE NULL"
    );
    if ($addRegisteredDate || patient_column_exists($conn, 'registered_at')) {
        $registeredDateColumn = 'registered_at';
    }
}
$patientCreatedAt = $registeredDateColumn
    ? "`$registeredDateColumn` AS `created_at`"
    : "NULL AS `created_at`";

$actionMessage = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['patient_csrf'], $postedToken)) {
        $actionError = 'Security check failed. Refresh the page and try again.';
    } else {
        $action = (string)($_POST['patient_action'] ?? '');
        $targetId = (int)($_POST['patient_id'] ?? 0);

        if ($targetId <= 0) {
            $actionError = 'Invalid patient record.';
        } elseif ($action === 'update') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $ageInput = trim((string)($_POST['age'] ?? ''));
            $gender = trim((string)($_POST['gender'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $area = trim((string)($_POST['area'] ?? ''));
            $district = trim((string)($_POST['district'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $conditions = trim((string)($_POST['chronic_conditions'] ?? ''));

            if ($name === '' || $phone === '') {
                $actionError = 'Patient name and phone number are required.';
            } elseif ($ageInput !== '' && (!ctype_digit($ageInput) || (int)$ageInput > 125)) {
                $actionError = 'Enter a valid age between 0 and 125.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $actionError = 'Enter a valid email address or leave it blank.';
            } else {
                $age = ($ageInput === '') ? null : (int)$ageInput;
                if (!$phoneColumn) {
                    $actionError = 'The patients table has neither a phone nor a mobile column.';
                } else {
                    if (!patient_column_exists($conn, 'name')) {
                        $actionError = 'The patients table does not contain the required name column.';
                    } else {
                    $fields = [
                        'name' => [$name, 's'],
                        $phoneColumn => [$phone, 's'],
                    ];
                    if (patient_column_exists($conn, 'age')) $fields['age'] = [$age, 'i'];
                    if (patient_column_exists($conn, 'gender')) $fields['gender'] = [$gender, 's'];
                    if (patient_column_exists($conn, 'address')) $fields['address'] = [$address, 's'];
                    if ($areaColumn) {
                        $fields[$areaColumn] = [$area, 's'];
                    } elseif ($area !== '') {
                        $actionError = 'The patients table has no area/locality column, so this field cannot be saved. Add an area/locality column or use the actual column name in the code.';
                    }

                    if (patient_column_exists($conn, 'district')) $fields['district'] = [$district, 's'];
                    if (patient_column_exists($conn, 'email')) $fields['email'] = [$email, 's'];

                    if ($conditionsColumn) {
                        $fields[$conditionsColumn] = [$conditions, 's'];
                    } elseif ($conditions !== '') {
                        $actionError = 'The patients table has no medical-conditions column, so this field cannot be saved. Add a medical-conditions column or map the existing column name.';
                    }

                    $sets = [];
                    $types = '';
                    $values = [];
                    foreach ($fields as $column => $field) {
                        $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
                        $sets[] = "`$safeColumn` = ?";
                        $types .= $field[1];
                        $values[] = $field[0];
                    }
                    $types .= 'i';
                    $values[] = $targetId;
                    $sql = "UPDATE patients SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
                    if ($actionError === '') {
                        $stmt = $conn->prepare($sql);
    
                        if (!$stmt) {
                            $actionError = 'Unable to prepare patient update: ' . $conn->error;
                        } else {
                            $bound = patient_bind_dynamic($stmt, $types, $values);
    
                            if (!$bound) {
                                $actionError = 'Unable to bind patient update values: ' . $stmt->error;
                                $stmt->close();
                            } elseif (!$stmt->execute()) {
                                $actionError = 'Unable to update patient: ' . $stmt->error;
                                $stmt->close();
                            } else {
                                /*
                                 * MySQL reports affected_rows = 0 when the record already
                                 * contains exactly the submitted values. Verify the record
                                 * exists, then distinguish "unchanged" from a failed update.
                                 */
                                $affected = $stmt->affected_rows;
                                $stmt->close();
    
                                $verify = $conn->prepare("SELECT id FROM patients WHERE id = ? LIMIT 1");
                                if (!$verify) {
                                    $actionError = 'The update ran, but verification failed: ' . $conn->error;
                                } else {
                                    $verify->bind_param('i', $targetId);
                                    if (!$verify->execute()) {
                                        $actionError = 'The update ran, but verification failed: ' . $verify->error;
                                    } else {
                                        $verifyResult = $verify->get_result();
                                        if (!$verifyResult || !$verifyResult->fetch_assoc()) {
                                            $actionError = 'No patient record exists with ID ' . $targetId . '.';
                                        } else {
                                            $_SESSION['patient_flash_success'] = $affected > 0
                                                ? 'Patient details updated and saved successfully.'
                                                : 'No values changed. The submitted details are already saved in the database.';
                                            $verify->close();
                                            header('Location: patients.php?id=' . $targetId);
                                            exit;
                                        }
                                    }
                                    $verify->close();
                                }
                            }
                        }
                    }
                    }
                }
            }
            $patientId = $targetId;
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM appointments WHERE patient_id=?");
            if (!$stmt) {
                $actionError = 'Unable to check appointment history: ' . $conn->error;
                $patientId = $targetId;
            } else {
                $stmt->bind_param('i', $targetId);
                if (!$stmt->execute()) {
                    $actionError = 'Unable to check appointment history: ' . $stmt->error;
                    $stmt->close();
                    $patientId = $targetId;
                } else {
                    $countResult = $stmt->get_result();
                    $countRow = $countResult ? $countResult->fetch_assoc() : ['total' => 0];
                    $stmt->close();

                if ((int)($countRow['total'] ?? 0) > 0) {
                    $actionError = 'This patient has appointment history. Deletion is blocked to protect clinic records. Edit the patient details instead.';
                    $patientId = $targetId;
                } else {
                    $stmt = $conn->prepare("DELETE FROM patients WHERE id=? LIMIT 1");
                    if (!$stmt) {
                        $actionError = 'Unable to prepare patient deletion: ' . $conn->error;
                        $patientId = $targetId;
                    } else {
                        $stmt->bind_param('i', $targetId);
                        if ($stmt->execute() && $stmt->affected_rows > 0) {
                            $_SESSION['patient_flash_success'] = 'Patient record deleted successfully.';
                            $stmt->close();
                            header('Location: patients.php');
                            exit;
                        }
                        $actionError = 'Patient was not deleted. It may already be removed or linked to other clinic records.';
                        $stmt->close();
                        $patientId = $targetId;
                    }
                }
                }
            }
        } else {
            $actionError = 'Unknown patient action.';
        }
    }
}

if (!empty($_SESSION['patient_flash_success'])) {
    $actionMessage = (string)$_SESSION['patient_flash_success'];
    unset($_SESSION['patient_flash_success']);
}

$patient = null;
$appointments = null;
$profileError = '';

/* ============================================================
   LOAD PATIENT PROFILE
   ============================================================ */
if ($patientId > 0) {
    $profileSql = "SELECT
        id, code, name,
        " . patient_sql_column($conn, 'age', 'age') . ",
        " . patient_sql_column($conn, 'gender', 'gender') . ",
        $patientEmail,
        $patientPhone,
        " . patient_sql_column($conn, 'address', 'address') . ",
        $patientArea,
        $patientDistrict,
        $patientConditions,
        $patientCreatedAt
        FROM patients WHERE id = ? LIMIT 1";

    $stmt = $conn->prepare($profileSql);
    if (!$stmt) {
        $profileError = 'Unable to load patient profile: ' . $conn->error;
        $patientId = 0;
    } else {
        $stmt->bind_param('i', $patientId);
        if (!$stmt->execute()) {
            $profileError = 'Unable to load patient profile: ' . $stmt->error;
            $patientId = 0;
        } else {
            $result = $stmt->get_result();
            $patient = $result ? $result->fetch_assoc() : null;
        }
        $stmt->close();
    }

    if (!$patient && $patientId > 0) {
        $profileError = 'Patient record not found.';
        $patientId = 0;
    }
}

/* ============================================================
   LOAD ALL APPOINTMENTS FOR PATIENT
   Include every status (Pending, Approved, Completed, Cancelled, etc.).
   Older appointments may have a missing/wrong patient_id, so also match
   by the patient's name + phone number for legacy records.
   ============================================================ */
if ($patient) {
    $appointmentColumns = [];
    $appointmentColumnResult = $conn->query("SHOW COLUMNS FROM `appointments`");
    if ($appointmentColumnResult) {
        while ($columnRow = $appointmentColumnResult->fetch_assoc()) {
            if (isset($columnRow['Field'])) {
                $appointmentColumns[] = (string)$columnRow['Field'];
            }
        }
        $appointmentColumnResult->free();
    }

    $hasAppointmentCreatedAt = in_array('created_at', $appointmentColumns, true);
    $createdAtSelect = $hasAppointmentCreatedAt
        ? "`created_at`"
        : "NULL AS `created_at`";

    $hasAppointmentPhone = in_array('phone', $appointmentColumns, true);
    $hasAppointmentName = in_array('name', $appointmentColumns, true);
    $patientPhoneDigits = preg_replace('/\\D+/', '', (string)($patient['phone'] ?? ''));
    $patientPhoneDigits = strlen($patientPhoneDigits) > 10
        ? substr($patientPhoneDigits, -10)
        : $patientPhoneDigits;

    $legacyMatch = '';
    if ($hasAppointmentPhone && $hasAppointmentName && strlen($patientPhoneDigits) === 10) {
        $legacyMatch = " OR (
            LOWER(TRIM(`name`)) = LOWER(TRIM(?))
            AND RIGHT(
                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone`, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''),
                10
            ) = ?
        )";
    }

    $appointmentSql = "SELECT
            `id`,
            " . (in_array('service', $appointmentColumns, true) ? "`service`" : "'' AS `service`") . ",
            " . (in_array('date', $appointmentColumns, true) ? "`date`" : "NULL AS `date`") . ",
            " . (in_array('time', $appointmentColumns, true) ? "`time`" : "'' AS `time`") . ",
            " . (in_array('status', $appointmentColumns, true) ? "`status`" : "'Pending' AS `status`") . ",
            $createdAtSelect
         FROM `appointments`
         WHERE `patient_id` = ?" . $legacyMatch . "
         ORDER BY `date` DESC, `id` DESC";

    $stmt = $conn->prepare($appointmentSql);
    if ($stmt) {
        if ($legacyMatch !== '') {
            $patientNameForMatch = (string)($patient['name'] ?? '');
            $stmt->bind_param('iss', $patientId, $patientNameForMatch, $patientPhoneDigits);
        } else {
            $stmt->bind_param('i', $patientId);
        }

        if ($stmt->execute()) {
            $appointments = $stmt->get_result();
        } else {
            $profileError = 'Unable to load appointments: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $profileError = 'Unable to prepare appointments query: ' . $conn->error;
    }
}

/* ============================================================
   PATIENT DIRECTORY: SEARCH, FILTERS AND SORTING
   ============================================================ */
$sortBy = (string)($_GET['sort'] ?? 'newest');
$sortDir = strtoupper((string)($_GET['dir'] ?? 'ASC'));
$sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'ASC';
$filterGender = trim((string)($_GET['gender_filter'] ?? ''));
$filterDistrict = trim((string)($_GET['district_filter'] ?? ''));
$filterCondition = trim((string)($_GET['condition_filter'] ?? ''));

$genderOptions = [];
$districtOptions = [];
$conditionOptions = [];

if (!$patient) {
    if (patient_column_exists($conn, 'gender')) {
        $opt = $conn->query("SELECT DISTINCT `gender` AS v FROM `patients` WHERE `gender` IS NOT NULL AND TRIM(`gender`)<>'' ORDER BY `gender`");
        if ($opt) { while ($o = $opt->fetch_assoc()) $genderOptions[] = (string)$o['v']; $opt->free(); }
    }
    if (patient_column_exists($conn, 'district')) {
        $opt = $conn->query("SELECT DISTINCT `district` AS v FROM `patients` WHERE `district` IS NOT NULL AND TRIM(`district`)<>'' ORDER BY `district`");
        if ($opt) { while ($o = $opt->fetch_assoc()) $districtOptions[] = (string)$o['v']; $opt->free(); }
    }
    if ($conditionsColumn) {
        $opt = $conn->query("SELECT DISTINCT `$conditionsColumn` AS v FROM `patients` WHERE `$conditionsColumn` IS NOT NULL AND TRIM(`$conditionsColumn`)<>'' ORDER BY `$conditionsColumn`");
        if ($opt) { while ($o = $opt->fetch_assoc()) $conditionOptions[] = (string)$o['v']; $opt->free(); }
    }
}

$sortMap = [
    'newest' => 'id',
    'age' => patient_column_exists($conn, 'age') ? '`age`' : 'id',
    'gender' => patient_column_exists($conn, 'gender') ? '`gender`' : 'id',
    'district' => patient_column_exists($conn, 'district') ? '`district`' : 'id',
    'condition' => $conditionsColumn ? "`$conditionsColumn`" : 'id',
    'registered' => $registeredDateColumn ? "`$registeredDateColumn`" : 'id',
    'name' => '`name`'
];
if (!isset($sortMap[$sortBy])) $sortBy = 'newest';
$sortSql = $sortMap[$sortBy];
if ($sortBy === 'newest') $sortDir = 'DESC';

$rows = null;
if (!$patient) {
    $selectPatientList = "SELECT
        `id`, `code`, `name`,
        " . patient_sql_column($conn, 'age', 'age') . ",
        " . patient_sql_column($conn, 'gender', 'gender') . ",
        $patientPhone,
        $patientDistrict,
        $patientConditions,
        $patientCreatedAt
        FROM `patients`";

    $where = [];
    $values = [];
    $types = '';

    if ($term !== '') {
        $like = '%' . $term . '%';
        $searchParts = ['`name` LIKE ?'];
        if ($phoneColumn) $searchParts[] = "`$phoneColumn` LIKE ?";
        $searchParts[] = "CONCAT('', `code`) LIKE ?";
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        foreach ($searchParts as $_) { $values[] = $like; $types .= 's'; }
    }
    if ($filterGender !== '' && patient_column_exists($conn, 'gender')) {
        $where[] = '`gender` = ?'; $values[] = $filterGender; $types .= 's';
    }
    if ($filterDistrict !== '' && patient_column_exists($conn, 'district')) {
        $where[] = '`district` = ?'; $values[] = $filterDistrict; $types .= 's';
    }
    if ($filterCondition !== '' && $conditionsColumn) {
        $where[] = "`$conditionsColumn` LIKE ?"; $values[] = '%' . $filterCondition . '%'; $types .= 's';
    }

    $sql = $selectPatientList;
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY $sortSql $sortDir, `id` DESC";

    if ($values) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $profileError = 'Unable to prepare patient list: ' . $conn->error;
        } else {
            patient_bind_dynamic($stmt, $types, $values);
            if (!$stmt->execute()) {
                $profileError = 'Unable to load patients: ' . ($stmt->error ?: $conn->error);
            } else {
                $rows = $stmt->get_result();
            }
            $stmt->close();
        }
    } else {
        $rows = $conn->query($sql);
        if (!$rows) $profileError = 'Unable to load patients: ' . $conn->error;
    }
}

function status_class($status)
{
    return strtolower(str_replace(' ', '-', (string)$status));
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $patient ? 'Patient Profile | AmarDayal Clinic' : 'Patient Directory | AmarDayal Clinic' ?></title>
<link rel="stylesheet" href="../Styles/style.css">

<style>
/* ============================================================
   PATIENT DIRECTORY / PROFILE
   ============================================================ */

.patient-page {
    max-width: none;
}

/* Shared admin sidebar layout */
body {
    margin: 0;
    padding: 0 !important;
    background: #f5f8fc;
    overflow-x: hidden;
}

.layout {
    width: 100%;
    min-height: 100vh;
    display: block;
}

.content {
    margin-left: 270px;
    width: calc(100% - 270px);
    min-height: 100vh;
    padding: 30px;
    max-width: none;
}

.page-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 22px;
}

.page-top h1 {
    margin: 0 0 5px;
}

.page-top p {
    margin: 0;
    color: #64748b;
}

.back-btn,
.profile-btn,
.manage-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    text-decoration: none;
    border-radius: 10px;
    font-weight: 800;
    transition: .2s ease;
}

.back-btn {
    padding: 11px 16px;
    background: #fff;
    border: 1px solid #dbe4ef;
    color: #17365d;
}

.back-btn:hover,
.profile-btn:hover,
.manage-btn:hover {
    transform: translateY(-1px);
}

.search-card {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.search-card input {
    flex: 1;
    min-width: 0;
}

.search-card .btn {
    white-space: nowrap;
}

/* Profile header */
.profile-hero {
    padding: 26px;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #ffffff 0%, #f5f9ff 100%);
    border: 1px solid #dce7f3;
    border-radius: 20px;
    box-shadow: 0 14px 40px rgba(15, 23, 42, .06);
}

.profile-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
}

.profile-identity {
    display: flex;
    align-items: center;
    gap: 15px;
}

.profile-avatar {
    width: 58px;
    height: 58px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #2563eb, #0f8b8d);
    color: #fff;
    font-size: 22px;
    font-weight: 900;
    box-shadow: 0 10px 25px rgba(37, 99, 235, .2);
}

.profile-identity h2 {
    margin: 0 0 4px;
    color: #0f3158;
}

.profile-identity p {
    margin: 0;
    color: #64748b;
}

.profile-code {
    background: #eaf2ff;
    color: #1d4ed8;
    padding: 9px 13px;
    border-radius: 10px;
    font-weight: 900;
}

.patient-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.info-box {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 13px;
    padding: 14px;
}

.info-box span {
    display: block;
    font-size: 11px;
    font-weight: 900;
    color: #8a9bb0;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 6px;
}

.info-box strong {
    display: block;
    color: #17365d;
    word-break: break-word;
}

.info-box.full {
    grid-column: 1 / -1;
}

/* Appointment section */
.section-card {
    padding: 24px;
    margin-bottom: 20px;
}

.section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 18px;
}

.section-title h2 {
    margin: 0 0 4px;
    color: #0f3158;
}

.section-title p {
    margin: 0;
    color: #64748b;
}

.appointment-count {
    padding: 8px 12px;
    border-radius: 999px;
    background: #eff6ff;
    color: #2563eb;
    font-weight: 900;
}

.appointment-list {
    display: grid;
    gap: 12px;
}

.appointment-item {
    display: grid;
    grid-template-columns: 100px minmax(180px, 1fr) minmax(150px, 1fr) 120px 120px auto;
    align-items: center;
    gap: 16px;
    padding: 17px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
}

.appointment-date strong,
.appointment-service strong {
    display: block;
    color: #17365d;
}

.appointment-date span,
.appointment-service span {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: #64748b;
}

.appointment-label {
    display: block;
    margin-bottom: 5px;
    font-size: 10px;
    color: #94a3b8;
    text-transform: uppercase;
    font-weight: 900;
}

.status-pill {
    display: inline-flex;
    justify-content: center;
    padding: 7px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
}

.status-pill.pending {
    background: #fff7ed;
    color: #c2410c;
}

.status-pill.approved {
    background: #ecfdf5;
    color: #15803d;
}

.status-pill.completed {
    background: #eff6ff;
    color: #1d4ed8;
}

.status-pill.rejected {
    background: #fef2f2;
    color: #dc2626;
}

.manage-btn {
    padding: 10px 14px;
    background: #2563eb;
    color: #fff;
    border: 0;
}

.empty-state {
    text-align: center;
    padding: 35px 20px;
    color: #64748b;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: #f8fafc;
}

/* Patient table */
.patient-table td:last-child {
    white-space: nowrap;
}

.profile-btn {
    padding: 8px 12px;
    background: #eff6ff;
    color: #1d4ed8;
}

.alert-error {
    margin-bottom: 18px;
    padding: 13px 15px;
    border-radius: 12px;
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    font-weight: 700;
}

/* Shared sidebar responsive layout */
@media(max-width: 900px) {
    .content {
        margin-left: 78px;
        width: calc(100% - 78px);
        padding: 24px 20px;
    }
}

@media(max-width: 520px) {
    .content {
        margin-left: 66px;
        width: calc(100% - 66px);
        padding: 20px 14px;
    }
}

/* Patient database actions */
.patient-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin:0 0 16px}
.delete-patient-form{margin:0}
.action-btn{border:0;border-radius:10px;padding:10px 15px;font:inherit;font-size:12px;font-weight:850;cursor:pointer;transition:.18s ease}
.action-btn.edit{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.action-btn.edit:hover{background:#dbeafe}
.action-btn.delete{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.action-btn.delete:hover{background:#ffe4e6}
.action-btn.save{background:#2563eb;color:#fff}
.action-btn.save:hover{background:#1d4ed8}
.action-btn.cancel{background:#f1f5f9;color:#334155}
.patient-action-alert{padding:12px 15px;border-radius:12px;margin-bottom:14px;font-size:13px;font-weight:750}
.patient-action-alert.success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.patient-action-alert.error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.patient-editor-overlay{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.58);display:flex;align-items:center;justify-content:center;padding:20px}
.patient-editor-overlay[hidden]{display:none}
.patient-editor-modal{width:min(760px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;box-shadow:0 25px 80px rgba(15,23,42,.28);padding:24px}
.editor-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:20px}
.editor-heading h2{margin:0 0 5px;color:#0f2748;font-size:22px}
.editor-heading p{margin:0;color:#64748b;font-size:12px}
.editor-close{width:34px;height:34px;border:0;border-radius:9px;background:#f1f5f9;color:#334155;font-size:23px;cursor:pointer}
.edit-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.edit-fields label{display:block;color:#475569;font-size:11px;font-weight:850}
.edit-fields input,.edit-fields select,.edit-fields textarea{display:block;width:100%;margin-top:6px;padding:11px 12px;border:1px solid #dbe4ee;border-radius:10px;background:#fff;color:#0f172a;font:inherit;font-size:13px;box-sizing:border-box}
.edit-fields textarea{resize:vertical}
.edit-fields .wide{grid-column:1/-1}
.editor-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0}
@media(max-width:600px){.patient-editor-overlay{padding:10px}.patient-editor-modal{padding:17px}.edit-fields{grid-template-columns:1fr}.edit-fields .wide{grid-column:auto}.patient-actions{justify-content:flex-start}}

/* Patient directory filters and sorting */
.patient-filter-card{display:block;padding:18px 20px;margin-bottom:20px}
.patient-search-row{display:flex;gap:10px;margin-bottom:16px}
.patient-search-row input{flex:1;min-width:0;padding:12px 14px;border:1px solid #dbe4ef;border-radius:10px;font:inherit}
.patient-filter-grid{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:13px}
.patient-filter-grid label{display:flex;flex-direction:column;gap:7px;color:#64748b;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.035em}
.patient-filter-grid select{width:100%;min-width:0;padding:11px 12px;border:1px solid #dbe4ef;border-radius:10px;background:#fff;color:#17365d;font:inherit;font-size:13px;text-transform:none;letter-spacing:normal}
.filter-actions{display:flex;align-items:end;gap:12px;flex-wrap:wrap}
.clear-filter-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 12px;color:#475569;text-decoration:none;font-size:12px;font-weight:800}
.clear-filter-btn:hover{color:#1d4ed8}
@media(max-width:900px){.patient-filter-grid{grid-template-columns:repeat(2,minmax(140px,1fr))}}
@media(max-width:560px){.patient-search-row{flex-direction:column}.patient-filter-grid{grid-template-columns:1fr}.filter-actions{align-items:center}}

/* Responsive */
@media(max-width: 1100px) {
    .patient-info-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .appointment-item {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 700px) {
    .page-top,
    .profile-top,
    .section-title {
        align-items: flex-start;
        flex-direction: column;
    }

    .search-card {
        flex-direction: column;
    }

    .patient-info-grid {
        grid-template-columns: 1fr;
    }

    .info-box.full {
        grid-column: auto;
    }

    .appointment-item {
        grid-template-columns: 1fr;
        gap: 11px;
    }

    .manage-btn {
        width: 100%;
    }
}
</style>
</head>

<body>
<div class="layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="content patient-page">

        <?php if ($patient): ?>

            <div class="page-top">
                <div>
                    <h1>Patient Profile</h1>
                    <p>Complete patient record and appointment history.</p>
                </div>

                <a class="back-btn" href="patients.php">
                    ← Back to Patients
                </a>
            </div>

            <?php if ($actionMessage): ?><div class="patient-action-alert success"><?= e($actionMessage) ?></div><?php endif; ?>
            <?php if ($actionError): ?><div class="patient-action-alert error"><?= e($actionError) ?></div><?php endif; ?>

            <div class="patient-actions">
                <button type="button" class="action-btn edit" onclick="openPatientEditor()">✎ Edit Patient</button>
                <form method="post" class="delete-patient-form" onsubmit="return confirm('Permanently delete this patient record? Deletion is allowed only when no appointments are linked.');">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['patient_csrf']) ?>">
                    <input type="hidden" name="patient_action" value="delete">
                    <input type="hidden" name="patient_id" value="<?= (int)$patientId ?>">
                    <button type="submit" class="action-btn delete">🗑 Delete Patient</button>
                </form>
            </div>

            <div class="profile-hero">
                <div class="profile-top">
                    <div class="profile-identity">
                        <div class="profile-avatar">
                            <?= e(strtoupper(substr(trim($patient['name']), 0, 1))) ?>
                        </div>

                        <div>
                            <h2><?= e($patient['name']) ?></h2>
                            <p>Patient record • <?= e($patient['phone']) ?></p>
                        </div>
                    </div>

                    <div class="profile-code">
                        AD-<?= e((string)$patient['code']) ?>
                    </div>
                </div>

                <div class="patient-info-grid">
                    <div class="info-box">
                        <span>Mobile</span>
                        <strong><?= e($patient['phone']) ?></strong>
                    </div>

                    <div class="info-box">
                        <span>Age</span>
                        <strong><?= e($patient['age'] ?: '—') ?></strong>
                    </div>

                    <div class="info-box">
                        <span>Gender</span>
                        <strong><?= e($patient['gender'] ?: '—') ?></strong>
                    </div>

                    <div class="info-box">
                        <span>District</span>
                        <strong><?= e($patient['district'] ?: '—') ?></strong>
                    </div>

                    <div class="info-box">
                        <span>Address</span>
                        <strong><?= e($patient['address'] ?: '—') ?></strong>
                    </div>

                    <div class="info-box">
                        <span>Area / Locality</span>
                        <strong><?= e($patient['area'] ?: '—') ?></strong>
                    </div>

                    <div class="info-box">
                        <span>Email</span>
                        <strong><?= e($patient['email'] ?: '—') ?></strong>
                    </div>

                    <div class="info-box">
                        <span>Registered</span>
                        <strong><?php
                            $registeredTimestamp = !empty($patient['created_at']) ? strtotime((string)$patient['created_at']) : false;
                            echo ($registeredTimestamp && date('Y', $registeredTimestamp) > 1970)
                                ? e(date('d M Y', $registeredTimestamp))
                                : 'Not recorded';
                        ?></strong>
                    </div>

                    <div class="info-box full">
                        <span>Medical / Chronic Conditions</span>
                        <strong><?= e($patient['chronic_conditions'] ?: 'No conditions recorded.') ?></strong>
                    </div>
                </div>
            </div>

            <div class="patient-editor-overlay" id="patientEditor" hidden>
                <section class="patient-editor-modal" role="dialog" aria-modal="true" aria-labelledby="patientEditorTitle">
                    <div class="editor-heading">
                        <div><h2 id="patientEditorTitle">Edit Patient Details</h2><p>Changes are saved directly to the patient database.</p></div>
                        <button type="button" class="editor-close" onclick="closePatientEditor()" aria-label="Close">×</button>
                    </div>
                    <form method="post" class="patient-edit-form">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['patient_csrf']) ?>">
                        <input type="hidden" name="patient_action" value="update">
                        <input type="hidden" name="patient_id" value="<?= (int)$patientId ?>">
                        <div class="edit-fields">
                            <label>Patient Name *<input name="name" required maxlength="150" value="<?= e($patient['name']) ?>"></label>
                            <label>Phone Number *<input name="phone" type="tel" required maxlength="30" value="<?= e($patient['phone']) ?>"></label>
                            <label>Age<input name="age" type="number" min="0" max="125" value="<?= e((string)($patient['age'] ?? '')) ?>"></label>
                            <label>Gender<select name="gender"><option value="">Select gender</option><?php foreach (['Male','Female','Other'] as $genderOption): ?><option value="<?= e($genderOption) ?>" <?= ($patient['gender'] ?? '') === $genderOption ? 'selected' : '' ?>><?= e($genderOption) ?></option><?php endforeach; ?></select></label>
                            <label>District<input name="district" maxlength="100" value="<?= e($patient['district'] ?? '') ?>"></label>
                            <label>Area / Locality<input name="area" maxlength="150" value="<?= e($patient['area'] ?? '') ?>"></label>
                            <label class="wide">Address<textarea name="address" rows="2" maxlength="500"><?= e($patient['address'] ?? '') ?></textarea></label>
                            <label>Email<input name="email" type="email" maxlength="190" value="<?= e($patient['email'] ?? '') ?>"></label>
                            <label class="wide">Medical / Chronic Conditions<textarea name="chronic_conditions" rows="3" maxlength="2000"><?= e($patient['chronic_conditions'] ?? '') ?></textarea></label>
                        </div>
                        <div class="editor-actions"><button type="button" class="action-btn cancel" onclick="closePatientEditor()">Cancel</button><button type="submit" class="action-btn save">Save Changes</button></div>
                    </form>
                </section>
            </div>

            <div class="card section-card">
                <div class="section-title">
                    <div>
                        <h2>All Appointments</h2>
                        <p>Every appointment belonging to this patient is shown here.</p>
                    </div>

                    <span class="appointment-count">
                        <?= $appointments ? $appointments->num_rows : 0 ?> Appointment(s)
                    </span>
                </div>

                <?php if ($appointments && $appointments->num_rows > 0): ?>
                    <div class="appointment-list">
                        <?php while ($a = $appointments->fetch_assoc()): ?>
                            <div class="appointment-item">

                                <div class="appointment-date">
                                    <span class="appointment-label">Date</span>
                                    <strong><?= e(date('d M Y', strtotime($a['date']))) ?></strong>
                                    <span><?= e($a['time'] ?: 'Time not set') ?></span>
                                </div>

                                <div class="appointment-service">
                                    <span class="appointment-label">Service</span>
                                    <strong><?= e($a['service'] ?: 'General Consultation') ?></strong>
                                    <span>Appointment #<?= e((string)$a['id']) ?></span>
                                </div>

                                <div>
                                    <span class="appointment-label">Requested</span>
                                    <strong><?= e(date('d M Y, h:i A', strtotime($a['created_at']))) ?></strong>
                                </div>

                                <div>
                                    <span class="appointment-label">Status</span>
                                    <span class="status-pill <?= e(status_class($a['status'])) ?>">
                                        <?= e($a['status']) ?>
                                    </span>
                                </div>

                                <div>
                                    <span class="appointment-label">Clinic</span>
                                    <strong>Clinic Appointment</strong>
                                </div>

                                <div>
                                    <a
                                        class="manage-btn"
                                        href="UpdateAppointment.php?id=<?= (int)$a['id'] ?>&patient_id=<?= (int)$patientId ?>"
                                    >
                                        Manage
                                    </a>
                                </div>

                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        No appointments have been recorded for this patient yet.
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="page-top">
                <div>
                    <h1>Patient Directory</h1>
                    <p>Search patients and open their complete profile and appointment history.</p>
                </div>
            </div>

            <?php if ($profileError): ?>
                <div class="alert-error"><?= e($profileError) ?></div>
            <?php endif; ?>

            <form class="card patient-filter-card" method="get">
                <div class="patient-search-row">
                    <input name="q" value="<?= e($term) ?>" placeholder="Search by patient name, mobile number or patient ID">
                    <button class="btn" type="submit">🔍 Search</button>
                </div>
                <div class="patient-filter-grid">
                    <label>Sort patients by
                        <select name="sort">
                            <option value="newest" <?= $sortBy==='newest'?'selected':'' ?>>Recently added</option>
                            <option value="age" <?= $sortBy==='age'?'selected':'' ?>>Age</option>
                            <option value="gender" <?= $sortBy==='gender'?'selected':'' ?>>Gender</option>
                            <option value="district" <?= $sortBy==='district'?'selected':'' ?>>District</option>
                            <option value="condition" <?= $sortBy==='condition'?'selected':'' ?>>Medical condition</option>
                            <option value="registered" <?= $sortBy==='registered'?'selected':'' ?>>Registered date</option>
                            <option value="name" <?= $sortBy==='name'?'selected':'' ?>>Patient name</option>
                        </select>
                    </label>
                    <label>Order
                        <select name="dir">
                            <option value="ASC" <?= $sortDir==='ASC'?'selected':'' ?>>Ascending (A–Z / low–high / oldest)</option>
                            <option value="DESC" <?= $sortDir==='DESC'?'selected':'' ?>>Descending (Z–A / high–low / newest)</option>
                        </select>
                    </label>
                    <label>Gender
                        <select name="gender_filter">
                            <option value="">All genders</option>
                            <?php foreach ($genderOptions as $option): ?>
                                <option value="<?= e($option) ?>" <?= $filterGender===$option?'selected':'' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>District
                        <select name="district_filter">
                            <option value="">All districts</option>
                            <?php foreach ($districtOptions as $option): ?>
                                <option value="<?= e($option) ?>" <?= $filterDistrict===$option?'selected':'' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Medical condition
                        <select name="condition_filter">
                            <option value="">All conditions</option>
                            <?php foreach ($conditionOptions as $option): ?>
                                <option value="<?= e($option) ?>" <?= $filterCondition===$option?'selected':'' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button class="btn" type="submit">Apply Filters</button>
                        <a class="clear-filter-btn" href="patients.php">Clear all</a>
                    </div>
                </div>
            </form>

            <div class="card">
                <div class="table-wrap">
                    <table class="table patient-table">
                        <tr>
                            <th>Patient ID</th>
                            <th>Name</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Mobile</th>
                            <th>District</th>
                            <th>Conditions</th>
                            <th>Registered</th>
                            <th>Profile</th>
                        </tr>

                        <?php if ($rows && $rows->num_rows > 0): ?>
                            <?php while ($r = $rows->fetch_assoc()): ?>
                                <tr>
                                    <td>AD-<?= e((string)$r['code']) ?></td>
                                    <td><strong><?= e($r['name']) ?></strong></td>
                                    <td><?= e($r['age'] ?? '—') ?: '—' ?></td>
                                    <td><?= e($r['gender'] ?? '—') ?: '—' ?></td>
                                    <td><?= e($r['phone'] ?? '—') ?: '—' ?></td>
                                    <td><?= e($r['district'] ?? '—') ?: '—' ?></td>
                                    <td><?= e($r['chronic_conditions'] ?? '—') ?: '—' ?></td>
                                    <td><?php
                                        $registered = !empty($r['created_at']) ? strtotime((string)$r['created_at']) : false;
                                        echo ($registered && date('Y', $registered) > 1970) ? e(date('d M Y', $registered)) : '—';
                                    ?></td>
                                    <td><a class="profile-btn" href="patients.php?id=<?= (int)$r['id'] ?>">View Profile →</a></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;padding:30px;color:#64748b">No patients found for these search/filter options.</td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </main>
</div>
<script>
function openPatientEditor(){const modal=document.getElementById('patientEditor');if(modal){modal.hidden=false;document.body.style.overflow='hidden';modal.querySelector('input[name="name"]')?.focus();}}
function closePatientEditor(){const modal=document.getElementById('patientEditor');if(modal)modal.hidden=true;document.body.style.overflow='';}
document.addEventListener('keydown',function(event){if(event.key==='Escape')closePatientEditor();});
document.getElementById('patientEditor')?.addEventListener('click',function(event){if(event.target===this)closePatientEditor();});
</script>
</body>
</html>
