<?php
require_once 'config.php';

/* ================================================================
   AMARDAYAL CLINIC - PUBLIC APPOINTMENT BOOKING
   ================================================================

   STEP 1
   -> New Patient / Old Patient

   STEP 2
   -> Patient Name + Mobile Number

   STEP 3
   -> Disease / Problem
   -> Problem Duration
   -> Address
   -> District
   -> Clinic
   -> Appointment Date
   -> 10-minute Time Slot
   -> Service

   CLINICS

   Sankisa Clinic
   Every day except Tuesday / holidays
   09:30 AM - 02:30 PM

   Mainpuri Clinic
   Wednesday + Saturday only
   04:00 PM - 06:30 PM

   TIME BUTTONS DISPLAY START TIME ONLY

   09:30 AM
   09:40 AM
   09:50 AM
   10:00 AM
   ...

   ================================================================ */


/* ================================================================
   SAFE HTML ESCAPE
   ================================================================ */

if (!function_exists('adc_e')) {

    function adc_e($value)
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* ================================================================
   CHECK DATABASE COLUMN
   ================================================================ */

/* ================================================================
   DATABASE SCHEMA
   ================================================================

   Database tables and required columns are created/updated by the
   one-time database migration file. This page must never run ALTER
   TABLE or CREATE TABLE statements for normal visitors.
   ================================================================ */


/* ================================================================
   COMMON HOLIDAYS
   ================================================================

   These can be extended through:

   phpMyAdmin
   -> amar_dayal_clinic
   -> clinic_holidays

   Database holidays take priority.

   ================================================================ */

$builtInHolidays = [

    /* 2026 */

    '2026-10-20'
        => 'Dussehra / Vijayadashami',

    '2026-11-08'
        => 'Diwali',

    '2026-11-09'
        => 'Govardhan Puja',

    '2026-11-10'
        => 'Bhai Dooj',

    '2026-12-25'
        => 'Christmas / Clinic Holiday',


    /* 2027 */

    '2027-01-15'
        => 'Makar Sankranti',

    '2027-02-11'
        => 'Vasant Panchami',

    '2027-03-06'
        => 'Maha Shivaratri',

    '2027-03-22'
        => 'Holi',

    '2027-04-15'
        => 'Ram Navami',

    '2027-04-20'
        => 'Hanuman Jayanti',

    '2027-05-20'
        => 'Buddha Purnima / Buddha Jayanti',

    '2027-08-25'
        => 'Janmashtami',

    '2027-09-04'
        => 'Ganesh Chaturthi',

    '2027-10-09'
        => 'Dussehra / Vijayadashami',

    '2027-10-29'
        => 'Diwali',

    '2027-10-30'
        => 'Govardhan Puja',

    '2027-10-31'
        => 'Bhai Dooj'
];


/* ================================================================
   CLINICS
   ================================================================ */

$clinics = [

    'Sankisa Clinic',

    'Mainpuri Clinic'

];

/* ================================================================
   STANDARD DISEASE / PROBLEM BUTTONS
   ================================================================ */

$standardDiseases = [
    'Knee pain',
    'Back pain',
    'Lower Back pain',
    'Shoulder pain',
    'Neck pain',
    'Servical',
    'Sciatica',
    'Migraine',
    'Ankle pain',
    'Spondylitis',
    'Skin disease',
    'Diabetes',
    'Blood pressure',
    'Asthma'
    
];


/* ================================================================
   BASIC VARIABLES
   ================================================================ */

$error = '';


$success =
    isset($_GET['success'])
    &&
    $_GET['success'] === '1';


$appointmentRef =
    (int)(
        $_GET['ref']
        ?? 0
    );


$successReg =
    trim(
        $_GET['reg']
        ?? ''
    );


$successAppointment = null;

if ($success && $appointmentRef > 0) {

    $stmt = $conn->prepare("
        SELECT
            id,
            date,
            time,
            clinic,
            booked_at
        FROM appointments
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $appointmentRef
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $successAppointment =
            $result
            ? $result->fetch_assoc()
            : null;

        $stmt->close();
    }
}    

/* ================================================================
   SESSION DATA
   ================================================================ */

$name =
    trim(
        $_POST['name']
        ??
        $_SESSION['appointment_name']
        ??
        ''
    );


$phone =
    trim(
        $_POST['phone']
        ??
        $_SESSION['appointment_phone']
        ??
        ''
    );


$type =
    trim(
        $_POST['patient_type']
        ??
        $_SESSION['appointment_type']
        ??
        ''
    );


$patientId =
    (int)(
        $_SESSION['appointment_patient_id']
        ??
        0
    );

$patientCode = '';

$matchedPatient = null;

$patientsByPhone = [];

$pastProblems = [];

$pastProblemDetails = [];

$selectedPastProblemDuration = [
    'duration' => '',
    'unit' => 'Days'
];

$problemChoices = $_POST['problem_choices'] ?? [];

if (!is_array($problemChoices)) {
    $problemChoices = [];
}

$problemChoices = array_values(
    array_filter(
        array_map('trim', $problemChoices),
        function ($value) {
            return $value !== '';
        }
    )
);

$problemChoice = implode(', ', $problemChoices);

$diseasePreset = trim(
    $_POST['disease_preset']
    ??
    ''
);

/* ================================================================
   APPOINTMENT FORM DATA
   ================================================================ */

$problem =
    trim(
        $_POST['problem']
        ??
        ''
    );


$duration =
    trim(
        $_POST['problem_duration']
        ??
        ''
    );


$durationUnit =
    trim(
        $_POST['problem_duration_unit']
        ??
        'Days'
    );


$address =
    trim(
        $_POST['address']
        ??
        ''
    );


$district =
    trim(
        $_POST['district']
        ??
        ''
    );


$date =
    trim(
        $_POST['date']
        ??
        ''
    );


$clinic =
    trim(
        $_POST['clinic']
        ??
        ''
    );


$time =
    trim(
        $_POST['time']
        ??
        ''
    );


$service =
    trim(
        $_POST['service']
        ??
        (
            $services[0]
            ??
            'General Consultation'
        )
    );


/* ================================================================
   CURRENT STEP
   ================================================================ */

$step =

    $success

    ? 4

    :

    (int)(
        $_POST['step']
        ??
        $_GET['step']
        ??
        1
    );


if (
    $step < 1
    ||
    $step > 4
) {

    $step = 1;
}


/* ================================================================
   VALIDATE PHONE
   ================================================================ */

function adc_valid_phone(
    $phone
) {

    $digits =
        preg_replace(
            '/\D+/',
            '',
            $phone
        );

    return strlen($digits) === 10;
}


/* ================================================================
   GET HOLIDAY
   ================================================================ */

function adc_get_holiday(
    $db,
    $date,
    $builtInHolidays
) {

    $stmt =
        $db->prepare(
"
SELECT holiday_name

FROM clinic_holidays

WHERE holiday_date = ?

LIMIT 1
"
        );


    if ($stmt) {

        $stmt->bind_param(
            's',
            $date
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $row =
            $result
            ?
            $result->fetch_assoc()
            :
            null;

        $stmt->close();


        if ($row) {

            return
                $row['holiday_name'];
        }
    }


    return
        $builtInHolidays[$date]
        ??
        null;
}


/* ================================================================
   CLINIC SCHEDULE
   ================================================================

   IMPORTANT:

   The slots are START TIMES.

   Sankisa:

   09:30
   09:40
   09:50
   10:00
   ...

   Mainpuri:

   04:00
   04:10
   04:20
   ...

   ================================================================ */

function adc_schedule(
    $clinic,
    $date
) {

    $dateObject =
        DateTime::createFromFormat(
            'Y-m-d',
            $date
        );


    if (
        !$dateObject
        ||
        $dateObject->format('Y-m-d')
        !==
        $date
    ) {

        return [

            'open' =>
                false,

            'reason' =>
                'Please choose a valid date.',

            'slots' =>
                []

        ];
    }


    /*
       PHP:

       Monday    = 1
       Tuesday   = 2
       Wednesday = 3
       Thursday  = 4
       Friday    = 5
       Saturday  = 6
       Sunday    = 7
    */

    $day =
        (int)$dateObject->format('N');


    /* ============================================================
       TUESDAY
       ============================================================ */

    if ($day === 2) {

        return [

            'open' =>
                false,

            'reason' =>
                'Both clinics are closed every Tuesday.',

            'slots' =>
                []

        ];
    }


    /* ============================================================
       SANKISA
       ============================================================ */

    if (
        $clinic ===
        'Sankisa Clinic'
    ) {

        /*
           09:30 AM
           to
           02:30 PM
        */

        $start =
            (9 * 60) + 30;

        $end =
            (14 * 60) + 30;
    }


    /* ============================================================
       MAINPURI
       ============================================================ */

    elseif (
        $clinic ===
        'Mainpuri Clinic'
    ) {

        if (
            !in_array(
                $day,
                [3, 6],
                true
            )
        ) {

            return [

                'open' =>
                    false,

                'reason' =>
                    'Mainpuri Clinic is open only on Wednesday and Saturday.',

                'slots' =>
                    []

            ];
        }


        /*
           04:00 PM
           to
           06:30 PM
        */

        $start =
            16 * 60;

        $end =
            (18 * 60) + 30;
    }


    else {

        return [

            'open' =>
                false,

            'reason' =>
                'Please select a clinic.',

            'slots' =>
                []

        ];
    }


    /* ============================================================
       CREATE 10-MINUTE START TIME SLOTS
       ============================================================ */

    $slots = [];


    for (
        $minutes = $start;
        $minutes < $end;
        $minutes += 10
    ) {

        $hour =
            intdiv(
                $minutes,
                60
            );


        $minute =
            $minutes % 60;


        $value =
            sprintf(
                '%02d:%02d:00',
                $hour,
                $minute
            );


        $label =
            date(
                'g:i A',
                strtotime($value)
            );


        $slots[] = [

            'value' =>
                $value,

            'label' =>
                $label

        ];
    }


    return [

        'open' =>
            true,

        'reason' =>
            '',

        'slots' =>
            $slots

    ];
}


/* ================================================================
   FIND OLD PATIENT
   ================================================================ */

function adc_find_patients_by_phone(
    $db,
    $phone
) {

    $patients = [];

    $stmt = $db->prepare(
"
SELECT
    id,
    code,
    name,
    phone,
    age,
    gender,
    address,
    district
FROM patients
WHERE phone = ?
ORDER BY id DESC
"
    );

    if (!$stmt) {
        return $patients;
    }

    $stmt->bind_param(
        's',
        $phone
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $patients[] = $row;
        }
    }

    $stmt->close();

    return $patients;
}


function adc_find_patient_by_id(
    $db,
    $patientId
) {

    $stmt = $db->prepare(
"
SELECT
    id,
    code,
    name,
    phone,
    age,
    gender,
    address,
    district
FROM patients
WHERE id = ?
LIMIT 1
"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'i',
        $patientId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result
        ? $result->fetch_assoc()
        : null;

    $stmt->close();

    return $row;
}


function adc_find_duplicate_patient(
    $db,
    $phone,
    $name
) {

    $stmt = $db->prepare(
"
SELECT
    id,
    code,
    name,
    phone,
    age,
    gender,
    address,
    district
FROM patients
WHERE phone = ?
  AND LOWER(TRIM(name)) = LOWER(TRIM(?))
ORDER BY id DESC
LIMIT 1
"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'ss',
        $phone,
        $name
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result
        ? $result->fetch_assoc()
        : null;

    $stmt->close();

    return $row;
}


function adc_find_patient_problems(
    $db,
    $patientId
) {

    $problems = [];

    /*
       Past problems are taken from this patient's previous
       appointment/visit records. Pending and rejected requests
       are not treated as past visits.
    */
    $stmt = $db->prepare(
"
SELECT DISTINCT
    TRIM(
        CASE
            WHEN TRIM(COALESCE(problem, '')) <> ''
                THEN problem
            ELSE disease
        END
    ) AS past_problem
FROM appointments
WHERE patient_id = ?
  AND LOWER(COALESCE(status, '')) NOT IN ('pending', 'rejected')
  AND (date IS NULL OR date <= CURDATE())
  AND TRIM(
        CASE
            WHEN TRIM(COALESCE(problem, '')) <> ''
                THEN problem
            ELSE disease
        END
      ) <> ''
ORDER BY past_problem ASC
"
    );

    if (!$stmt) {
        return $problems;
    }

    $stmt->bind_param(
        'i',
        $patientId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $value = trim((string)($row['past_problem'] ?? ''));
            if ($value !== '') {
                /* A previous appointment may contain multiple problems. */
                $parts = array_map('trim', explode(',', $value));
                foreach ($parts as $part) {
                    if ($part !== '' && !in_array($part, $problems, true)) {
                        $problems[] = $part;
                    }
                }
            }
        }
    }

    $stmt->close();

    return $problems;
}


function adc_find_patient_problem_duration(
    $db,
    $patientId,
    $problem
) {

    $data = [
        'duration' => '',
        'unit' => 'Days'
    ];

    $stmt = $db->prepare(
"
SELECT
    problem_duration,
    problem_duration_unit
FROM appointments
WHERE patient_id = ?
  AND LOWER(TRIM(
        CASE
            WHEN TRIM(COALESCE(problem, '')) <> ''
                THEN problem
            ELSE disease
        END
      )) = LOWER(TRIM(?))
  AND LOWER(COALESCE(status, '')) NOT IN ('pending', 'rejected')
  AND (date IS NULL OR date <= CURDATE())
ORDER BY date DESC, id DESC
LIMIT 1
"
    );

    if (!$stmt) {
        return $data;
    }

    $stmt->bind_param(
        'is',
        $patientId,
        $problem
    );

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && ($row = $result->fetch_assoc())) {
        $data['duration'] = trim((string)($row['problem_duration'] ?? ''));
        $unit = trim((string)($row['problem_duration_unit'] ?? 'Days'));

        if (in_array($unit, ['Days', 'Weeks', 'Months', 'Years'], true)) {
            $data['unit'] = $unit;
        }
    }

    $stmt->close();

    return $data;
}


function adc_pending_appointment(
    $db,
    $patientId
) {

    $stmt = $db->prepare(
"
SELECT
    id,
    date,
    time,
    clinic,
    service
FROM appointments
WHERE patient_id = ?
AND status = 'Pending'
ORDER BY id DESC
LIMIT 1
"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'i',
        $patientId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result
        ? $result->fetch_assoc()
        : null;

    $stmt->close();

    return $row;
}


/* ================================================================
   BOOKED TIME SLOTS
   ================================================================ */

function adc_booked_times(
    $db,
    $clinic,
    $date
) {

    $booked = [];

    $stmt = $db->prepare(
"
SELECT time
FROM appointments
WHERE clinic = ?
  AND date = ?
  AND status IN ('Pending', 'Approved', 'Completed')
"
    );

    if (!$stmt) {
        return $booked;
    }

    $stmt->bind_param(
        'ss',
        $clinic,
        $date
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $value = trim((string)($row['time'] ?? ''));
            if ($value !== '') {
                $booked[] = $value;
            }
        }
    }

    $stmt->close();

    return array_values(array_unique($booked));
}


/* ================================================================
   AJAX TIME SLOT LOOKUP
   ================================================================ */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'slots') {

    header('Content-Type: application/json; charset=utf-8');

    $ajaxClinic = trim((string)($_GET['clinic'] ?? ''));
    $ajaxDate = trim((string)($_GET['date'] ?? ''));

    $ajaxHoliday = adc_get_holiday(
        $conn,
        $ajaxDate,
        $builtInHolidays
    );

    $ajaxSchedule = adc_schedule(
        $ajaxClinic,
        $ajaxDate
    );

    if ($ajaxHoliday) {
        echo json_encode([
            'open' => false,
            'reason' => 'Clinic closed: ' . $ajaxHoliday,
            'slots' => [],
            'booked' => []
        ]);
        exit;
    }

    if (!$ajaxSchedule['open']) {
        echo json_encode([
            'open' => false,
            'reason' => $ajaxSchedule['reason'],
            'slots' => [],
            'booked' => []
        ]);
        exit;
    }

    $booked = adc_booked_times(
        $conn,
        $ajaxClinic,
        $ajaxDate
    );

    echo json_encode([
        'open' => true,
        'reason' => '',
        'slots' => $ajaxSchedule['slots'],
        'booked' => $booked
    ]);

    exit;
}


