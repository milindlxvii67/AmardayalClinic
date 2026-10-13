<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$host = getenv('DB_HOST') ?: 'localhost';

$port = (int) (getenv('DB_PORT') ?: 3306);

$user = getenv('DB_USERNAME') ?: 'root';

$password = getenv('DB_PASSWORD') ?: '';

$database = getenv('DB_NAME') ?: 'amar_dayal_clinic';


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $conn = new mysqli(
        $host,
        $user,
        $password,
        $database,
        $port
    );

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {

    error_log(
        'Database connection failed: ' .
        $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Database connection failed. Please check the database configuration.'
    );
}


/*
|--------------------------------------------------------------------------
| BASE URL
|--------------------------------------------------------------------------
*/

define(
    'BASE_URL',
    '/'
);


/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/

function redirect(string $url): never
{
    header(
        'Location: ' . $url
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

function flash(
    ?string $set = null
): string {

    if ($set !== null) {

        $_SESSION['flash'] = $set;

    }

    $message =
        $_SESSION['flash']
        ?? '';

    unset(
        $_SESSION['flash']
    );

    return $message;
}


/*
|--------------------------------------------------------------------------
| PATIENT LOGIN
|--------------------------------------------------------------------------
*/

function require_patient(): void
{
    if (
        empty(
            $_SESSION['patient_id']
        )
    ) {

        redirect(
            BASE_URL .
            'patient/login.php'
        );

    }
}


/*
|--------------------------------------------------------------------------
| STAFF LOGIN
|--------------------------------------------------------------------------
*/

function require_staff(
    ?array $roles = null
): void {

    if (
        empty(
            $_SESSION['staff_id']
        )
    ) {

        redirect(
            BASE_URL .
            'admin/Login.php'
        );

    }

    if (
        $roles
        && !in_array(
            $_SESSION['staff_role'] ?? '',
            $roles,
            true
        )
    ) {

        http_response_code(403);

        exit(
            'Access denied.'
        );

    }
}


/*
|--------------------------------------------------------------------------
| AUDIT LOG
|--------------------------------------------------------------------------
*/

function audit(
    string $action,
    string $details = ''
): void {

    global $conn;

    $username =
        $_SESSION['staff_username']
        ?? 'system';

    $s = $conn->prepare("
        INSERT INTO audit_log
        (
            username,
            action,
            details,
            timestamp
        )
        VALUES
        (
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    if (!$s) {
        return;
    }

    $s->bind_param(
        'sss',
        $username,
        $action,
        $details
    );

    $s->execute();

    $s->close();
}


/*
|--------------------------------------------------------------------------
| WEBSITE CONTENT
|--------------------------------------------------------------------------
*/

function site_content(
    string $key,
    string $fallback = ''
): string {

    global $conn;

    $s = $conn->prepare("
        SELECT content_value
        FROM site_content
        WHERE content_key = ?
        LIMIT 1
    ");

    if (!$s) {
        return $fallback;
    }

    $s->bind_param(
        's',
        $key
    );

    $s->execute();

    $result =
        $s->get_result()
          ->fetch_assoc();

    $s->close();

    return
        $result['content_value']
        ?? $fallback;
}


/*
|--------------------------------------------------------------------------
| REGISTRATION NUMBER
|--------------------------------------------------------------------------
*/

function next_code(
    string $table,
    string $column,
    int $start
): int {

    global $conn;

    /*
     * These table/column values are controlled
     * by our own application.
     */

    $query =
        "SELECT MAX($column) AS m
         FROM $table";

    $q =
        $conn->query($query);

    if (!$q) {
        return $start;
    }

    $row =
        $q->fetch_assoc();

    return
        !empty($row['m'])
        ? ((int) $row['m'] + 1)
        : $start;
}


/*
|--------------------------------------------------------------------------
| PUBLIC NAVBAR
|--------------------------------------------------------------------------
*/

function nav(
    string $root = ''
): void {

    include
        __DIR__
        . '/'
        . $root
        . 'navbar.php';

}

?>