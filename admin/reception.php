<?php
require_once '../config.php';
require_staff(['Admin', 'Reception']);

$role = $_SESSION['staff_role'] ?? 'Reception';
$staffName = $_SESSION['staff_name'] ?? $_SESSION['staff_username'] ?? 'Reception';

$today = date('Y-m-d');
$message = '';
$error = '';

/* =========================================================
   QUEUE COLUMNS
   ========================================================= */

function queue_column_exists($db, $table, $column)
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);

    $result = $db->query(
        "SHOW COLUMNS FROM `$table` LIKE '$column'"
    );

    return $result && $result->num_rows > 0;
}


function queue_ensure_column($db, $table, $column, $definition)
{
    if (!queue_column_exists($db, $table, $column)) {
        $db->query(
            "ALTER TABLE `$table` ADD COLUMN `$column` $definition"
        );
    }
}


/*
 * These fields make the reception queue independent
 * from the public appointment form.
 */

queue_ensure_column(
    $conn,
    'appointments',
    'token_no',
    "INT NULL"
);

queue_ensure_column(
    $conn,
    'appointments',
    'checked_in_at',
    "DATETIME NULL"
);

queue_ensure_column(
    $conn,
    'appointments',
    'checked_in_by',
    "VARCHAR(120) NULL"
);

queue_ensure_column(
    $conn,
    'appointments',
    'visit_id',
    "INT NULL"
);


/* =========================================================
   CHECK-IN
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'checkin'
) {

    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {

        $error = 'Invalid appointment selected.';

    } else {

        $stmt = $conn->prepare(
            "SELECT
                a.id,
                a.patient_id,
                a.name,
                a.phone,
                a.clinic,
                a.service,
                a.problem,
                a.date,
                a.time,
                a.status,
                a.token_no,
                a.visit_id,
                p.code AS patient_code,
                p.age,
                p.gender,
                p.address,
                p.district

             FROM appointments a

             LEFT JOIN patients p
                ON p.id = a.patient_id

             WHERE a.id = ?

             LIMIT 1"
        );

        $appointment = null;

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $appointmentId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $appointment = $result
                ? $result->fetch_assoc()
                : null;

            $stmt->close();
        }


        if (!$appointment) {

            $error = 'Appointment not found.';

        } elseif (
            strtolower(
                trim(
                    $appointment['status'] ?? ''
                )
            ) === 'cancelled'
        ) {

            $error = 'Cancelled appointments cannot be checked in.';

        } elseif (!empty($appointment['visit_id'])) {

            $error = 'This appointment has already been checked in.';

        } else {

            /* Generate today's next token. */

            $tokenResult = $conn->query(
                "SELECT
                    COALESCE(MAX(token_no), 0) + 1 AS next_token
                 FROM appointments
                 WHERE date='" .
                $conn->real_escape_string($today) .
                "'"
            );

            $tokenRow = $tokenResult
                ? $tokenResult->fetch_assoc()
                : null;

            $token = max(
                1,
                (int)($tokenRow['next_token'] ?? 1)
            );


            /* Generate the next OPD serial. */

            $serialResult = $conn->query(
                "SELECT
                    COALESCE(MAX(opd_serial),1000) + 1 AS next_serial
                 FROM visits"
            );

            $serialRow = $serialResult
                ? $serialResult->fetch_assoc()
                : null;

            $opdSerial = max(
                1001,
                (int)($serialRow['next_serial'] ?? 1001)
            );


            $patientId = (int)(
                $appointment['patient_id'] ?? 0
            );


            if ($patientId <= 0) {

                $error =
                    'This appointment does not have a valid patient record.';

            } else {

                $conn->begin_transaction();

                try {

                    /*
                     * Create permanent visit record.
                     * Diagnosis/prescription remain empty
                     * until Doctor Consultation.
                     */

                    $visitStmt = $conn->prepare(
                        "INSERT INTO visits
                        (
                            opd_serial,
                            patient_id,
                            visit_date,
                            main_conditions,
                            symptoms,
                            duration_number,
                            duration_unit,
                            fee_required,
                            fee_amount,
                            status,
                            created_by,
                            created_at
                        )

                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            '',
                            '',
                            0,
                            0,
                            'Waiting',
                            ?,
                            NOW()
                        )"
                    );


                    if (!$visitStmt) {

                        throw new Exception(
                            'Unable to prepare visit record: ' .
                            $conn->error
                        );
                    }


                    $problem = trim(
                        ($appointment['problem'] ?? '') !== ''
                            ? $appointment['problem']
                            : ($appointment['service'] ?? '')
                    );

                    $symptoms = $problem;


                    $visitStmt->bind_param(
                        'iissss',
                        $opdSerial,
                        $patientId,
                        $today,
                        $problem,
                        $symptoms,
                        $staffName
                    );


                    if (!$visitStmt->execute()) {

                        throw new Exception(
                            'Unable to create visit record: ' .
                            $visitStmt->error
                        );
                    }


                    $visitId = (int)$conn->insert_id;

                    $visitStmt->close();


                    /* Mark appointment as checked in. */

                    $updateStmt = $conn->prepare(
                        "UPDATE appointments
                         SET
                            status='Checked In',
                            token_no=?,
                            checked_in_at=NOW(),
                            checked_in_by=?,
                            visit_id=?
                         WHERE id=?"
                    );


                    if (!$updateStmt) {

                        throw new Exception(
                            'Unable to prepare queue update: ' .
                            $conn->error
                        );
                    }


                    $updateStmt->bind_param(
                        'isii',
                        $token,
                        $staffName,
                        $visitId,
                        $appointmentId
                    );


                    if (!$updateStmt->execute()) {

                        throw new Exception(
                            'Unable to update appointment queue: ' .
                            $updateStmt->error
                        );
                    }


                    $updateStmt->close();


                    if (function_exists('audit')) {

                        audit(
                            'Reception',
                            'Patient checked in',
                            'Appointment #' .
                            $appointmentId .
                            ', Patient #' .
                            $patientId .
                            ', Token T-' .
                            str_pad(
                                (string)$token,
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) .
                            ', OPD #' .
                            $opdSerial
                        );
                    }


                    $conn->commit();


                    $message =
                        'Patient checked in successfully. ' .
                        'Token T-' .
                        str_pad(
                            (string)$token,
                            2,
                            '0',
                            STR_PAD_LEFT
                        ) .
                        ' • OPD #' .
                        $opdSerial;

                } catch (Throwable $e) {

                    $conn->rollback();

                    $error = $e->getMessage();
                }
            }
        }
    }
}