/* ================================================================
   AJAX MOBILE LOOKUP
   ================================================================
*/

if (
    isset($_GET['lookup_phone'])
) {

    $lookupPhone = preg_replace(
        '/\D+/',
        '',
        trim(
            $_GET['lookup_phone']
        )
    );

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    if (!adc_valid_phone($lookupPhone)) {

        echo json_encode([
            'success' => false,
            'patients' => []
        ]);

        exit;
    }

    $patients =
        adc_find_patients_by_phone(
            $conn,
            $lookupPhone
        );

    foreach ($patients as &$lookupPatient) {

        $lookupPatient['pending'] =
            adc_pending_appointment(
                $conn,
                (int)$lookupPatient['id']
            )
            ? true
            : false;
    }

    unset($lookupPatient);

    echo json_encode([
        'success' => true,
        'patients' => $patients
    ]);

    exit;
}



/* ================================================================
   STEP 1
   PHONE LOOKUP / PATIENT SELECTION
   ================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    $step === 1
) {

    $action = trim(
        $_POST['lookup_action'] ?? 'lookup'
    );

    /* ------------------------------------------------------------
       SELECT AN EXISTING PATIENT
       ------------------------------------------------------------ */

    if ($action === 'select_patient') {

        $selectedPatientId = (int)(
            $_POST['patient_id'] ?? 0
        );

        if ($selectedPatientId <= 0) {

            $error =
                'Please select a patient.';

        } else {

            $selectedPatient =
                adc_find_patient_by_id(
                    $conn,
                    $selectedPatientId
                );

            if (!$selectedPatient) {

                $error =
                    'The selected patient could not be found.';

            } else {

                $pending =
                    adc_pending_appointment(
                        $conn,
                        $selectedPatientId
                    );

                if ($pending) {

                    $pendingDate =
                        $pending['date']
                        ?
                        date(
                            'd M Y',
                            strtotime($pending['date'])
                        )
                        :
                        '';

                    $pendingTime =
                        $pending['time']
                        ?
                        date(
                            'g:i A',
                            strtotime($pending['time'])
                        )
                        :
                        '';

                    $error =
                        'This patient already has a pending appointment'
                        .
                        ($pendingDate ? ' on ' . $pendingDate : '')
                        .
                        ($pendingTime ? ' at ' . $pendingTime : '')
                        .
                        '. Please wait for the existing appointment to be processed.';

                    $phone =
                        trim(
                            $_POST['phone']
                            ??
                            $selectedPatient['phone']
                            ??
                            ''
                        );

                    $patientsByPhone =
                        adc_find_patients_by_phone(
                            $conn,
                            $phone
                        );

                    $step = 1;

                } else {

                    $patientId =
                        (int)$selectedPatient['id'];

                    $patientCode =
                        (string)$selectedPatient['code'];

                    $name =
                        (string)$selectedPatient['name'];

                    $phone =
                        (string)$selectedPatient['phone'];

                    $_SESSION[
                        'appointment_patient_id'
                    ] = $patientId;

                    $_SESSION[
                        'appointment_type'
                    ] = 'old';

                    $_SESSION[
                        'appointment_name'
                    ] = $name;

                    $_SESSION[
                        'appointment_phone'
                    ] = $phone;

                    $type = 'old';

                    $matchedPatient =
                        $selectedPatient;

                    $address =
                        (string)(
                            $selectedPatient['address']
                            ??
                            ''
                        );

                    $district =
                        (string)(
                            $selectedPatient['district']
                            ??
                            ''
                        );

                    $step = 3;
                }
            }
        }
    }

    /* ------------------------------------------------------------
       ADD A NEW PATIENT USING THE SAME MOBILE NUMBER
       ------------------------------------------------------------ */

    elseif ($action === 'add_new') {

        $phone = trim(
            $_POST['phone'] ?? ''
        );

        if (!adc_valid_phone($phone)) {

            $error =
                'Please enter a valid 10-digit mobile number.';

            $step = 1;

        } else {

            $_SESSION[
                'appointment_phone'
            ] = $phone;

            $_SESSION[
                'appointment_type'
            ] = 'new';

            unset(
                $_SESSION['appointment_patient_id'],
                $_SESSION['appointment_name']
            );

            $type = 'new';
            $patientId = 0;
            $name = '';
            $step = 2;
        }
    }

    /* ------------------------------------------------------------
       MOBILE NUMBER LOOKUP
       ------------------------------------------------------------ */

    else {

        $phone = preg_replace(
            '/\D+/',
            '',
            trim(
                $_POST['phone'] ?? ''
            )
        );

        if (!adc_valid_phone($phone)) {

            $error =
                'Please enter a valid 10-digit mobile number.';

        } else {

            $_SESSION[
                'appointment_phone'
            ] = $phone;

            $patientsByPhone =
                adc_find_patients_by_phone(
                    $conn,
                    $phone
                );

            if (!$patientsByPhone) {

                $_SESSION[
                    'appointment_type'
                ] = 'new';

                unset(
                    $_SESSION['appointment_patient_id'],
                    $_SESSION['appointment_name']
                );

                $type = 'new';
                $patientId = 0;
                $name = '';
                $step = 2;

            } else {

                $step = 1;
            }
        }
    }
}


/* ================================================================
   STEP 2
   NEW PATIENT NAME
   ================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    $step === 2
) {

    $name = trim(
        $_POST['name'] ?? ''
    );

    $phone = preg_replace(
        '/\D+/',
        '',
        trim(
            $_POST['phone']
            ??
            $_SESSION['appointment_phone']
            ??
            ''
        )
    );

    $type = 'new';

    if (
        mb_strlen($name) < 2
    ) {

        $error =
            'Please enter the patient name.';

    } elseif (!adc_valid_phone($phone)) {

        $error =
            'Please enter a valid 10-digit mobile number.';

    } else {

        $duplicatePatient = adc_find_duplicate_patient(
            $conn,
            $phone,
            $name
        );

        if ($duplicatePatient) {

            $error =
                'A patient with the same name and mobile number already exists. Please select that patient instead of creating a duplicate profile.';

            $patientsByPhone = adc_find_patients_by_phone(
                $conn,
                $phone
            );

            $step = 1;

        } else {

            $_SESSION[
                'appointment_name'
        ] = $name;

        $_SESSION[
            'appointment_phone'
        ] = $phone;

        $_SESSION[
            'appointment_type'
        ] = 'new';

        unset(
            $_SESSION['appointment_patient_id']
        );

            $patientId = 0;
            $step = 3;
        }
    }
}

/* ================================================================
   LOAD OLD PATIENT
   ================================================================ */

if (
    $step === 3
    &&
    $type === 'old'
    &&
    $patientId > 0
) {

    $stmt =
        $conn->prepare(
"
SELECT

    id,
    code,
    name,
    phone,
    age,
    gender,
    address,
    district

FROM patients

WHERE id = ?

LIMIT 1
"
        );


    if ($stmt) {

        $stmt->bind_param(
            'i',
            $patientId
        );


        $stmt->execute();


        $result =
            $stmt->get_result();


        $matchedPatient =
            $result
            ?
            $result->fetch_assoc()
            :
            null;


        $stmt->close();
    }


    if ($matchedPatient) {

        $pastProblems = adc_find_patient_problems(
            $conn,
            $patientId
        );

        $patientCode =
            (string)$matchedPatient['code'];


        $name =
            (string)$matchedPatient['name'];


        $phone =
            (string)$matchedPatient['phone'];


        if (
            $_SERVER['REQUEST_METHOD']
            !==
            'POST'
        ) {

            $address =
                (string)(
                    $matchedPatient['address']
                    ??
                    ''
                );


            $district =
                (string)(
                    $matchedPatient['district']
                    ??
                    ''
                );
        }
    }
}


/* ================================================================
   STEP 3
   SAVE APPOINTMENT
   ================================================================ */

