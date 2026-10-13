<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| AmarDayal Clinic - Staff Login
|--------------------------------------------------------------------------
| Uses the actual database structure:
|
| users
|   id
|   username
|   password
|   role
|   name
|   email
|   phone
|   status
|   created_at
|
| Existing accounts:
|   admin     / admin123
|   doctor    / doctor123
|   reception / reception123
|
| Newer accounts may contain a password_hash() value in the password
| column. This login supports BOTH existing plain-text passwords and
| secure password hashes, and automatically upgrades plain-text passwords
| after a successful login.
|--------------------------------------------------------------------------
*/

/* Already logged in? */
if (
    !empty($_SESSION['staff_logged_in']) &&
    !empty($_SESSION['staff_role'])
) {
    redirect(BASE_URL . 'admin/index.php');
}

$error = '';
$username = '';

if (empty($_SESSION['staff_login_csrf'])) {
    $_SESSION['staff_login_csrf'] = bin2hex(random_bytes(32));
}
$loginCsrf = $_SESSION['staff_login_csrf'];

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (
        $submittedCsrf === '' ||
        !hash_equals($_SESSION['staff_login_csrf'] ?? '', $submittedCsrf)
    ) {
        $error = 'Your login session has expired. Please refresh the page and try again.';
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($error !== '') {
        // Stop processing this request after a failed CSRF check.
    } elseif ($username === '' || $password === '') {

        $error = 'Please enter your username and password.';

    } else {

        /*
        |------------------------------------------------------------------
        | IMPORTANT:
        | Your actual database uses the column "password", not
        | "password_hash".
        |------------------------------------------------------------------
        */
        $stmt = $conn->prepare(
            "SELECT
                id,
                username,
                password,
                role,
                name,
                email,
                phone,
                status
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        if (!$stmt) {

            $error = 'Unable to connect to the user account database.';

        } else {

            $stmt->bind_param('s', $username);

            if (!$stmt->execute()) {

                $error = 'Unable to verify the account.';

                $stmt->close();

            } else {

                $result = $stmt->get_result();
                $user = $result ? $result->fetch_assoc() : null;

                $stmt->close();

                if (!$user) {

                    $error = 'Invalid username or password.';

                } elseif (
                    strtolower(trim((string)($user['status'] ?? ''))) !== 'active'
                ) {

                    $error = 'This account is inactive. Please contact the System Administrator.';

                } else {

                    $storedPassword = (string)($user['password'] ?? '');
                    $passwordValid = false;

                    /*
                    |----------------------------------------------------------
                    | 1. Secure hashed password
                    |----------------------------------------------------------
                    */
                    if (
                        $storedPassword !== '' &&
                        password_get_info($storedPassword)['algo'] !== 0
                    ) {

                        $passwordValid = password_verify(
                            $password,
                            $storedPassword
                        );

                    }

                    /*
                    |----------------------------------------------------------
                    | 2. Existing plain-text password
                    |
                    | Your current database contains:
                    | admin     -> admin123
                    | doctor    -> doctor123
                    | reception -> reception123
                    |
                    | Support these existing records so you do not have to
                    | manually change them in phpMyAdmin.
                    |----------------------------------------------------------
                    */
                    if (!$passwordValid) {

                        $passwordValid = hash_equals(
                            $storedPassword,
                            $password
                        );

                    }

                    if (!$passwordValid) {

                        $error = 'Invalid username or password.';

                    } else {

                        /*
                        |------------------------------------------------------
                        | Upgrade an old plain-text password to a secure hash.
                        | This happens only after the correct password is
                        | supplied.
                        |------------------------------------------------------
                        */
                        if (
                            $storedPassword !== '' &&
                            password_get_info($storedPassword)['algo'] === 0
                        ) {

                            $newHash = password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );

                            if ($newHash !== false) {

                                $update = $conn->prepare(
                                    "UPDATE users
                                     SET password = ?
                                     WHERE id = ?
                                     LIMIT 1"
                                );

                                if ($update) {

                                    $userId = (int)$user['id'];

                                    $update->bind_param(
                                        'si',
                                        $newHash,
                                        $userId
                                    );

                                    $update->execute();
                                    $update->close();
                                }
                            }
                        }

                        /*
                        |------------------------------------------------------
                        | Normalize the database role for the existing
                        | application session system.
                        |------------------------------------------------------
                        */
                        $dbRole = strtolower(
                            trim((string)($user['role'] ?? ''))
                        );

                        $sessionRoleMap = [
                            'admin'     => 'Admin',
                            'doctor'    => 'Doctor',
                            'reception' => 'Reception'
                        ];

                        if (!isset($sessionRoleMap[$dbRole])) {

                            $error = 'This account has an invalid staff role. Please contact the System Administrator.';

                        } else {

                            $sessionRole =
                                $sessionRoleMap[$dbRole];

                            /*
                            |--------------------------------------------------
                            | Clear conflicting old sessions.
                            |--------------------------------------------------
                            */
                            unset(
                                $_SESSION['admin'],
                                $_SESSION['user'],
                                $_SESSION['patient']
                            );

                            /*
                            |--------------------------------------------------
                            | New secure staff session.
                            |--------------------------------------------------
                            */
                            session_regenerate_id(true);

                            $_SESSION['staff_logged_in'] = true;

                            $_SESSION['staff_id'] =
                                (int)$user['id'];

                            $_SESSION['staff_username'] =
                                (string)$user['username'];

                            $_SESSION['staff_name'] =
                                trim((string)($user['name'] ?? ''))
                                ?: (string)$user['username'];

                            $_SESSION['staff_role'] =
                                $sessionRole;

                            $_SESSION['staff_email'] =
                                (string)($user['email'] ?? '');

                            $_SESSION['staff_phone'] =
                                (string)($user['phone'] ?? '');

                            $_SESSION['staff_login_csrf'] = bin2hex(random_bytes(32));

                            /*
                            |--------------------------------------------------
                            | Redirect to the existing admin dashboard.
                            |--------------------------------------------------
                            */
                            redirect(
                                BASE_URL . 'admin/index.php'
                            );
                        }
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Admin Login | AmarDayal Clinic</title>

<link
    rel="stylesheet"
    href="<?= e(BASE_URL) ?>Styles/style.css"
>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;

    font-family:
        "Segoe UI",
        Arial,
        sans-serif;

    background:
        linear-gradient(
            135deg,
            #eef6ff,
            #f7fbff,
            #ecfdf8
        );

    color: #10233f;
}

.login-wrapper {
    min-height: 100vh;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 110px 30px 30px;
}

.login-card {
    width: 100%;
    max-width: 450px;

    padding: 42px;

    background: rgba(255,255,255,.97);

    border: 1px solid #e2e8f0;
    border-radius: 26px;

    box-shadow:
        0 30px 80px
        rgba(15,23,42,.12);
}

.clinic-logo {
    display: flex;
    justify-content: center;
    align-items: center;

    margin-bottom: 18px;
}

.clinic-logo img {
    width: 110px;
    height: 110px;

    object-fit: contain;
    display: block;
}

.login-card h1 {
    margin: 0;

    text-align: center;

    font-size: 29px;
}

.login-subtitle {
    margin: 8px 0 30px;

    text-align: center;

    color: #64748b;

    font-size: 14px;
    line-height: 1.6;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;

    margin-bottom: 8px;

    font-size: 13px;
    font-weight: 800;
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 54px;
}

.form-group input {
    width: 100%;
    height: 50px;

    padding: 0 15px;

    border: 1px solid #dbe3ee;
    border-radius: 12px;

    background: #f8fafc;

    outline: none;

    font-size: 15px;

    transition: .2s;
}

.form-group input:focus {
    border-color: #2563eb;

    background: #fff;

    box-shadow:
        0 0 0 4px
        rgba(37,99,235,.1);
}

/*
|--------------------------------------------------------------------------
| Password show/hide button
|--------------------------------------------------------------------------
*/
.password-toggle {
    position: absolute;

    right: 10px;
    top: 50%;

    transform: translateY(-50%);

    width: 38px;
    height: 38px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 0;
    border-radius: 10px;

    background: transparent;

    color: #64748b;

    font-size: 19px;

    cursor: pointer;

    transition: .2s;
}

.password-toggle:hover {
    background: #eaf1ff;
    color: #2563eb;
}

.login-button {
    width: 100%;
    height: 52px;

    margin-top: 8px;

    border: 0;
    border-radius: 13px;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #1d4ed8
        );

    color: white;

    font-size: 15px;
    font-weight: 800;

    cursor: pointer;

    transition: .2s;
}

.login-button:hover {
    transform: translateY(-2px);

    box-shadow:
        0 12px 25px
        rgba(37,99,235,.25);
}

.error-message {
    margin-bottom: 20px;

    padding: 12px 14px;

    border: 1px solid #fecaca;
    border-radius: 10px;

    background: #fef2f2;

    color: #b91c1c;

    font-size: 13px;
    font-weight: 700;
}

@media (max-width: 520px) {

    .login-wrapper {
        padding: 95px 18px 18px;
    }

    .login-card {
        padding: 30px 24px;
        border-radius: 20px;
    }

}

</style>

</head>

<body>

<?php nav(); ?>

<div class="login-wrapper">

    <div class="login-card">

        <div class="clinic-logo">

            <img
                src="../img/logo.png"
                alt="AmarDayal Clinic Logo"
                width="110"
                height="110"
            >

        </div>

        <h1>
            Admin Portal
        </h1>

        <p class="login-subtitle">

            AmarDayal Clinic
            <br>

            Joint &amp; Pain Management Center

        </p>

        <?php if ($error !== ''): ?>

            <div class="error-message">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>

        <form
            method="POST"
            autocomplete="off"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($loginCsrf) ?>"
            >

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars(
                        $username,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    placeholder="Enter username"
                    autocomplete="username"
                    required
                    autofocus
                >

            </div>

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                        aria-label="Show password"
                        title="Show password"
                    >
                        👁️
                    </button>

                </div>

            </div>

            <button
                type="submit"
                class="login-button"
            >
                ← Sign In to Admin Dashboard →
            </button>

        </form>

    </div>

</div>

<script>

(function () {

    const password =
        document.getElementById('password');

    const toggle =
        document.getElementById('passwordToggle');

    if (!password || !toggle) {
        return;
    }

    toggle.addEventListener('click', function () {

        const isHidden =
            password.type === 'password';

        password.type =
            isHidden
                ? 'text'
                : 'password';

        toggle.textContent =
            isHidden
                ? '🙈'
                : '👁️';

        toggle.setAttribute(
            'aria-label',
            isHidden
                ? 'Hide password'
                : 'Show password'
        );

        toggle.setAttribute(
            'title',
            isHidden
                ? 'Hide password'
                : 'Show password'
        );

    });

})();

</script>

</body>
</html>
