<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
| Completely clear the current session and return the staff member
| to the Admin Login page.
*/

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

redirect(BASE_URL . 'admin/Login.php');