if (
    $_SERVER['REQUEST_METHOD']
    ===
    'POST'

    &&

    $step === 3
) {

    $type =
        trim(
            $_POST['patient_type']
            ??
            $_SESSION[
                'appointment_type'
            ]
            ??
            ''
        );


    $name =
        trim(
            $_POST['name']
            ??
            $_SESSION[
                'appointment_name'
            ]
            ??
            ''
        );


    $phone =
        trim(
            $_POST['phone']
            ??
            $_SESSION[
                'appointment_phone'
            ]
            ??
            ''
        );


    $problemChoices = $_POST['problem_choices'] ?? [];

    if (!is_array($problemChoices)) {
        $problemChoices = [];
    }

    $problemChoices = array_values(
        array_filter(
            array_map('trim', $problemChoices),
            function ($value) {
                return $value !== '';
            }
        )
    );

    $postedProblem = trim(
        $_POST['problem']
        ??
        ''
    );

    $problem = $postedProblem;


    $duration =
        trim(
            $_POST['problem_duration']
            ??
            ''
        );


    $durationUnit =
        trim(
            $_POST[
                'problem_duration_unit'
            ]
            ??
            ''
        );


    $address =
        trim(
            $_POST['address']
            ??
            ''
        );


    $district =
        trim(
            $_POST['district']
            ??
            ''
        );


    $date =
        trim(
            $_POST['date']
            ??
            ''
        );


    $clinic =
        trim(
            $_POST['clinic']
            ??
            ''
        );


    $time =
        trim(
            $_POST['time']
            ??
            ''
        );


    $service =
        trim(
            $_POST['service']
            ??
            (
                $services[0]
                ??
                'General Consultation'
            )
        );


    $diseasePreset = trim(
        $_POST['disease_preset']
        ??
        ''
    );


    /* ============================================================
       OLD PATIENT MASTER DATA IS READ-ONLY
       ============================================================ */

    if (
        $type === 'old'
        &&
        $patientId > 0
    ) {

        $lockedPatient = adc_find_patient_by_id(
            $conn,
            $patientId
        );

        if ($lockedPatient) {

            /* Never trust editable POST values for old patients. */
            $name = (string)($lockedPatient['name'] ?? $name);
            $phone = (string)($lockedPatient['phone'] ?? $phone);
            $address = (string)($lockedPatient['address'] ?? '');
            $district = (string)($lockedPatient['district'] ?? '');

            $matchedPatient = $lockedPatient;
        }
    }


    /* ============================================================
       OLD PATIENT PROBLEM SELECTION
       ============================================================ */

    if (
        $type === 'old'
        &&
        $patientId > 0
    ) {

        $pastProblems = adc_find_patient_problems(
            $conn,
            $patientId
        );

        $problemChoice = trim(
            $_POST['past_problem_choice'] ?? ''
        );

        if ($problemChoice !== '' && $problemChoice !== '__new__') {
            if (in_array($problemChoice, $pastProblems, true)) {
                $problem = $problemChoice;

                $selectedPastProblemDuration = adc_find_patient_problem_duration(
                    $conn,
                    $patientId,
                    $problemChoice
                );

                if ($selectedPastProblemDuration['duration'] !== '') {
                    $duration = $selectedPastProblemDuration['duration'];
                    $durationUnit = $selectedPastProblemDuration['unit'];
                }
            } else {
                $error = 'Please choose a valid past disease / problem.';
            }
        } elseif ($problemChoice === '__new__') {

            if (in_array($diseasePreset, $standardDiseases, true)) {
                $problem = $diseasePreset;
            } else {
                $problem = trim($_POST['problem'] ?? '');
            }
        }
    }


    /* ============================================================
       NEW PATIENT / NEW PROBLEM DISEASE BUTTON
       ============================================================ */

    if (
        $type === 'new'
        ||
        ($type === 'old' && ($problemChoice ?? '') === '__new__')
    ) {

        if ($problemChoices) {
            $problem = implode(', ', $problemChoices);

            if ($diseasePreset === '__other__' && $postedProblem !== '') {
                $problem = $postedProblem;
            }
        } elseif ($diseasePreset === '__other__') {
            $problem = $postedProblem;
        } elseif (in_array($diseasePreset, $standardDiseases, true)) {
            $problem = $diseasePreset;
        }
    }


    /* ============================================================
       OLD PATIENT SELECTED PAST PROBLEM = LOCK DURATION
       ============================================================ */

    if (
        $type === 'old'
        &&
        $patientId > 0
        &&
        $problemChoice !== ''
        &&
        $problemChoice !== '__new__'
        &&
        in_array($problemChoice, $pastProblems, true)
    ) {

        $selectedPastProblemDuration = adc_find_patient_problem_duration(
            $conn,
            $patientId,
            $problemChoice
        );

        if ($selectedPastProblemDuration['duration'] !== '') {
            $duration = $selectedPastProblemDuration['duration'];
            $durationUnit = $selectedPastProblemDuration['unit'];
        }
    }


    /* ============================================================
       PREVENT DUPLICATE PENDING APPOINTMENT
       ============================================================ */

    if (
        $error === ''
        &&
        $type === 'old'
        &&
        $patientId > 0
    ) {

        $pending =
            adc_pending_appointment(
                $conn,
                $patientId
            );

        if ($pending) {

            $pendingDate =
                $pending['date']
                ?
                date(
                    'd M Y',
                    strtotime($pending['date'])
                )
                :
                '';

            $pendingTime =
                $pending['time']
                ?
                date(
                    'g:i A',
                    strtotime($pending['time'])
                )
                :
                '';

            $error =
                'This patient already has a pending appointment'
                .
                ($pendingDate ? ' on ' . $pendingDate : '')
                .
                ($pendingTime ? ' at ' . $pendingTime : '')
                .
                '. Please wait for the existing appointment to be processed.';
        }
    }


    /* ============================================================
       DATE VALIDATION
       ============================================================ */

    $dateObject =
        DateTime::createFromFormat(
            'Y-m-d',
            $date
        );


    $validDate = (

        $dateObject

        &&

        $dateObject->format(
            'Y-m-d'
        )
        ===
        $date
    );


    /* ============================================================
       HOLIDAY
       ============================================================ */

    $holiday =

        $validDate

        ?

        adc_get_holiday(
            $conn,
            $date,
            $builtInHolidays
        )

        :

        null;


    /* ============================================================
       CLINIC SCHEDULE
       ============================================================ */

    $schedule =
        adc_schedule(
            $clinic,
            $date
        );


    /* ============================================================
       VALIDATION
       ============================================================ */

    if (
        !in_array(
            $type,
            ['new', 'old'],
            true
        )
    ) {

        $error =
            'Please select New Patient or Old Patient first.';

        $step = 1;
    }


    elseif (
        !adc_valid_phone(
            $phone
        )
    ) {

        $error =
            'Please enter a valid 10-digit mobile number.';

        $step = 2;
    }


    elseif (
        $type === 'old'
        &&
        isset($problemChoice)
        &&
        $problemChoice === ''
    ) {

        $error =
            'Please select one past disease / problem or choose Add New Problem.';
    }


    elseif (
        ($type === 'new' || ($type === 'old' && ($problemChoice ?? '') === '__new__'))
        &&
        empty($problemChoices)
        &&
        $diseasePreset === ''
    ) {

        $error =
            'Please choose a disease / problem button or select Other and enter the problem.';
    }


    elseif (
        $diseasePreset === '__other__'
        &&
        $problem === ''
    ) {

        $error =
            'Please enter the other disease / problem.';
    }


    elseif (
        $problem === ''
    ) {

        $error =
            'Please enter the disease / problem name.';
    }


    elseif (
        $duration === ''

        ||

        !ctype_digit(
            $duration
        )

        ||

        (int)$duration < 1
    ) {

        $error =
            'Please enter how long the patient has had the problem.';
    }


    elseif (
        !in_array(
            $durationUnit,
            [
                'Days',
                'Weeks',
                'Months',
                'Years'
            ],
            true
        )
    ) {

        $error =
            'Please select the problem duration unit.';
    }


    elseif (
        $address === ''
    ) {

        $error =
            'Please enter the patient address.';
    }


    elseif (
        $district === ''
    ) {

        $error =
            'Please enter the district.';
    }


    elseif (
        !$validDate

        ||

        $date <
        date('Y-m-d')
    ) {

        $error =
            'Please choose a valid future appointment date.';
    }


    elseif (
        !in_array(
            $clinic,
            $clinics,
            true
        )
    ) {

        $error =
            'Please select a clinic.';
    }


    elseif (
        $holiday
    ) {

        $error =
            'The clinic is closed on '
            .
            $holiday
            .
            '. Please select another date.';
    }


    elseif (
        !$schedule['open']
    ) {

        $error =
            $schedule['reason'];
    }


    else {

        $allowedTimes =
            array_column(
                $schedule['slots'],
                'value'
            );


        if (
            !in_array(
                $time,
                $allowedTimes,
                true
            )
        ) {

            $error =
                'Please select an available 10-minute time slot.';
        }
    }


    /* ============================================================
       CHECK IF SLOT IS ALREADY BOOKED
       ============================================================ */

    if (
        $error === ''
    ) {

        $stmt =
            $conn->prepare(
"
SELECT id

FROM appointments

WHERE date = ?

AND time = ?

AND clinic = ?

AND status IN
(
    'Pending',
    'Approved',
    'Completed'
)

LIMIT 1
"
            );


        if ($stmt) {

            $stmt->bind_param(
                'sss',
                $date,
                $time,
                $clinic
            );


            $stmt->execute();


            $result =
                $stmt->get_result();


            $busy =
                $result
                &&
                $result->num_rows > 0;


            $stmt->close();


            if ($busy) {

                $error =
                    'This time slot has already been booked. Please choose another slot.';
            }
        }
    }


    /* ============================================================
       CREATE / UPDATE PATIENT
       ============================================================ */

    if (
        $error === ''
    ) {


        /* ========================================================
           OLD PATIENT
           ======================================================== */

        if (
            $type === 'old'
        ) {

            if (
                $patientId <= 0
            ) {

                $matchedPatient =
                    adc_find_patient_by_id(
                        $conn,
                        $patientId
                    );


                if (
                    $matchedPatient
                ) {

                    $patientId =
                        (int)$matchedPatient['id'];
                }
            }


            if (
                $patientId <= 0
            ) {

                $error =
                    'Old patient record could not be found. Please go back and verify the name and mobile number.';
            }


            else {

                /*
                   Old patient master information is locked.
                   Address, district, age and gender are read from
                   the patient record and are never updated here.
                */
                $lockedPatient = adc_find_patient_by_id(
                    $conn,
                    $patientId
                );

                if (!$lockedPatient) {
                    $error = 'The old patient record could not be loaded.';
                } else {
                    $name = (string)($lockedPatient['name'] ?? $name);
                    $phone = (string)($lockedPatient['phone'] ?? $phone);
                    $address = (string)($lockedPatient['address'] ?? '');
                    $district = (string)($lockedPatient['district'] ?? '');
                    $matchedPatient = $lockedPatient;
                }
            }
        }


        /* ========================================================
           NEW PATIENT
           ======================================================== */

        else {

            $duplicatePatient = adc_find_duplicate_patient(
                $conn,
                $phone,
                $name
            );

            if ($duplicatePatient) {

                $error =
                    'A patient with the same name and mobile number already exists. Duplicate patient profiles are not allowed.';

            }

            if ($error === '') {

                if (
                    function_exists(
                        'next_code'
                    )
                ) {

                    $code =
                        next_code(
                            'patients',
                            'code',
                            1001
                        );
                }

                else {

                    $code = 1001;
                }


            /*
               A mobile number is allowed to belong to more
               than one patient profile.
            */

            $stmt =
                $conn->prepare(
"
INSERT INTO patients
(
    code,
    name,
    phone,
    address,
    district
)

VALUES
(
    ?,
    ?,
    ?,
    ?,
    ?
)
"
                );


            if (!$stmt) {

                $error =
                    'Unable to create the patient record. Database error: '
                    .
                    $conn->error;
            }

            else {

                $stmt->bind_param(
                    'issss',
                    $code,
                    $name,
                    $phone,
                    $address,
                    $district
                );


                if (!$stmt->execute()) {

                    $error =
                        'Unable to create the patient record. Database error: '
                        .
                        $stmt->error;
                }

                else {

                    $patientId =
                        (int)$conn->insert_id;

                    $patientCode =
                        (string)$code;
                }

                    $stmt->close();
                }
            }
        }
    }


    /* ============================================================
       INSERT APPOINTMENT
       ============================================================ */

    if (
        $error === ''

        &&

        $patientId > 0
    ) {

        $emptyEmail = '';


        /*
           We save both:

           disease = problem
           problem = problem

           This keeps compatibility with your existing
           database structure.
        */

        $stmt =
            $conn->prepare(
"
INSERT INTO appointments
(

    patient_id,

    name,

    email,

    phone,

    clinic,

    disease,

    service,

    date,

    status,

    time,

    patient_type,

    problem,

    problem_duration,

    problem_duration_unit,

    address,

    district,

    booked_at

)

VALUES
(

    ?,

    ?,

    ?,

    ?,

    ?,

    ?,

    ?,

    ?,

    'Pending',

    ?,

    ?,

    ?,

    ?,

    ?,

    ?,

    ?,

    NOW()
)
"
            );


        if (!$stmt) {

            $error =
                'Unable to book the appointment. Database error: '
                .
                $conn->error;
        }


        else {

            /*
               15 parameters:

               1  patient_id  = i

               2  name        = s
               3  email       = s
               4  phone       = s
               5  clinic      = s
               6  disease     = s
               7  service     = s
               8  date        = s
               9  time        = s
               10 patient_type= s
               11 problem     = s
               12 duration    = s
               13 unit        = s
               14 address     = s
               15 district    = s

               Therefore:

               i + 14 strings
            */

            $stmt->bind_param(

                'issssssssssssss',

                $patientId,

                $name,

                $emptyEmail,

                $phone,

                $clinic,

                $problem,

                $service,

                $date,

                $time,

                $type,

                $problem,

                $duration,

                $durationUnit,

                $address,

                $district
            );


            if (
                $stmt->execute()
            ) {

                $appointmentId =
                    (int)$conn->insert_id;


                $stmt->close();


                unset(

                    $_SESSION[
                        'appointment_name'
                    ],

                    $_SESSION[
                        'appointment_phone'
                    ],

                    $_SESSION[
                        'appointment_type'
                    ],

                    $_SESSION[
                        'appointment_patient_id'
                    ]

                );


                $base =
                    defined('BASE_URL')
                    ?
                    BASE_URL
                    :
                    '';


                $url =
                    $base
                    .
                    'appointment.php?success=1'
                    .
                    '&ref='
                    .
                    $appointmentId
                    .
                    '&reg='
                    .
                    urlencode(
                        $patientCode
                    );


                if (
                    function_exists(
                        'redirect'
                    )
                ) {

                    redirect(
                        $url
                    );

                    exit;
                }


                header(
                    'Location: '
                    .
                    $url
                );

                exit;
            }


            else {

                $error =
                    'Unable to book the appointment. Database error: '
                    .
                    $stmt->error;


                $stmt->close();
            }
        }
    }
}


