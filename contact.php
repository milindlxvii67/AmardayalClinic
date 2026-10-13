<?php
require_once 'config.php';

$msg = '';
$msgType = 'success';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* CSRF token */
if (empty($_SESSION['contact_csrf'])) {
    $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['contact_csrf'], $token)) {
        $msg = 'Your session has expired. Please refresh the page and try again.';
        $msgType = 'error';
    } else {
        $n  = trim($_POST['name'] ?? '');
        $em = trim($_POST['email'] ?? '');
        $ph = trim($_POST['phone'] ?? '');
        $m  = trim($_POST['message'] ?? '');

        if (
            $n !== '' &&
            mb_strlen($n) <= 100 &&
            filter_var($em, FILTER_VALIDATE_EMAIL) &&
            mb_strlen($em) <= 150 &&
            mb_strlen($ph) <= 30 &&
            $m !== '' &&
            mb_strlen($m) <= 5000
        ) {
            $s = $conn->prepare(
                'INSERT INTO messages(name,email,phone,message) VALUES(?,?,?,?)'
            );

            if ($s) {
                $s->bind_param('ssss', $n, $em, $ph, $m);

                if ($s->execute()) {
                    $msg = 'Your message has been sent successfully.';
                    $msgType = 'success';
                    $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
                } else {
                    $msg = 'Unable to send your message right now. Please try again later.';
                    $msgType = 'error';
                }

                $s->close();
            } else {
                $msg = 'Unable to process your message right now. Please try again later.';
                $msgType = 'error';
            }
        } else {
            $msg = 'Please enter a valid name, email, message, and a valid phone number if provided.';
            $msgType = 'error';
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contact</title>
<link rel="stylesheet" href="Styles/style.css">
</head>
<body>
<?php nav(); ?>

<section class="section">
    <div class="section-title">
        <h2>Contact Us</h2>
        <p><?= e(site_content('contact_address')) ?></p>
        <p><?= e(site_content('contact_phone')) ?> · <?= e(site_content('contact_hours')) ?></p>
    </div>

    <div class="card form-card">
        <?php if ($msg): ?>
            <div class="alert <?= $msgType === 'success' ? 'success' : 'error' ?>">
                <?= e($msg) ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['contact_csrf']) ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="contact-name">Name</label>
                    <input id="contact-name" name="name" maxlength="100" required autocomplete="name">
                </div>

                <div class="field">
                    <label for="contact-email">Email</label>
                    <input id="contact-email" type="email" name="email" maxlength="150" required autocomplete="email">
                </div>

                <div class="field">
                    <label for="contact-phone">Phone</label>
                    <input id="contact-phone" name="phone" maxlength="30" autocomplete="tel">
                </div>

                <div class="field full">
                    <label for="contact-message">Message</label>
                    <textarea id="contact-message" name="message" rows="5" maxlength="5000" required></textarea>
                </div>
            </div>

            <br>
            <button class="btn" type="submit">Send Message</button>
        </form>
    </div>
</section>

<footer class="footer">© <?= date('Y') ?> AmarDayal Clinic</footer>
</body>
</html>