/* =========================================================
   CALL NEXT PATIENT
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'call_next'
) {

    /*
     * This only changes queue state.
     * Actual consultation is completed by Doctor page.
     */

    $next = $conn->query(
        "SELECT id
         FROM appointments
         WHERE date='" .
        $conn->real_escape_string($today) .
        "'
         AND status IN ('Checked In','Waiting')
         ORDER BY token_no ASC, id ASC
         LIMIT 1"
    );


    $nextRow = $next
        ? $next->fetch_assoc()
        : null;


    if ($nextRow) {

        $id = (int)$nextRow['id'];


        $stmt = $conn->prepare(
            "UPDATE appointments
             SET status='Waiting'
             WHERE id=?"
        );


        if ($stmt) {

            $stmt->bind_param(
                'i',
                $id
            );

            $stmt->execute();

            $stmt->close();
        }


        $message =
            'Next patient is ready for the doctor.';

    } else {

        $error =
            'There is no checked-in patient waiting in the queue.';
    }
}


/* =========================================================
   TODAY'S QUEUE
   ========================================================= */

$queue = [];


$stmt = $conn->prepare(
    "SELECT
        a.id,
        a.patient_id,
        a.name,
        a.phone,
        a.clinic,
        a.service,
        a.problem,
        a.time,
        a.status,
        a.token_no,
        a.checked_in_at,
        a.visit_id,
        p.code AS patient_code,
        p.age,
        p.gender

     FROM appointments a

     LEFT JOIN patients p
        ON p.id=a.patient_id

     WHERE a.date=?
       AND a.status IN
           ('Checked In','Waiting','Approved','Completed')

     ORDER BY

        CASE
            WHEN a.status='Waiting' THEN 1
            WHEN a.status='Checked In' THEN 2
            WHEN a.status='Approved' THEN 3
            ELSE 4
        END,

        a.token_no ASC,
        a.id ASC

     LIMIT 100"
);


if ($stmt) {

    $stmt->bind_param(
        's',
        $today
    );

    $stmt->execute();

    $result = $stmt->get_result();


    while ($row = $result->fetch_assoc()) {

        $queue[] = $row;
    }


    $stmt->close();
}


/* =========================================================
   COUNTS
   ========================================================= */

$totalQueue = count($queue);