/* ================================================================
   LOAD OLD PATIENT FOR STEP 3 DISPLAY
   ================================================================ */

if (
    $step === 3
    &&
    $type === 'old'
    &&
    $patientId > 0
    &&
    !$matchedPatient
) {

    $stmt =
        $conn->prepare(
"
SELECT

    id,
    code,
    name,
    phone,
    age,
    gender,
    address,
    district

FROM patients

WHERE id = ?

LIMIT 1
"
        );


    if ($stmt) {

        $stmt->bind_param(
            'i',
            $patientId
        );


        $stmt->execute();


        $result =
            $stmt->get_result();


        $matchedPatient =
            $result
            ?
            $result->fetch_assoc()
            :
            null;


        $stmt->close();
    }
}


if ($matchedPatient) {

    if ($type === 'old' && $patientId > 0) {
        $pastProblems = adc_find_patient_problems(
            $conn,
            $patientId
        );

        foreach ($pastProblems as $pastProblemItem) {
            $pastProblemDetails[$pastProblemItem] =
                adc_find_patient_problem_duration(
                    $conn,
                    $patientId,
                    $pastProblemItem
                );
        }
    }

    $patientCode =
        (string)(
            $matchedPatient['code']
            ??
            $patientCode
        );


    $name =
        (string)(
            $matchedPatient['name']
            ??
            $name
        );


    $phone =
        (string)(
            $matchedPatient['phone']
            ??
            $phone
        );


    if ($address === '') {

        $address =
            (string)(
                $matchedPatient['address']
                ??
                ''
            );
    }


    if ($district === '') {

        $district =
            (string)(
                $matchedPatient['district']
                ??
                ''
            );
    }
}

?>

<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
Book Appointment | AmarDayal Clinic
</title>

<link
    rel="stylesheet"
    href="Styles/style.css"
>


<style>

/* ================================================================
   APPOINTMENT PAGE
   ================================================================ */

:root {

    --blue: #2563eb;

    --blue-dark: #1d4ed8;

    --teal: #0ea5a4;

    --ink: #092d52;

    --muted: #64748b;

    --line: #dbe5f0;

    --background: #f4f8fc;

    --white: #ffffff;

    --danger: #dc2626;

    --success: #059669;
}


* {
    box-sizing: border-box;
}


.booking-page {

    min-height:
        calc(100vh - 80px);

    background:

        radial-gradient(
            circle at top left,
            #eef5ff 0,
            #f8fbff 45%,
            #ecfbf8 100%
        );

    padding:
        48px 18px 80px;
}


.booking-wrap {

    max-width:
        1080px;

    margin:
        auto;
}


/* ================================================================
   HEADING
   ================================================================ */

.heading {

    text-align:
        center;

    margin:
        0 auto 28px;
}


.heading .kicker {

    font-size:
        12px;

    font-weight:
        900;

    letter-spacing:
        2.5px;

    color:
        var(--teal);
}


.heading h1 {

    margin:
        8px 0 0;

    color:
        var(--ink);

    font-size:
        40px;
}


/*
   We intentionally hide the old explanatory sentence.
*/

.heading p {

    display:
        none;
}


/* ================================================================
   MAIN CARD
   ================================================================ */

.flow {

    background:
        #ffffff;

    border:
        1px solid #e6edf5;

    border-radius:
        24px;

    box-shadow:
        0 20px 55px
        rgba(
            15,
            23,
            42,
            .09
        );

    overflow:
        hidden;
}


/* ================================================================
   CLICKABLE PROGRESS
   ================================================================ */

.progress {

    display:
        flex;

    align-items:
        center;

    padding:
        24px 28px;

    border-bottom:
        1px solid #edf2f7;
}


.pitem {

    display:
        flex;

    align-items:
        center;

    gap:
        10px;

    text-decoration:
        none;

    color:
        #94a3b8;

    min-width:
        0;
}


.pitem.disabled {

    pointer-events:
        none;
}


.pnum {

    width:
        42px;

    height:
        42px;

    border-radius:
        50%;

    display:
        grid;

    place-items:
        center;

    border:
        1px solid #d9e2ed;

    background:
        #f8fafc;

    font-weight:
        900;

    transition:
        .2s;
}


.pitem.active {

    color:
        var(--blue);
}


.pitem.active .pnum {

    background:
        var(--blue);

    border-color:
        var(--blue);

    color:
        #ffffff;

    box-shadow:
        0 8px 18px
        rgba(
            37,
            99,
            235,
            .25
        );
}


.pitem.current .pnum {

    background:
        linear-gradient(
            135deg,
            var(--blue),
            var(--teal)
        );

    border-color:
        transparent;
}


.ptext strong,
.ptext small {

    display:
        block;
}


.ptext strong {

    font-size:
        13px;
}


.ptext small {

    font-size:
        10px;

    margin-top:
        2px;
}


.pline {

    height:
        2px;

    background:
        #e1e9f2;

    flex:
        1;

    margin:
        0 16px;
}


.pline.active {

    background:
        linear-gradient(
            90deg,
            var(--blue),
            var(--teal)
        );
}


/* ================================================================
   CARD CONTENT
   ================================================================ */

.card {

    padding:
        38px 42px;
}


.section-head {

    display:
        flex;

    align-items:
        center;

    gap:
        15px;

    margin-bottom:
        26px;
}


.badge {

    width:
        40px;

    height:
        40px;

    border-radius:
        13px;

    background:
        #eaf2ff;

    color:
        var(--blue);

    display:
        grid;

    place-items:
        center;

    font-weight:
        900;
}


.section-head h2 {

    margin:
        0;

    color:
        var(--ink);

    font-size:
        25px;
}


.section-head p {

    margin:
        3px 0 0;

    color:
        var(--muted);

    font-size:
        13px;
}


/* ================================================================
   ALERT
   ================================================================ */

.alert {

    padding:
        13px 15px;

    border-radius:
        12px;

    margin-bottom:
        20px;

    font-size:
        13px;
}


.alert.error {

    background:
        #fff1f2;

    border:
        1px solid #fecdd3;

    color:
        #b91c1c;
}


.alert.info {

    background:
        #eff6ff;

    border:
        1px solid #bfdbfe;

    color:
        #1d4ed8;
}


/* ================================================================
   NEW / OLD PATIENT
   ================================================================ */

.type-grid {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        16px;
}


.type-option {

    position:
        relative;
}


.type-option input {

    position:
        absolute;

    opacity:
        0;
}


.type-label {

    display:
        block;

    border:
        1.5px solid
        var(--line);

    border-radius:
        17px;

    padding:
        24px;

    cursor:
        pointer;

    transition:
        .2s;

    background:
        #ffffff;
}


.type-label:hover {

    transform:
        translateY(-2px);

    border-color:
        #93c5fd;

    box-shadow:
        0 10px 25px
        rgba(
            37,
            99,
            235,
            .08
        );
}


.type-option
input:checked
+
.type-label {

    border-color:
        var(--blue);

    background:
        #eff6ff;

    box-shadow:
        0 12px 28px
        rgba(
            37,
            99,
            235,
            .12
        );
}


.type-icon {

    font-size:
        25px;
}


.type-label strong {

    display:
        block;

    color:
        var(--ink);

    margin-top:
        10px;

    font-size:
        17px;
}


.type-label span {

    display:
        block;

    color:
        var(--muted);

    font-size:
        12px;

    margin-top:
        5px;
}


/* ================================================================
   FORM
   ================================================================ */

.form-grid {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        18px;
}


.mobile-lookup {

    width: 100%;

    max-width: 560px;

    margin-left: auto;

    margin-right: auto;
}

.mobile-lookup input {

    height: 54px;

}

.mobile-lookup #livePatientResults {

    width: 100%;

}

.field.full {

    grid-column:
        1 / -1;
}


.field label {

    display:
        block;

    font-size:
        12px;

    font-weight:
        800;

    color:
        #17365d;

    margin:
        0 0 8px;
}


.field input,
.field select,
.field textarea {

    width:
        100%;

    border:
        1px solid #d6e1ed;

    border-radius:
        12px;

    padding:
        14px 15px;

    background:
        #ffffff;

    color:
        #16385f;

    font:
        inherit;

    outline:
        none;
}


.field input:focus,
.field select:focus,
.field textarea:focus {

    border-color:
        var(--blue);

    box-shadow:
        0 0 0 4px
        rgba(
            37,
            99,
            235,
            .09
        );
}


.field textarea {

    min-height:
        95px;

    resize:
        vertical;
}


.field input[readonly],
.field textarea[readonly],
.field input:disabled,
.field textarea:disabled,
.field select:disabled {

    background: #f1f5f9;
    color: #475569;
    cursor: not-allowed;
}

.locked-note {

    margin-top: 7px;
    color: #64748b;
    font-size: 11px;
}

.problem-choice-box {

    margin-bottom: 16px;
    padding: 16px;
    border: 1px solid #dbe5f1;
    border-radius: 14px;
    background: #f8fbff;
}

