<?php

require_once 'config.php';

header(
    'Content-Type: application/json; charset=utf-8'
);


/*
|--------------------------------------------------------------------------
| Generate clinic schedule
|--------------------------------------------------------------------------
*/

function schedule_for(
    string $clinic,
    string $date
): array {

    $day =
        (int)date(
            'N',
            strtotime($date)
        );


    /*
    Monday    = 1
    Tuesday   = 2
    Wednesday = 3
    Thursday  = 4
    Friday    = 5
    Saturday  = 6
    Sunday    = 7
    */


    /* Tuesday */

    if ($day === 2) {

        return [
            'open' => false,
            'reason' =>
                'Both clinics are closed every Tuesday.',
            'slots' => []
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Sankisa Clinic
    |--------------------------------------------------------------------------
    */

    if ($clinic === 'Sankisa Clinic') {

        $start =
            (9 * 60) + 30;

        $end =
            (14 * 60) + 30;
    }


    /*
    |--------------------------------------------------------------------------
    | Mainpuri Clinic
    |--------------------------------------------------------------------------
    */

    elseif ($clinic === 'Mainpuri Clinic') {

        if (
            !in_array(
                $day,
                [3, 6],
                true
            )
        ) {

            return [
                'open' => false,
                'reason' =>
                    'Mainpuri Clinic is open only on Wednesday and Saturday.',
                'slots' => []
            ];
        }


        $start =
            16 * 60;

        $end =
            (18 * 60) + 30;
    }


    else {

        return [
            'open' => false,
            'reason' =>
                'Invalid clinic selected.',
            'slots' => []
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Create 10-minute slots
    |--------------------------------------------------------------------------
    */

    $slots = [];


    for (
        $minutes = $start;
        $minutes < $end;
        $minutes += 10
    ) {

        $startHour =
            intdiv(
                $minutes,
                60
            );

        $startMinute =
            $minutes % 60;


        $endMinutes =
            $minutes + 10;

        $endHour =
            intdiv(
                $endMinutes,
                60
            );

        $endMinute =
            $endMinutes % 60;


        $startTime =
            sprintf(
                '%02d:%02d:00',
                $startHour,
                $startMinute
            );


        $endTime =
            sprintf(
                '%02d:%02d:00',
                $endHour,
                $endMinute
            );


        $slots[] =
            date(
                'h:i A',
                strtotime($startTime)
            )
            .
            ' - '
            .
            date(
                'h:i A',
                strtotime($endTime)
            );
    }


    return [
        'open' => true,
        'reason' => '',
        'slots' => $slots
    ];
}


/*
|--------------------------------------------------------------------------
| Get requested clinic/date
|--------------------------------------------------------------------------
*/

$clinic =
    trim(
        $_GET['clinic'] ?? ''
    );

$date =
    trim(
        $_GET['date'] ?? ''
    );


/*
|--------------------------------------------------------------------------
| Validate date
|--------------------------------------------------------------------------
*/

if (
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date
    )
) {

    echo json_encode([
        'open' => false,
        'reason' => 'Invalid appointment date.',
        'slots' => []
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Holiday table
|--------------------------------------------------------------------------
*/

$conn->query("
CREATE TABLE IF NOT EXISTS clinic_holidays (

    id INT AUTO_INCREMENT PRIMARY KEY,

    holiday_date DATE NOT NULL UNIQUE,

    holiday_name VARCHAR(180) NOT NULL,

    tradition VARCHAR(60)
        DEFAULT 'Clinic Holiday',

    created_at
        TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP

)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
");


/*
|--------------------------------------------------------------------------
| Check holiday
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT holiday_name
        FROM clinic_holidays
        WHERE holiday_date = ?
        LIMIT 1
    ");

$stmt->bind_param(
    's',
    $date
);

$stmt->execute();

$holiday =
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if ($holiday) {

    echo json_encode([

        'open' => false,

        'reason' =>
            'Clinic closed: '
            . $holiday['holiday_name']
            . '.',

        'slots' => []

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Get schedule
|--------------------------------------------------------------------------
*/

$schedule =
    schedule_for(
        $clinic,
        $date
    );


if (!$schedule['open']) {

    echo json_encode(
        $schedule
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Find booked slots
|--------------------------------------------------------------------------
*/

$booked = [];


$stmt =
    $conn->prepare("
        SELECT time
        FROM appointments
        WHERE date = ?
        AND clinic = ?
        AND status IN
        (
            'Pending',
            'Approved',
            'Completed'
        )
    ");


$stmt->bind_param(
    'ss',
    $date,
    $clinic
);

$stmt->execute();

$result =
    $stmt->get_result();


while (
    $row =
    $result->fetch_assoc()
) {

    $booked[] =
        $row['time'];
}


$stmt->close();


/*
|--------------------------------------------------------------------------
| Remove booked slots
|--------------------------------------------------------------------------
*/

$available = [];


foreach (
    $schedule['slots']
    as $slot
) {

    if (
        !in_array(
            $slot,
            $booked,
            true
        )
    ) {

        $available[] =
            $slot;
    }
}


/*
|--------------------------------------------------------------------------
| Send result
|--------------------------------------------------------------------------
*/

echo json_encode([

    'open' => true,

    'reason' => '',

    'slots' => $available,

    'total' =>
        count(
            $schedule['slots']
        ),

    'booked' =>
        count($booked)

]);

?>