$waitingCount = 0;
$completedCount = 0;


foreach ($queue as $q) {

    if (
        ($q['status'] ?? '') === 'Waiting' ||
        ($q['status'] ?? '') === 'Checked In'
    ) {

        $waitingCount++;
    }


    if (
        ($q['status'] ?? '') === 'Completed'
    ) {

        $completedCount++;
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
        Reception & Queue | AmarDayal Clinic
    </title>

    <link
        rel="stylesheet"
        href="../Styles/style.css"
    >

    <style>

        :root {

            --bg: #f5f8fc;
            --card: #ffffff;
            --text: #10233f;
            --muted: #718096;
            --line: #e4eaf1;

            --blue: #2563eb;
            --blue-soft: #edf4ff;

            --green: #059669;
            --green-soft: #eafaf4;

            --orange: #d97706;
            --orange-soft: #fff7e8;

            --red: #dc2626;
            --red-soft: #fff0f0;
        }


        body {

            background: var(--bg);
        }


        /*
         * IMPORTANT:
         * Sidebar is loaded from sidebar.php.
         *
         * This page does NOT contain a duplicate sidebar.
         */


        .queue-main {

            padding: 28px 34px 50px;

            max-width: 1600px;

            margin: auto;
        }


        .queue-head {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;
        }


        .kicker {

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 1.6px;

            color: var(--blue);

            text-transform: uppercase;
        }


        .queue-head h1 {

            margin: 6px 0;

            font-size: 31px;

            color: var(--text);
        }


        .queue-head p {

            margin: 0;

            color: var(--muted);

            font-size: 14px;
        }


        .actions {

            display: flex;

            gap: 10px;

            flex-wrap: wrap;
        }


        .btn {

            display: inline-flex;

            align-items: center;

            gap: 7px;

            padding: 11px 15px;

            border-radius: 11px;

            border: 1px solid var(--line);

            background: #fff;

            color: var(--text);

            text-decoration: none;

            font-size: 13px;

            font-weight: 750;

            transition: .2s ease;
        }


        .btn:hover {

            transform: translateY(-1px);

            box-shadow:
                0 5px 15px rgba(15, 23, 42, .08);
        }


        .btn.primary {

            background: var(--blue);

            border-color: var(--blue);

            color: #fff;
        }


        .alert {

            padding: 13px 15px;

            border-radius: 12px;

            margin-bottom: 18px;

            font-size: 13px;

            font-weight: 700;
        }


        .alert.success {

            background: var(--green-soft);

            color: var(--green);

            border: 1px solid #b9efd9;
        }


        .alert.error {

            background: var(--red-soft);

            color: var(--red);

            border: 1px solid #fecaca;
        }


        .stats {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;

            margin-bottom: 20px;
        }


        .stat {

            background: #fff;

            border: 1px solid var(--line);

            border-radius: 17px;

            padding: 18px;

            box-shadow:
                0 8px 25px rgba(15, 23, 42, .05);
        }


        .stat-label {

            font-size: 11px;

            color: var(--muted);

            font-weight: 800;
        }


        .stat-value {

            font-size: 28px;

            color: var(--text);

            font-weight: 850;

            margin-top: 5px;
        }


        .layout-grid {

            display: grid;

            grid-template-columns:
                minmax(0, 1fr) 320px;

            gap: 20px;
        }


        .panel {

            background: #fff;

            border: 1px solid var(--line);

            border-radius: 18px;

            box-shadow:
                0 8px 25px rgba(15, 23, 42, .05);
        }


        .panel-head {

            padding: 20px 21px 14px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;
        }


        .panel-title {

            font-size: 17px;

            color: var(--text);

            font-weight: 850;
        }


        .panel-sub {

            font-size: 11px;

            color: var(--muted);

            margin-top: 3px;
        }


        .panel-body {

            padding: 0 21px 21px;
        }


        .queue-card {

            display: grid;

            grid-template-columns:
                82px
                minmax(180px, 1fr)
                150px
                105px
                120px;

            gap: 15px;

            align-items: center;

            padding: 16px 0;

            border-top: 1px solid #edf1f5;
        }


        .queue-card:first-child {

            border-top: 0;
        }


        .token {

            width: 54px;

            height: 54px;

            display: grid;

            place-items: center;

            border-radius: 14px;

            background: var(--blue-soft);

            color: var(--blue);

            font-weight: 900;

            font-size: 14px;
        }


        .patient-name {

            font-size: 14px;

            color: var(--text);

            font-weight: 850;
        }


        .patient-meta {

            font-size: 11px;

            color: var(--muted);

            margin-top: 4px;
        }


        .clinic {

            font-size: 12px;

            color: #52647d;
        }


        .status {

            display: inline-flex;

            width: max-content;

            padding: 6px 9px;

            border-radius: 999px;

            font-size: 10px;

            font-weight: 850;
        }


        .status.waiting {

            background: var(--orange-soft);

            color: var(--orange);
        }


        .status.checked {

            background: var(--blue-soft);

            color: var(--blue);
        }


        .status.completed {

            background: var(--green-soft);

            color: var(--green);
        }


        .checkin-form {

            margin: 0;
        }


        .checkin-btn {

            border: 0;

            border-radius: 9px;

            padding: 9px 11px;

            background: var(--blue);

            color: #fff;

            font-weight: 800;

            font-size: 11px;

            cursor: pointer;
        }


        .checkin-btn:hover {

            background: #1d4ed8;
        }


        .checkin-btn:disabled {

            background: #cbd5e1;

            cursor: not-allowed;
        }


        .call-box {

            padding: 18px;

            border-radius: 15px;

            background:
                linear-gradient(
                    135deg,
                    #eff6ff,
                    #f8fbff
                );

            border: 1px solid #dbeafe;
        }


        .call-label {

            font-size: 10px;

            color: var(--muted);

            font-weight: 800;

            letter-spacing: .7px;
        }


        .call-title {

            font-size: 21px;

            color: var(--text);

            font-weight: 850;

            margin-top: 5px;
        }


        .call-box p {

            font-size: 11px;

            color: var(--muted);

            line-height: 1.55;
        }


        .call-btn {

            width: 100%;

            padding: 12px;

            border: 0;

            border-radius: 10px;

            background: var(--blue);

            color: #fff;

            font-weight: 850;

            cursor: pointer;
        }


        .call-btn:hover {

            background: #1d4ed8;
        }


        .info-list {

            margin-top: 18px;
        }


        .info-item {

            padding: 11px 0;

            border-top: 1px solid var(--line);

            font-size: 12px;
        }


        .info-item:first-child {

            border-top: 0;
        }


        .info-item strong {

            display: block;

            color: var(--text);
        }


        .info-item span {

            display: block;

            color: var(--muted);

            margin-top: 3px;
        }


        .empty {

            text-align: center;

            padding: 40px 15px;

            color: var(--muted);

            font-size: 12px;
        }


        /* =====================================================
           RESPONSIVE
           ===================================================== */

        @media (max-width: 1100px) {

            .layout-grid {

                grid-template-columns: 1fr;
            }


            .queue-card {

                grid-template-columns:
                    70px
                    1fr
                    120px
                    100px;
            }


            .queue-card .checkin-form {

                grid-column: 2 / -1;
            }
        }


        @media (max-width: 700px) {

            .queue-main {

                padding: 20px 14px 35px;
            }


            .queue-head {

                align-items: flex-start;

                flex-direction: column;
            }


            .stats {

                grid-template-columns: 1fr;
            }


            .queue-card {

                grid-template-columns:
                    58px 1fr;
            }


            .queue-card .clinic,
            .queue-card .status,
            .queue-card .checkin-form {

                grid-column: 2;
            }
        }

        /* =========================================================
           SHARED SIDEBAR / CONTENT LAYOUT
           ========================================================= */

        /*
         * sidebar.php is the single shared sidebar for the admin portal.
         * It is fixed on the left, so reception.php must reserve the
         * sidebar width instead of allowing the page to render beneath it.
         *
         * IMPORTANT:
         * Do not create another <aside class="sidebar"> in this file.
         */

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        .layout {
            display: block !important;
            position: relative;
            width: 100%;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /*
         * The shared sidebar shown in the admin portal is 290px wide.
         * Force the content area to begin after it.
         */

        .layout > .content {
            position: relative;
            display: block;
            width: calc(100% - 290px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            margin-left: 290px !important;
            padding: 0;
            box-sizing: border-box;
            overflow: visible;
        }

        /*
         * Reception page wrapper.
         */

        .queue-main {
            display: block;
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            padding: 28px 34px 50px;
            box-sizing: border-box;
        }

        /*
         * Prevent the page's inner grid from being wider than the
         * available content area.
         */

        .layout-grid,
        .stats,
        .queue-head {
            max-width: 100%;
            box-sizing: border-box;
        }

        /*
         * The patient queue should be allowed to shrink instead of
         * pushing the page underneath the sidebar.
         */

        .layout-grid {
            min-width: 0;
        }

        .panel {
            min-width: 0;
        }

        .queue-card {
            min-width: 0;
        }

        .queue-card > div {
            min-width: 0;
        }

        /*
         * TABLET
         */

        @media (max-width: 1200px) and (min-width: 701px) {

            .layout > .content {
                width: calc(100% - 290px) !important;
                margin-left: 290px !important;
            }

            .queue-main {
                padding: 24px 22px 45px;
            }

        }

        /*
         * MOBILE
         *
         * sidebar.php can become part of the normal document flow
         * on smaller screens.
         */

        @media (max-width: 700px) {

            .layout {
                display: block !important;
                width: 100%;
            }

            .layout > .content {
                width: 100% !important;
                margin-left: 0 !important;
                min-height: auto;
            }

            .queue-main {
                width: 100%;
                padding: 20px 14px 35px;
            }

        }

    </style>

</head>


<body>

<div class="layout">

    <!-- =====================================================
         SHARED ADMIN SIDEBAR
         ===================================================== -->

    <?php require_once 'sidebar.php'; ?>


    <!-- =====================================================
         MAIN CONTENT
         ===================================================== -->

    <main class="content">

        <div class="queue-main">


            <!-- =================================================
                 PAGE HEADER
                 ================================================= -->

            <header class="queue-head">

                <div>

                    <div class="kicker">
                        Reception • Patient Flow
                    </div>

                    <h1>
                        Today's Queue
                    </h1>

                    <p>
                        <?= date('l, d F Y') ?>
                        • Check in patients and control
                        the clinic queue.
                    </p>

                </div>


                <div class="actions">

                    <a
                        class="btn primary"
                        href="../appointment.php"
                    >
                        ＋ New Appointment
                    </a>


                    <a
                        class="btn"
                        href="appointments.php"
                    >
                        View Appointments →
                    </a>

                </div>

            </header>


            <!-- =================================================
                 ALERTS
                 ================================================= -->

            <?php if ($message): ?>

                <div class="alert success">
                    <?= e($message) ?>
                </div>

            <?php endif; ?>


            <?php if ($error): ?>

                <div class="alert error">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>


            <!-- =================================================
                 STATISTICS
                 ================================================= -->

            <section class="stats">


                <div class="stat">

                    <div class="stat-label">
                        TODAY'S QUEUE
                    </div>

                    <div class="stat-value">
                        <?= $totalQueue ?>
                    </div>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        WAITING / CHECKED IN
                    </div>

                    <div class="stat-value">
                        <?= $waitingCount ?>
                    </div>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        COMPLETED VISITS
                    </div>

                    <div class="stat-value">
                        <?= $completedCount ?>
                    </div>

                </div>


            </section>


            <!-- =================================================
                 QUEUE + CONTROL
                 ================================================= -->

            <section class="layout-grid">


                <!-- =============================================
                     PATIENT QUEUE
                     ============================================= -->

                <article class="panel">


                    <div class="panel-head">

                        <div>

                            <div class="panel-title">
                                Patient Queue
                            </div>

                            <div class="panel-sub">
                                Appointments scheduled for today
                            </div>

                        </div>

                    </div>


                    <div class="panel-body">


                        <?php if (!$queue): ?>

                            <div class="empty">

                                📅

                                <br>
                                <br>

                                <strong>
                                    No patients are in today's queue.
                                </strong>

                                <br>

                                Approved appointments
                                will appear here.

                            </div>


                        <?php else: ?>


                            <?php foreach ($queue as $item): ?>


                                <?php

                                $status =
                                    $item['status'] ?? 'Pending';


                                if ($status === 'Waiting') {

                                    $statusClass = 'waiting';

                                } elseif (
                                    $status === 'Completed'
                                ) {

                                    $statusClass = 'completed';

                                } else {

                                    $statusClass = 'checked';
                                }

                                ?>


                                <div class="queue-card">


                                    <!-- TOKEN -->

                                    <div>

                                        <div class="token">

                                            T-
                                            <?= str_pad(
                                                (string)(
                                                    (int)(
                                                        $item['token_no']
                                                        ?? 0
                                                    )
                                                ),
                                                2,
                                                '0',
                                                STR_PAD_LEFT
                                            ) ?>

                                        </div>

                                    </div>


                                    <!-- PATIENT -->

                                    <div>

                                        <div class="patient-name">

                                            <?= e(
                                                $item['name']
                                                ?: 'Patient'
                                            ) ?>

                                        </div>


                                        <div class="patient-meta">

                                            Patient ID:

                                            <?= e(
                                                $item['patient_code']
                                                ?? $item['patient_id']
                                            ) ?>


                                            <?php if (
                                                !empty($item['age'])
                                            ): ?>

                                                • Age
                                                <?= e(
                                                    $item['age']
                                                ) ?>

                                            <?php endif; ?>


                                            <?php if (
                                                !empty($item['gender'])
                                            ): ?>

                                                •
                                                <?= e(
                                                    $item['gender']
                                                ) ?>

                                            <?php endif; ?>

                                        </div>


                                        <div class="patient-meta">

                                            <?= e(
                                                $item['phone']
                                                ?? ''
                                            ) ?>


                                            <?php if (
                                                !empty(
                                                    $item['service']
                                                )
                                            ): ?>

                                                •
                                                <?= e(
                                                    $item['service']
                                                ) ?>

                                            <?php endif; ?>

                                        </div>

                                    </div>


                                    <!-- CLINIC -->

                                    <div class="clinic">

                                        <?= e(
                                            $item['clinic']
                                            ?? 'Clinic'
                                        ) ?>

                                        <br>

                                        <?= e(
                                            $item['time']
                                            ?? ''
                                        ) ?>

                                    </div>


                                    <!-- STATUS -->

                                    <div>

                                        <span
                                            class="status
                                            <?= $statusClass ?>"
                                        >
                                            <?= e($status) ?>
                                        </span>

                                    </div>


                                    <!-- ACTION -->

                                    <div>


                                        <?php if (
                                            empty(
                                                $item['visit_id']
                                            ) &&
                                            $status !== 'Completed'
                                        ): ?>


                                            <form
                                                method="post"
                                                class="checkin-form"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="checkin"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="appointment_id"
                                                    value="<?= e(
                                                        $item['id']
                                                    ) ?>"
                                                >


                                                <button
                                                    class="checkin-btn"
                                                    type="submit"
                                                >
                                                    Check In
                                                </button>

                                            </form>


                                        <?php elseif (
                                            !empty(
                                                $item['visit_id']
                                            )
                                        ): ?>


                                            <a
                                                class="btn"
                                                style="
                                                    padding:8px 10px;
                                                    font-size:10px;
                                                "
                                                href="doctor.php"
                                            >
                                                Doctor →
                                            </a>


                                        <?php endif; ?>


                                    </div>


                                </div>


                            <?php endforeach; ?>


                        <?php endif; ?>


                    </div>

                </article>


                <!-- =============================================
                     QUEUE CONTROL
                     ============================================= -->

                <aside class="panel">


                    <div class="panel-head">

                        <div>

                            <div class="panel-title">
                                Queue Control
                            </div>

                            <div class="panel-sub">
                                Reception desk
                            </div>

                        </div>

                    </div>


                    <div class="panel-body">


                        <div class="call-box">

                            <div class="call-label">
                                PATIENT FLOW
                            </div>


                            <div class="call-title">
                                Ready for Doctor
                            </div>


                            <p>

                                Check in the patient first.
                                The patient receives a token
                                and a permanent OPD visit record.

                            </p>


                            <form method="post">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="call_next"
                                >


                                <button
                                    class="call-btn"
                                    type="submit"
                                >
                                    ▶ Call Next Patient
                                </button>

                            </form>

                        </div>


                        <div class="info-list">


                            <div class="info-item">

                                <strong>
                                    1. Check In
                                </strong>

                                <span>
                                    Confirm the patient
                                    has arrived.
                                </span>

                            </div>


                            <div class="info-item">

                                <strong>
                                    2. Token + OPD
                                </strong>

                                <span>
                                    The system creates today's
                                    queue token and permanent visit.
                                </span>

                            </div>


                            <div class="info-item">

                                <strong>
                                    3. Doctor
                                </strong>

                                <span>
                                    The doctor opens the patient
                                    and completes consultation.
                                </span>

                            </div>


                            <div class="info-item">

                                <strong>
                                    4. Complete
                                </strong>

                                <span>
                                    Diagnosis, prescription and
                                    billing stay linked to the visit.
                                </span>

                            </div>


                        </div>


                    </div>

                </aside>


            </section>


        </div>

    </main>

</div>

</body>

</html>