.problem-choice-box label {

    display: block;
    font-size: 12px;
    font-weight: 800;
    color: #17365d;
    margin-bottom: 8px;
}

.problem-choice-box select {

    width: 100%;
    border: 1px solid #d6e1ed;
    border-radius: 12px;
    padding: 13px 14px;
    background: #fff;
    color: #16385f;
    font: inherit;
    outline: none;
}

.problem-choice-box select:focus {

    border-color: var(--blue);
    box-shadow: 0 0 0 4px rgba(37,99,235,.09);
}

.new-disease-panel {
    margin-bottom: 14px;
    padding: 14px;
    border: 1px solid #dbe5f1;
    border-radius: 14px;
    background: #f8fbff;
}

.new-disease-title {
    margin-bottom: 10px;
    color: #17365d;
    font-size: 12px;
    font-weight: 900;
}

.disease-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
}

.disease-btn {
    border: 1px solid #cbd9e8;
    border-radius: 999px;
    padding: 10px 14px;
    background: #ffffff;
    color: #17365d;
    font: inherit;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    transition: .16s ease;
}

.disease-btn:hover {
    border-color: var(--blue);
    color: var(--blue);
    transform: translateY(-1px);
}

.disease-btn.selected {
    background: var(--blue);
    border-color: var(--blue);
    color: #ffffff;
    box-shadow: 0 7px 16px rgba(37,99,235,.18);
}

.other-problem-wrap {
    margin-top: 12px;
}

.slot.available {
    background: #e9f9ef;
    border-color: #34c759;
    color: #167a3a;
}

.slot.available:hover {
    background: #d9f5e4;
    border-color: #1ea84a;
    color: #126b31;
}

.slot.booked {
    background: #ffe8e8;
    border-color: #ef4444;
    color: #b42318;
    cursor: not-allowed;
    opacity: .95;
}

.slot.booked:hover {
    transform: none;
    border-color: #ef4444;
}

.slot.booked .slot-status {
    display: block;
    margin-top: 4px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .04em;
}

.slot .slot-status {
    display: block;
    margin-top: 4px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .04em;
}


/* ================================================================
   PATIENT SUMMARY
   ================================================================ */

.patient-summary {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        12px;

    background:
        #f8fafc;

    border:
        1px solid #e7edf5;

    border-radius:
        14px;

    padding:
        15px;

    margin-bottom:
        22px;
}


.summary-item span {

    display:
        block;

    color:
        #94a3b8;

    font-size:
        10px;

    text-transform:
        uppercase;

    font-weight:
        800;
}


.summary-item strong {

    display:
        block;

    color:
        var(--ink);

    margin-top:
        4px;
}


/* ================================================================
   CLINIC TABS
   ================================================================ */

.clinic-tabs {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        14px;
}


.clinic-tab {

    position:
        relative;
}


.clinic-tab input {

    position:
        absolute;

    opacity:
        0;
}


.clinic-label {

    display:
        block;

    border:
        1.5px solid
        var(--line);

    border-radius:
        15px;

    padding:
        18px;

    cursor:
        pointer;
}


.clinic-tab
input:checked
+
.clinic-label {

    border-color:
        var(--teal);

    background:
        #ecfdf5;

    box-shadow:
        0 8px 20px
        rgba(
            14,
            165,
            164,
            .10
        );
}


.clinic-label strong {

    display:
        block;

    color:
        var(--ink);
}


.clinic-label span {

    display:
        block;

    color:
        var(--muted);

    font-size:
        12px;

    margin-top:
        5px;
}


/* ================================================================
   SCHEDULE
   ================================================================ */

.schedule {

    margin-top:
        20px;
}


.schedule-title {

    font-size:
        13px;

    font-weight:
        900;

    color:
        #17365d;

    margin-bottom:
        10px;
}


.schedule-status {

    font-size:
        12px;

    margin:
        8px 0 14px;

    color:
        var(--muted);
}


.schedule-status.closed {

    color:
        #dc2626;
}


.schedule-status.open {

    color:
        #059669;
}


/* ================================================================
   TIME BUTTONS
   ================================================================ */

.slot-grid {

    display:
        grid;

    grid-template-columns:
        repeat(
            5,
            1fr
        );

    gap:
        10px;
}


.slot {

    border:
        1px solid #d6e1ed;

    background:
        #ffffff;

    border-radius:
        12px;

    padding:
        14px 8px;

    text-align:
        center;

    color:
        #17365d;

    font-weight:
        800;

    cursor:
        pointer;

    transition:
        .18s;
}


.slot:hover {

    border-color:
        var(--blue);

    transform:
        translateY(-1px);
}


.slot.selected {

    background:
        var(--blue);

    border-color:
        var(--blue);

    color:
        #ffffff;

    box-shadow:
        0 8px 18px
        rgba(
            37,
            99,
            235,
            .20
        );
}


/* ================================================================
   BUTTONS
   ================================================================ */

.actions {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap:
        12px;

    margin-top:
        28px;

    padding-top:
        22px;

    border-top:
        1px solid #edf2f7;
}


.btn,
.text-btn {

    border:
        0;

    border-radius:
        12px;

    padding:
        13px 20px;

    font-weight:
        900;

    text-decoration:
        none;

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    cursor:
        pointer;
}


.btn {

    background:
        linear-gradient(
            135deg,
            var(--blue),
            var(--blue-dark)
        );

    color:
        #ffffff;

    box-shadow:
        0 10px 22px
        rgba(
            37,
            99,
            235,
            .22
        );
}


.btn:disabled {

    opacity:
        .5;

    cursor:
        not-allowed;
}


.text-btn {

    background:
        #f1f5f9;

    color:
        #34516f;
}


/* ================================================================
   PRIVACY
   ================================================================ */

.privacy {

    font-size:
        11px;

    color:
        #94a3b8;

    text-align:
        center;

    margin-top:
        16px;
}


/* ================================================================
   SUCCESS
   ================================================================ */

.success {

    text-align:
        center;

    padding:
        55px 30px;
}


.success-icon {

    width:
        68px;

    height:
        68px;

    border-radius:
        50%;

    margin:
        0 auto 16px;

    background:
        #dcfce7;

    color:
        #059669;

    display:
        grid;

    place-items:
        center;

    font-size:
        35px;

    font-weight:
        900;
}


.success h1 {

    color:
        var(--ink);
}


.success p {

    color:
        var(--muted);

    line-height:
        1.7;
}


.success-details {

    display:
        grid;

    grid-template-columns:
        repeat(
            3,
            1fr
        );

    gap:
        12px;

    margin:
        24px 0;
}


.success-details div {

    background:
        #f8fafc;

    border-radius:
        12px;

    padding:
        15px;
}


.success-details span {

    display:
        block;

    color:
        #94a3b8;

    font-size:
        10px;

    text-transform:
        uppercase;
}


.success-details strong {

    display:
        block;

    color:
        var(--ink);

    margin-top:
        5px;
}


/* ================================================================
   MOBILE
   ================================================================ */

@media (
    max-width: 800px
) {

    .booking-page {

        padding:
            30px 10px 55px;
    }


    .heading h1 {

        font-size:
            31px;
    }


    .progress {

        padding:
            18px 12px;
    }


    .ptext {

        display:
            none;
    }


    .pline {

        margin:
            0 7px;
    }


    .pnum {

        width:
            38px;

        height:
            38px;
    }


    .card {

        padding:
            26px 20px;
    }


    .type-grid,
    .clinic-tabs,
    .form-grid,
    .patient-summary,
    .success-details {

        grid-template-columns:
            1fr;
    }


    .slot-grid {

        grid-template-columns:
            repeat(
                3,
                1fr
            );
    }


    .actions {

        flex-direction:
            column-reverse;

        align-items:
            stretch;
    }


    .btn,
    .text-btn {

        width:
            100%;
    }
}


@media (
    max-width: 480px
) {

    .slot-grid {

        grid-template-columns:
            repeat(
                2,
                1fr
            );
    }
}

</style>

</head>


<body>


<?php

if (
    function_exists('nav')
) {

    nav();
}

?>


<main class="booking-page">

<div class="booking-wrap">


<?php if ($step !== 4): ?>


<!-- ============================================================
     PAGE TITLE
     ============================================================ -->

<div class="heading">

    <h1>
        Book an Appointment
    </h1>

</div>


<div class="flow">


<!-- ============================================================
     CLICKABLE PROGRESS BAR
     ============================================================ -->

<div class="progress">

    <a
        class="
            pitem
            active
            <?= $step === 1 ? 'current' : '' ?>
        "
        href="appointment.php?step=1"
    >

        <span class="pnum">1</span>

        <span class="ptext">
            <strong>Patient</strong>
            <small>Mobile & profile</small>
        </span>

    </a>

    <div
        class="
            pline
            <?= $step >= 2 ? 'active' : '' ?>
        "
    ></div>

    <?php if ($type === 'new'): ?>

        <a
            class="
                pitem
                active
                <?= $step === 2 ? 'current' : '' ?>
            "
            href="appointment.php?step=2"
        >

    <?php else: ?>

        <span class="pitem disabled">

    <?php endif; ?>

        <span class="pnum">2</span>

        <span class="ptext">
            <strong>New Patient</strong>
            <small>Name & phone</small>
        </span>

    <?php if ($type === 'new'): ?>

        </a>

    <?php else: ?>

        </span>

    <?php endif; ?>

    <div
        class="
            pline
            <?= $step >= 3 ? 'active' : '' ?>
        "
    ></div>

    <?php if ($type && ($patientId > 0 || $type === 'new')): ?>

        <a
            class="
                pitem
                active
                <?= $step === 3 ? 'current' : '' ?>
            "
            href="appointment.php?step=3"
        >

    <?php else: ?>

        <span class="pitem disabled">

    <?php endif; ?>

        <span class="pnum">3</span>

        <span class="ptext">
            <strong>Appointment</strong>
            <small>Clinic & slot</small>
        </span>

    <?php if ($type && ($patientId > 0 || $type === 'new')): ?>

        </a>

    <?php else: ?>

        </span>

    <?php endif; ?>

</div>


<div class="card">


<?php if ($error && $step !== 2 && $step !== 3): ?>

<div class="alert error">

    <?= adc_e($error) ?>

</div>

<?php endif; ?>


<!-- ============================================================
     STEP 1
     ============================================================ -->

<?php if ($step === 1): ?>


<form
    method="post"
    action="appointment.php"
>

    <input
        type="hidden"
        name="step"
        value="1"
    >

    <input
        type="hidden"
        name="lookup_action"
        value="lookup"
    >

    <div class="field full mobile-lookup">

        <label>
            Mobile Number *
        </label>

        <input
            id="patientPhone"
            name="phone"
            value="<?= adc_e($phone) ?>"
            inputmode="numeric"
            maxlength="10"
            pattern="[0-9]{10}"
            placeholder="Enter 10-digit mobile number"
            required
            autofocus
        >

        <div
            id="livePatientResults"
            style="margin-top:12px;"
        ></div>

    </div>



    </div>

</form>


<?php if ($patientsByPhone): ?>

<div
    style="
        margin-top:28px;
        padding-top:24px;
        border-top:1px solid #e2e8f0;
    "
>

    <div style="margin-bottom:16px;">
        <h3 style="margin:0 0 6px;color:#12345b;">
            Patients registered with this number
        </h3>
    
    </div>

    <div style="display:grid;gap:12px;">

        <?php foreach ($patientsByPhone as $phonePatient): ?>

            <form
                method="post"
                action="appointment.php"
                style="margin:0;"
            >

                <input
                    type="hidden"
                    name="step"
                    value="1"
                >

                <input
                    type="hidden"
                    name="lookup_action"
                    value="select_patient"
                >

                <input
                    type="hidden"
                    name="patient_id"
                    value="<?= (int)$phonePatient['id'] ?>"
                >

                <input
                    type="hidden"
                    name="phone"
                    value="<?= adc_e($phone) ?>"
                >

                <button
                    type="submit"
                    style="
                        width:100%;
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        gap:18px;
                        padding:17px 20px;
                        border:1px solid #dbe5f1;
                        border-radius:14px;
                        background:#f8fbff;
                        color:#12345b;
                        cursor:pointer;
                        text-align:left;
                    "
                >

                    <span>
                        <strong style="display:block;font-size:16px;">
                            <?= adc_e($phonePatient['name']) ?>
                        </strong>

                        <small style="display:block;margin-top:5px;color:#64748b;">
                            Patient ID: <?= adc_e($phonePatient['code']) ?>
                            <?php if (!empty($phonePatient['age'])): ?>
                                · Age <?= adc_e($phonePatient['age']) ?>
                            <?php endif; ?>
                            <?php if (!empty($phonePatient['gender'])): ?>
                                · <?= adc_e($phonePatient['gender']) ?>
                            <?php endif; ?>
                        </small>
                    </span>

                    <span style="font-weight:700;color:#2563eb;white-space:nowrap;">
                        Select →
                    </span>

                </button>

            </form>

        <?php endforeach; ?>

    </div>

    <form
        method="post"
        action="appointment.php"
        style="margin-top:14px;"
    >

        <input
            type="hidden"
            name="step"
            value="1"
        >

        <input
            type="hidden"
            name="lookup_action"
            value="add_new"
        >

        <input
            type="hidden"
            name="phone"
            value="<?= adc_e($phone) ?>"
        >

        <button
            class="text-btn"
            type="submit"
            style="width:100%;"
        >
            + Add New Patient With This Same Number
        </button>

    </form>

</div>

<?php endif; ?>


<!-- ============================================================
     STEP 2
     ============================================================ -->

<?php elseif ($step === 2): ?>
<div class="alert info">

    Mobile Number:

    <strong>
        <?= adc_e($phone) ?>
    </strong>

</div>


<form
    method="post"
    action="appointment.php"
>

    <input
        type="hidden"
        name="step"
        value="2"
    >

    <input
        type="hidden"
        name="patient_type"
        value="new"
    >

    <input
        type="hidden"
        name="phone"
        value="<?= adc_e($phone) ?>"
    >

    <div class="form-grid">
<div class="field full">

    <label>
        Patient Full Name *
    </label>

    <input
        name="name"
        value="<?= adc_e($name) ?>"
        placeholder="Enter patient's full name"
        required
        autofocus
    >

    <?php if ($error && $step === 2): ?>

        <div
            class="alert error"
            style="margin-top:10px;"
        >
            <?= adc_e($error) ?>
        </div>

    <?php endif; ?>

</div>
    </div>

    <div class="actions">

        <a
            class="text-btn"
            href="appointment.php?step=1"
        >
            ← Back
        </a>

        <button
            class="btn"
            type="submit"
        >
            Continue →
        </button>

    </div>

</form>


<!-- ============================================================
     STEP 3
     ============================================================ -->

<?php elseif ($step === 3): ?>


<div class="section-head">

    <div class="badge">
        03
    </div>

    <div>

        <h2>
            Appointment Details
        </h2>

        <p>
            Enter the problem, choose the clinic,
            date and available 10-minute start time.
        </p>

    </div>

</div>


<!-- PATIENT SUMMARY -->

<div class="patient-summary">


    <div class="summary-item">

        <span>
            Patient
        </span>

        <strong>
            <?= adc_e($name) ?>
        </strong>

    </div>


    <div class="summary-item">

        <span>
            Mobile
        </span>

        <strong>
            <?= adc_e($phone) ?>
        </strong>

    </div>


</div>


<form
    id="appointmentForm"
    method="post"
    action="appointment.php"
>


<input
    type="hidden"
    name="step"
    value="3"
>


<input
    type="hidden"
    name="patient_type"
    value="<?= adc_e($type) ?>"
>


<input
    type="hidden"
    name="name"
    value="<?= adc_e($name) ?>"
>


<input
    type="hidden"
    name="phone"
    value="<?= adc_e($phone) ?>"
>


<div class="form-grid">


    <!-- DISEASE / PROBLEM -->

    <div class="field full">

        <label>
            Disease / Problem *
        </label>

        <?php if ($type === 'old'): ?>

            <div class="problem-choice-box">

                <label for="pastProblemChoice">
                    Past diseases / problems
                </label>

                <select
                    id="pastProblemChoice"
                    name="past_problem_choice"
                    required
                >

                    <option value="">
                        Select one past disease / problem
                    </option>

                    <?php foreach ($pastProblems as $pastProblem): ?>

                        <option
                            value="<?= adc_e($pastProblem) ?>"
                            <?= ($problemChoice === $pastProblem || ($problemChoice === '' && $problem === $pastProblem)) ? 'selected' : '' ?>
                        >
                            <?= adc_e($pastProblem) ?>
                        </option>

                    <?php endforeach; ?>

                    <option
                        value="__new__"
                        <?= $problemChoice === '__new__' ? 'selected' : '' ?>
                    >
                        + Add New Problem / Disease
                    </option>

                </select>

                <?php if (!$pastProblems): ?>
                    <div class="locked-note">
                        No previous disease/problem was found for this patient. Choose “Add New Problem / Disease” below.
                    </div>
                <?php endif; ?>

            </div>

        <?php endif; ?>

        <div
            id="newDiseasePanel"
            class="new-disease-panel"
            style="<?= ($type === 'new' || $problemChoice === '__new__') ? '' : 'display:none;' ?>"
        >

            <div class="new-disease-title">
                Choose a problem
            </div>

            <div class="disease-buttons">

                <?php foreach ($standardDiseases as $diseaseName): ?>

                    <button
                        type="button"
                        class="disease-btn <?= in_array($diseaseName, $problemChoices, true) ? 'selected' : '' ?>"
                        data-disease="<?= adc_e($diseaseName) ?>"
                    >
                        <?= adc_e($diseaseName) ?>
                    </button>

                <?php endforeach; ?>

                <button
                    type="button"
                    class="disease-btn other-disease-btn <?= $diseasePreset === '__other__' ? 'selected' : '' ?>"
                    data-disease="__other__"
                >
                    Other
                </button>

            </div>

            <div id="problemChoicesInputs">
                <?php foreach ($problemChoices as $choice): ?>
                    <input
                        type="hidden"
                        name="problem_choices[]"
                        value="<?= adc_e($choice) ?>"
                    >
                <?php endforeach; ?>
            </div>

            <input
                type="hidden"
                id="diseasePreset"
                name="disease_preset"
                value="<?= adc_e($diseasePreset) ?>"
            >

            <div
                id="otherProblemWrap"
                class="other-problem-wrap"
                style="<?= $diseasePreset === '__other__' ? '' : 'display:none;' ?>"
            >
                <label for="problem">
                    Other problem / disease
                </label>
            </div>

        </div>

        <textarea
            id="problem"
            name="problem"
            placeholder="Type the other problem / disease"
            <?= ($type === 'old' && $problemChoice !== '__new__' && $problemChoice !== '') || (($type === 'new' || $problemChoice === '__new__') && $diseasePreset !== '__other__') ? 'readonly' : '' ?>
            required
        ><?= adc_e($problem) ?></textarea>

        <?php if ($type === 'old'): ?>
            <div class="locked-note" id="problemNote">
                Select one past problem above, or choose “Add New Problem / Disease” to use the disease buttons.
            </div>
        <?php endif; ?>

    </div>


    <!-- DURATION -->

    <div class="field">

        <label>
            How long has the patient had this problem? *
        </label>

        <input
            id="problemDuration"
            type="number"
            min="1"
            name="problem_duration"
            value="<?= adc_e($duration) ?>"
            placeholder="Example: 3"
            <?= $type === 'old' && $problemChoice !== '__new__' && $problemChoice !== '' ? 'readonly' : '' ?>
            required
        >

    </div>


     <!-- DURATION UNIT -->

    <div class="field">

        <label>
            Duration Unit *
        </label>

        <select
            id="problemDurationUnit"
            name="problem_duration_unit"
            <?= $type === 'old' && $problemChoice !== '__new__' && $problemChoice !== '' ? 'disabled' : '' ?>
            required
        >

            <option
                value="Days"
                <?= $durationUnit === 'Days' ? 'selected' : '' ?>
            >
                Days
            </option>

            <option
                value="Weeks"
                <?= $durationUnit === 'Weeks' ? 'selected' : '' ?>
            >
                Weeks
            </option>

            <option
                value="Months"
                <?= $durationUnit === 'Months' ? 'selected' : '' ?>
            >
                Months
            </option>

            <option
                value="Years"
                <?= $durationUnit === 'Years' ? 'selected' : '' ?>
            >
                Years
            </option>

        </select>

        <?php if ($type === 'old'): ?>

            <input
                type="hidden"
                name="problem_duration_unit"
                value="<?= adc_e($durationUnit) ?>"
                id="problemDurationUnitHidden"
                <?= $problemChoice === '__new__' ? 'disabled' : '' ?>
            >

        <?php endif; ?>

    </div>


    <!-- =====================================================
         STEP 3 ERROR MESSAGE
         Appears after Duration Unit
         ===================================================== -->

    <?php if ($error && $step === 3): ?>

        <div
            class="alert error"
            style="
                grid-column: 1 / -1;
                margin: 0;
            "
        >
            <?= adc_e($error) ?>
        </div>

    <?php endif; ?>


    <!-- ADDRESS -->

    <div class="field full">

        <label>
            Address *
        </label>

        <textarea
            name="address"
            placeholder="Complete residential address"
            required
            <?= $type === 'old' ? 'readonly' : '' ?>
        ><?= adc_e($address) ?></textarea>

    </div>

<!-- AGE / GENDER -->

<?php if ($type === 'old' && $matchedPatient): ?>

    <!-- OLD PATIENT AGE -->
    <div class="field">

        <label>
            Age
        </label>

        <input
            type="text"
            value="<?= adc_e($matchedPatient['age'] ?? '') ?>"
            readonly
        >

    </div>


    <!-- OLD PATIENT GENDER -->
    <div class="field">

        <label>
            Gender
        </label>

        <input
            type="text"
            value="<?= adc_e($matchedPatient['gender'] ?? '') ?>"
            readonly
        >

    </div>


<?php elseif ($type === 'new'): ?>

    <!-- NEW PATIENT GENDER -->
    <div class="field">

        <label>
            Gender *
        </label>

        <select
            name="gender"
            required
        >

            <option value="">
                Select Gender
            </option>

            <option
                value="Male"
                <?= (($gender ?? '') === 'Male') ? 'selected' : '' ?>
            >
                Male
            </option>

            <option
                value="Female"
                <?= (($gender ?? '') === 'Female') ? 'selected' : '' ?>
            >
                Female
            </option>

            <option
                value="Other"
                <?= (($gender ?? '') === 'Other') ? 'selected' : '' ?>
            >
                Other
            </option>

        </select>

    </div>

<?php endif; ?>


    <!-- DISTRICT -->

    <div class="field">

        <label>
            District *
        </label>

        <input
            name="district"
            value="<?= adc_e($district) ?>"
            placeholder="Example: Mainpuri"
            required
            <?= $type === 'old' ? 'readonly' : '' ?>
        >

        <?php if ($type === 'old'): ?>
        <?php endif; ?>

    </div>


    <!-- ========================================================
         CLINIC
         ======================================================== -->

    <div class="field full">

        <label>
            Clinic *
        </label>


        <div class="clinic-tabs">


            <!-- SANKISA -->

            <div class="clinic-tab">

                <input

                    id="sankisa"

                    type="radio"

                    name="clinic"

                    value="Sankisa Clinic"

                    <?=

                    $clinic ===
                    'Sankisa Clinic'

                    ?
                    'checked'
                    :
                    ''

                    ?>

                    required

                >


                <label
                    class="clinic-label"
                    for="sankisa"
                >

                    <strong>
                        📍 Sankisa Clinic
                    </strong>

                    <span>
                        Every day except Tuesday
                        & holidays
                        •
                        09:30 AM – 02:30 PM
                    </span>

                </label>

            </div>


            <!-- MAINPURI -->

            <div class="clinic-tab">

                <input

                    id="mainpuri"

                    type="radio"

                    name="clinic"

                    value="Mainpuri Clinic"

                    <?=

                    $clinic ===
                    'Mainpuri Clinic'

                    ?
                    'checked'
                    :
                    ''

                    ?>

                >


                <label
                    class="clinic-label"
                    for="mainpuri"
                >

                    <strong>
                        📍 Mainpuri Clinic
                    </strong>

                    <span>
                        Wednesday & Saturday
                        •
                        04:00 PM – 06:30 PM
                    </span>

                </label>

            </div>


        </div>

    </div>


    <!-- ========================================================
         DATE
         ======================================================== -->

    <div class="field">

        <label>
            Appointment Date *
        </label>


        <input

            id="appointmentDate"

            type="date"

            name="date"

            value="<?= adc_e($date) ?>"

            min="<?= date('Y-m-d') ?>"

            required

        >

    </div>


    <!-- SELECTED TIME -->

    <div class="field">

        <label>
            Selected Time *
        </label>


        <input

            id="selectedTimeDisplay"

            type="text"

            value="<?=

                $time

                ?

                adc_e(
                    date(
                        'g:i A',
                        strtotime($time)
                    )
                )

                :

                'Select a time below'

            ?>"

            readonly

        >


        <input

            id="selectedTime"

            type="hidden"

            name="time"

            value="<?= adc_e($time) ?>"

        >

    </div>


    <!-- ========================================================
         TIME SLOTS
         ======================================================== -->

    <div
        class="
            field
            full
            schedule
        "
    >


        <div class="schedule-title">

            Available 10-Minute Time Slots *

        </div>


        <div
            id="scheduleStatus"
            class="schedule-status"
        >

            Select clinic and date
            to see available times.

        </div>


        <div
            id="slotGrid"
            class="slot-grid"
        ></div>


    </div>


</div>


<div class="privacy">

    🔒 Your information is kept private and secure.

</div>


<div class="actions">


    <a
        class="text-btn"
        href="appointment.php?step=2"
    >

        ← Back

    </a>


    <button

        id="bookBtn"

        class="btn"

        type="submit"

        disabled

    >

        Book Appointment ✓

    </button>


</div>


</form>


<?php endif; ?>


</div>

</div>


<?php else: ?>


<!-- ============================================================
     SUCCESS
     ============================================================ -->

<div class="flow">


<div class="success">


    <div class="success-icon">
        ✓
    </div>


    <div
        class="heading"
        style="margin-bottom:12px"
    >

        <div class="kicker">
            BOOKING RECEIVED
        </div>

    </div>


    <h1>
        Appointment Request Submitted
    </h1>


    <div class="success-details">

    <!-- APPOINTMENT NUMBER -->

    <div>

        <span>
            Appointment No.
        </span>

        <strong>
            #
            <?= adc_e($appointmentRef) ?>
        </strong>

    </div>


    <!-- APPOINTMENT DATE & TIME -->

    <div>

        <span>
            Appointment Date &amp; Time
        </span>

        <strong>

            <?php if ($successAppointment): ?>

                <?php
                $appointmentDate =
                    !empty($successAppointment['date'])
                    ? date(
                        'd M Y',
                        strtotime(
                            $successAppointment['date']
                        )
                    )
                    : '—';

                $appointmentTime =
                    !empty($successAppointment['time'])
                    ? date(
                        'h:i A',
                        strtotime(
                            $successAppointment['time']
                        )
                    )
                    : '—';
                ?>

                <?= adc_e($appointmentDate) ?>

                <br>

                <small
                    style="
                        font-size:13px;
                        font-weight:600;
                        color:#64748b;
                    "
                >
                    <?= adc_e($appointmentTime) ?>
                </small>

            <?php else: ?>

                —

            <?php endif; ?>

        </strong>

    </div>


    <!-- CLINIC BRANCH -->

    <div>

        <span>
            Clinic Branch
        </span>

        <strong>

            <?php if ($successAppointment): ?>

                <?php
                $branch =
                    str_ireplace(
                        ' Clinic',
                        '',
                        $successAppointment['clinic'] ?? ''
                    );
                ?>

                <?= adc_e($branch ?: '—') ?>

            <?php else: ?>

                —

            <?php endif; ?>

        </strong>

    </div>


    <!-- BOOKING DATE & TIME -->

    <div>

        <span
            style="
                font-size:11px;
                color:#94a3b8;
                font-weight:600;
            "
        >
            Booking Date &amp; Time
        </span>

        <strong
            style="
                font-size:12px;
                color:#94a3b8;
                font-weight:500;
            "
        >

            <?php if (
                $successAppointment
                &&
                !empty(
                    $successAppointment['booked_at']
                )
            ): ?>

                <?= adc_e(
                    date(
                        'd M Y, h:i A',
                        strtotime(
                            $successAppointment['booked_at']
                        )
                    )
                ) ?>

            <?php else: ?>

                —

            <?php endif; ?>

        </strong>

    </div>

</div>


    <a
        class="btn"
        href="appointment.php"
    >

        Book Another Appointment

    </a>


</div>


</div>


<?php endif; ?>


</div>

</main>


<!-- ================================================================
     JAVASCRIPT
     ================================================================ -->

<?php if ($step === 1): ?>

<script>

(function () {

    const phoneInput =
        document.getElementById('patientPhone');

    const resultBox =
        document.getElementById('livePatientResults');

    if (!phoneInput || !resultBox) {
        return;
    }

    let timer = null;
    let lastPhone = '';

    function esc(value) {

        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function showMessage(message, type) {

        const bg =
            type === 'error'
            ? '#fff1f2'
            : '#f8fbff';

        const border =
            type === 'error'
            ? '#fecdd3'
            : '#dbe5f1';

        resultBox.innerHTML =
            '<div style="padding:14px 16px;border:1px solid '
            + border
            + ';border-radius:12px;background:'
            + bg
            + ';color:#475569;">'
            + esc(message)
            + '</div>';
    }

    function renderPatients(patients, phone) {

        if (!patients.length) {

            resultBox.innerHTML =
                '<div style="padding:16px;border:1px solid #dbe5f1;border-radius:12px;background:#f8fbff;">'
                + '<strong style="display:block;color:#12345b;margin-bottom:5px;">No patient found</strong>'
                + '<span style="color:#64748b;">You can add a new patient using this same mobile number.</span>'
                + '</div>'
                + '<form method="post" action="appointment.php" style="margin-top:12px;">'
                + '<input type="hidden" name="step" value="1">'
                + '<input type="hidden" name="lookup_action" value="add_new">'
                + '<input type="hidden" name="phone" value="' + esc(phone) + '">'
                + '<button class="btn" type="submit" style="width:100%;">+ Add New Patient →</button>'
                + '</form>';

            return;
        }

        let html =
            '<div style="margin-bottom:12px;">'
            + '<h3 style="margin:0 0 5px;color:#12345b;">Patients registered with this number</h3>'
            + '<p style="margin:0;color:#64748b;">Select one patient to continue.</p>'
            + '</div>';

        html += '<div style="display:grid;gap:10px;">';

        patients.forEach(function (patient) {

            const pendingText = patient.pending
                ? '<span style="display:block;margin-top:5px;color:#b45309;font-weight:600;">Pending appointment — cannot book another appointment</span>'
                : '';

            const disabled = patient.pending ? ' disabled' : '';
            const opacity = patient.pending ? 'opacity:.65;cursor:not-allowed;' : 'cursor:pointer;';

            html +=
                '<form method="post" action="appointment.php" style="margin:0;">'
                + '<input type="hidden" name="step" value="1">'
                + '<input type="hidden" name="lookup_action" value="select_patient">'
                + '<input type="hidden" name="patient_id" value="' + Number(patient.id) + '">'
                + '<input type="hidden" name="phone" value="' + esc(phone) + '">'
                + '<button type="submit"' + disabled + ' style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;border:1px solid #dbe5f1;border-radius:14px;background:#f8fbff;color:#12345b;text-align:left;' + opacity + '">'
                + '<span>'
                + '<strong style="display:block;font-size:16px;">' + esc(patient.name) + '</strong>'
                + '<small style="display:block;margin-top:4px;color:#64748b;">Patient ID: ' + esc(patient.code) + '</small>'
                + pendingText
                + '</span>'
                + '<span style="font-weight:700;color:#2563eb;white-space:nowrap;">'
                + (patient.pending ? 'Pending' : 'Select →')
                + '</span>'
                + '</button>'
                + '</form>';
        });

        html += '</div>';

        html +=
            '<form method="post" action="appointment.php" style="margin-top:12px;">'
            + '<input type="hidden" name="step" value="1">'
            + '<input type="hidden" name="lookup_action" value="add_new">'
            + '<input type="hidden" name="phone" value="' + esc(phone) + '">'
            + '<button class="text-btn" type="submit" style="width:100%;">+ Add New Patient With This Same Number</button>'
            + '</form>';

        resultBox.innerHTML = html;
    }

    async function lookup() {

        const phone =
            phoneInput.value.replace(/\D/g, '');

        if (phone.length < 10) {
            resultBox.innerHTML = '';
            lastPhone = '';
            return;
        }

        if (phone === lastPhone) {
            return;
        }

        lastPhone = phone;

        showMessage('Checking patient records…', 'info');

        try {

            const response =
                await fetch(
                    'appointment.php?lookup_phone='
                    + encodeURIComponent(phone),
                    {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }
                );

            const data = await response.json();

            if (!data.success) {
                showMessage('Unable to check the patient records.', 'error');
                return;
            }

            renderPatients(
                data.patients || [],
                phone
            );

        } catch (error) {

            showMessage(
                'Could not check the patient records. You can press Find Patient to try again.',
                'error'
            );
        }
    }

    phoneInput.addEventListener('input', function () {

        phoneInput.value =
            phoneInput.value.replace(/\D/g, '').slice(0, 10);

        clearTimeout(timer);

        timer = setTimeout(
            lookup,
            350
        );
    });

})();

</script>


<?php endif; ?>


<?php if ($step === 3): ?>

<script>

(function () {


    const dateEl =
        document.getElementById(
            'appointmentDate'
        );


    const slotGrid =
        document.getElementById(
            'slotGrid'
        );


    const statusEl =
        document.getElementById(
            'scheduleStatus'
        );


    const selectedEl =
        document.getElementById(
            'selectedTime'
        );


    const selectedDisplay =
        document.getElementById(
            'selectedTimeDisplay'
        );


    const bookBtn =
        document.getElementById(
            'bookBtn'
        );


    const clinicEls =
        document.querySelectorAll(
            'input[name="clinic"]'
        );


    const pastProblemChoice =
        document.getElementById(
            'pastProblemChoice'
        );


    const problemEl =
        document.getElementById(
            'problem'
        );


    const problemDurationEl =
        document.getElementById(
            'problemDuration'
        );


    const problemDurationUnitEl =
        document.getElementById(
            'problemDurationUnit'
        );


    const problemDurationUnitHidden =
        document.getElementById(
            'problemDurationUnitHidden'
        );


    const newDiseasePanel =
        document.getElementById(
            'newDiseasePanel'
        );


    const diseasePreset =
        document.getElementById(
            'diseasePreset'
        );


    const diseaseButtons =
        document.querySelectorAll(
            '.disease-btn'
        );


    const problemChoicesInputs =
        document.getElementById(
            'problemChoicesInputs'
        );


    const pastProblemDetails =
        <?= json_encode(
            $pastProblemDetails,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;


    let selectedDiseases =
        <?= json_encode(
            array_values($problemChoices),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;


    function syncProblemChoicesInputs() {

        if (!problemChoicesInputs) {
            return;
        }

        problemChoicesInputs.innerHTML = '';

        selectedDiseases.forEach(
            function (value) {

                const input = document.createElement('input');

                input.type = 'hidden';
                input.name = 'problem_choices[]';
                input.value = value;

                problemChoicesInputs.appendChild(input);
            }
        );
    }


    function updateDiseaseButtons() {

        diseaseButtons.forEach(
            function (button) {

                const value =
                    button.getAttribute('data-disease') || '';

                button.classList.toggle(
                    'selected',
                    value === '__other__'
                        ? diseasePreset && diseasePreset.value === '__other__'
                        : selectedDiseases.indexOf(value) !== -1
                );
            }
        );
    }


    function updateProblemText() {

        if (!problemEl) {
            return;
        }

        if (diseasePreset && diseasePreset.value === '__other__') {
            if (!problemEl.value.trim() || problemEl.readOnly) {
                problemEl.value = selectedDiseases.join(', ');
            }
            problemEl.readOnly = false;
            return;
        }

        problemEl.value = selectedDiseases.join(', ');
        problemEl.readOnly = true;
    }


    function setDiseaseButton(value) {

        if (value === '__other__') {

            if (diseasePreset) {
                diseasePreset.value = '__other__';
            }

            if (otherProblemWrap) {
                otherProblemWrap.style.display = 'block';
            }

            if (problemEl) {
                if (!problemEl.value.trim()) {
                    problemEl.value = selectedDiseases.join(', ');
                }
                problemEl.readOnly = false;
                problemEl.focus();
            }

        } else if (value !== '') {

            const index = selectedDiseases.indexOf(value);

            if (index === -1) {
                selectedDiseases.push(value);
            } else {
                selectedDiseases.splice(index, 1);
            }

            /* Selecting a normal disease keeps Other mode off. */
            if (diseasePreset && diseasePreset.value === '__other__') {
                diseasePreset.value = '';
                if (otherProblemWrap) {
                    otherProblemWrap.style.display = 'none';
                }
            }

            if (diseasePreset) {
                diseasePreset.value = selectedDiseases.length
                    ? selectedDiseases.join('||')
                    : '';
            }

            if (problemEl) {
                problemEl.value = selectedDiseases.join(', ');
                problemEl.readOnly = true;
            }
        }

        syncProblemChoicesInputs();
        updateDiseaseButtons();
    }


    function syncProblemChoice() {

        if (!pastProblemChoice) {

            if (newDiseasePanel) {
                newDiseasePanel.style.display = 'block';
            }

            if (diseasePreset && diseasePreset.value === '__other__') {
                if (otherProblemWrap) {
                    otherProblemWrap.style.display = 'block';
                }
                if (problemEl) {
                    problemEl.readOnly = false;
                }
            } else {
                updateProblemText();
            }

            syncProblemChoicesInputs();
            updateDiseaseButtons();
            return;
        }

        const value = pastProblemChoice.value;

        if (value === '__new__') {

            newDiseasePanel.style.display = 'block';

            if (problemDurationEl) {
                problemDurationEl.readOnly = false;
                problemDurationEl.value = '';
            }

            if (problemDurationUnitEl) {
                problemDurationUnitEl.disabled = false;
            }

            if (problemDurationUnitHidden) {
                problemDurationUnitHidden.disabled = true;
            }

            if (diseasePreset && diseasePreset.value === '__other__') {
                if (otherProblemWrap) {
                    otherProblemWrap.style.display = 'block';
                }
                if (problemEl) {
                    problemEl.readOnly = false;
                }
            } else {
                updateProblemText();
            }

            syncProblemChoicesInputs();
            updateDiseaseButtons();
            return;
        }

        newDiseasePanel.style.display = 'none';

        if (value !== '') {

            if (problemEl) {
                problemEl.value = value;
                problemEl.readOnly = true;
            }

            const details =
                pastProblemDetails[value] || {};

            if (problemDurationEl) {
                problemDurationEl.value =
                    details.duration || problemDurationEl.value;
                problemDurationEl.readOnly = true;
            }

            if (problemDurationUnitEl) {
                const unit = details.unit || 'Days';
                problemDurationUnitEl.value = unit;
                problemDurationUnitEl.disabled = true;
            }

            if (problemDurationUnitHidden) {
                problemDurationUnitHidden.value = details.unit || 'Days';
                problemDurationUnitHidden.disabled = false;
            }

            if (diseasePreset) {
                diseasePreset.value = '';
            }

            selectedDiseases = [];
            syncProblemChoicesInputs();

            diseaseButtons.forEach(
                function (button) {
                    button.classList.remove('selected');
                }
            );

        } else {

            if (problemEl) {
                problemEl.value = '';
                problemEl.readOnly = true;
            }

            if (problemDurationEl) {
                problemDurationEl.readOnly = true;
            }

            if (problemDurationUnitEl) {
                problemDurationUnitEl.disabled = true;
            }

            if (problemDurationUnitHidden) {
                problemDurationUnitHidden.disabled = false;
            }
        }
    }


    diseaseButtons.forEach(
        function (button) {

            button.addEventListener(
                'click',
                function () {
                    setDiseaseButton(
                        button.getAttribute('data-disease') || ''
                    );
                }
            );
        }
    );


    if (pastProblemChoice) {

        pastProblemChoice.addEventListener(
            'change',
            syncProblemChoice
        );

        syncProblemChoice();

    } else {

        syncProblemChoice();
    }



    const builtInHolidays =

        <?=

        json_encode(
            $builtInHolidays,
            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
        )

        ?>;


    /* ============================================================
       GET SELECTED CLINIC
       ============================================================ */

    function getClinic() {

        const element =
            document.querySelector(
                'input[name="clinic"]:checked'
            );


        return element
            ?
            element.value
            :
            '';
    }


    function escapeHtml(value) {

        return String(value)
            .replace(/[&<>'"]/g, function (character) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                };
                return map[character];
            });
    }


    /* ============================================================
       PAD NUMBER
       ============================================================ */

    function pad(number) {

        return String(
            number
        ).padStart(
            2,
            '0'
        );
    }


    /* ============================================================
       FORMAT TIME
       ============================================================ */

    function formatTime(
        hour,
        minute
    ) {

        const period =
            hour >= 12
            ?
            'PM'
            :
            'AM';


        let displayHour =
            hour % 12;


        if (
            displayHour === 0
        ) {

            displayHour = 12;
        }


        return (

            displayHour

            +

            ':'

            +

            pad(minute)

            +

            ' '

            +

            period

        );
    }


    /* ============================================================
       HOLIDAY
       ============================================================ */

    function isHoliday(
        date
    ) {

        return (
            builtInHolidays[date]
            ||
            ''
        );
    }


    /* ============================================================
       GENERATE SCHEDULE
       ============================================================ */

    function schedule(
        clinic,
        date
    ) {

        if (!date) {

            return {

                open: false,

                reason:
                    'Select an appointment date.',

                slots: []

            };
        }


        const d =
            new Date(
                date
                +
                'T12:00:00'
            );


        /*
           JavaScript:

           Sunday = 0
           Monday = 1
           Tuesday = 2
           Wednesday = 3
           Thursday = 4
           Friday = 5
           Saturday = 6
        */

        const day =
            d.getDay();


        /* Tuesday */

        if (
            day === 2
        ) {

            return {

                open: false,

                reason:
                    'Both clinics are closed every Tuesday.',

                slots: []

            };
        }


        /* Holiday */

        const holiday =
            isHoliday(date);


        if (holiday) {

            return {

                open: false,

                reason:
                    'Clinic closed: '
                    +
                    holiday,

                slots: []

            };
        }


        let start;
        let end;


        /* ========================================================
           SANKISA
           ======================================================== */

        if (
            clinic ===
            'Sankisa Clinic'
        ) {

            /*
               09:30 AM
               = 570 minutes
            */

            start = 570;


            /*
               02:30 PM
               = 870 minutes
            */

            end = 870;
        }


        /* ========================================================
           MAINPURI
           ======================================================== */

        else if (
            clinic ===
            'Mainpuri Clinic'
        ) {

            if (
                day !== 3
                &&
                day !== 6
            ) {

                return {

                    open: false,

                    reason:
                        'Mainpuri Clinic is open only on Wednesday and Saturday.',

                    slots: []

                };
            }


            /*
               04:00 PM
            */

            start = 960;


            /*
               06:30 PM
            */

            end = 1110;
        }


        else {

            return {

                open: false,

                reason:
                    'Select a clinic first.',

                slots: []

            };
        }


        const slots = [];


        /*
           Every 10 minutes.

           Example:

           09:30
           09:40
           09:50
           10:00
           10:10
           ...
        */

        for (
            let minutes = start;
            minutes < end;
            minutes += 10
        ) {

            const hour =
                Math.floor(
                    minutes / 60
                );


            const minute =
                minutes % 60;


            const value =

                pad(hour)
                +
                ':'
                +
                pad(minute)
                +
                ':00';


            const label =

                formatTime(
                    hour,
                    minute
                );


            slots.push({

                value:
                    value,

                label:
                    label

            });
        }


        return {

            open:
                true,

            reason:
                '',

            slots:
                slots

        };
    }


    /* ============================================================
       RENDER SLOTS
       ============================================================ */

    async function renderSlots() {

        const clinic =
            getClinic();

        const date =
            dateEl.value;

        slotGrid.innerHTML = '';

        selectedEl.value = '';

        selectedDisplay.value =
            'Select a time below';

        bookBtn.disabled = true;

        if (!clinic || !date) {

            statusEl.className =
                'schedule-status';

            statusEl.textContent =
                'Select clinic and date to see available times.';

            return;
        }

        statusEl.className =
            'schedule-status';

        statusEl.textContent =
            'Checking available time slots...';

        try {

            const response = await fetch(
                'appointment.php?ajax=slots'
                + '&clinic=' + encodeURIComponent(clinic)
                + '&date=' + encodeURIComponent(date),
                {
                    cache: 'no-store'
                }
            );

            const data = await response.json();

            if (!data.open) {

                statusEl.className =
                    'schedule-status closed';

                statusEl.textContent =
                    data.reason || 'Clinic is closed.';

                return;
            }

            const booked = new Set(
                Array.isArray(data.booked)
                    ? data.booked
                    : []
            );

            const availableCount =
                data.slots.filter(
                    function (slot) {
                        return !booked.has(slot.value);
                    }
                ).length;

            statusEl.className =
                'schedule-status open';

            statusEl.textContent =
                availableCount
                + ' available • '
                + booked.size
                + ' booked';

            data.slots.forEach(
                function (slot) {

                    const isBooked =
                        booked.has(slot.value);

                    const button =
                        document.createElement('button');

                    button.type = 'button';

                    button.className =
                        'slot '
                        + (isBooked ? 'booked' : 'available');

                    button.innerHTML =
                        '<span>'
                        + escapeHtml(slot.label)
                        + '</span>'
                        + '<span class="slot-status">'
                        + (isBooked ? 'BOOKED' : 'AVAILABLE')
                        + '</span>';

                    if (isBooked) {

                        button.disabled = true;
                        button.title =
                            'This time slot is already booked.';

                    } else {

                        button.addEventListener(
                            'click',
                            function () {

                                document
                                    .querySelectorAll('.slot.selected')
                                    .forEach(
                                        function (item) {
                                            item.classList.remove('selected');
                                        }
                                    );

                                button.classList.add('selected');

                                selectedEl.value =
                                    slot.value;

                                selectedDisplay.value =
                                    slot.label;

                                bookBtn.disabled =
                                    false;
                            }
                        );
                    }

                    slotGrid.appendChild(button);
                }
            );

        } catch (error) {

            statusEl.className =
                'schedule-status closed';

            statusEl.textContent =
                'Unable to check booked slots. Please refresh and try again.';
        }
    }


    /* ============================================================
       DATE PICKER
       ============================================================

       Clicking the date field opens the browser's
       calendar directly.

       showPicker() is supported by modern Chrome/Edge/Brave.

       ============================================================ */

    dateEl.addEventListener(
        'click',
        function () {

            if (
                typeof this.showPicker
                ===
                'function'
            ) {

                try {

                    this.showPicker();

                }

                catch (error) {

                    /*
                       Browser already opened
                       the native calendar.
                    */

                }
            }
        }
    );


    /* ============================================================
       DATE CHANGED
       ============================================================ */

    dateEl.addEventListener(
        'change',
        function () {

            renderSlots();

        }
    );


    /* ============================================================
       CLINIC CHANGED
       ============================================================ */

    clinicEls.forEach(
        function (element) {

            element.addEventListener(
                'change',
                function () {

                    renderSlots();

                }
            );
        }
    );


    /* ============================================================
       INITIAL LOAD
       ============================================================ */

    renderSlots();


})();

</script>

<?php endif; ?>


</body>

</html>