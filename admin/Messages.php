<?php
require_once '../config.php';
require_staff();

$role = $_SESSION['staff_role'] ?? '';
$staffUsername = $_SESSION['staff_username'] ?? '';
$staffName = $_SESSION['staff_name'] ?? $staffUsername ?: 'Staff';

function msg_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Messages / enquiries
|--------------------------------------------------------------------------
| Uses the existing online `messages` table.
| The live schema contains:
| id, name, email, phone, message, date, status, priority
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['messages_csrf'])) {
    $_SESSION['messages_csrf'] = bin2hex(random_bytes(32));
}

function messages_csrf(): string
{
    return $_SESSION['messages_csrf'];
}

function verify_messages_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        $token === '' ||
        empty($_SESSION['messages_csrf']) ||
        !hash_equals($_SESSION['messages_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid security token.');
    }
}

$success = '';
$error = '';

/* ------------------------------------------------------------------
   POST ACTIONS
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_messages_csrf();
    $action = $_POST['action'] ?? '';

    /* Mark read */
    if ($action === 'mark_read') {

        $messageId = (int)($_POST['message_id'] ?? 0);

        if ($messageId <= 0) {
            $error = 'Invalid message.';
        } else {

            $stmt = $conn->prepare(
                "UPDATE messages
                 SET status = CASE
                    WHEN status = 'Unread' THEN 'Read'
                    ELSE status
                 END
                 WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $messageId);

                if ($stmt->execute()) {
                    $success = 'Message marked as read.';
                } else {
                    $error = 'Unable to update the message.';
                }

                $stmt->close();
            }
        }
    }

    /* Reply */
    elseif ($action === 'reply') {

        $messageId = (int)($_POST['message_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');

        if ($messageId <= 0) {
            $error = 'Invalid message.';
        } elseif ($reply === '') {
            $error = 'Please enter a reply.';
        } elseif (mb_strlen($reply) > 10000) {
            $error = 'Reply is too long.';
        } else {

            $stmt = $conn->prepare(
                "SELECT name, email, phone, subject, priority
                 FROM messages
                 WHERE id = ?
                 LIMIT 1"
            );

            $original = null;

            if ($stmt) {
                $stmt->bind_param('i', $messageId);
                $stmt->execute();
                $original = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            if (!$original) {
                $error = 'Message not found.';
            } else {

                /*
                 * The live messages table has no reply/replied_by/replied_at
                 * columns. Store the clinic reply as a new message and mark
                 * the original message as Replied.
                 */
                $replySubject = 'Re: ' . (string)$original['subject'];
                $replyName = $staffName;
                $replyEmail = '';
                $replyPhone = '';

                $insert = $conn->prepare(
                    "INSERT INTO messages
                        (name, email, phone, message, status, priority)
                     VALUES (?, ?, ?, ?, 'Replied', ?)"
                );

                if ($insert) {
                    $insert->bind_param(
                        'sssss',
                        $replyName,
                        $replyEmail,
                        $replyPhone,
                        $reply,
                        $original['priority']
                    );

                    if ($insert->execute()) {
                        $insert->close();

                        $update = $conn->prepare(
                            "UPDATE messages SET status = 'Replied' WHERE id = ?"
                        );

                        if ($update) {
                            $update->bind_param('i', $messageId);
                            $update->execute();
                            $update->close();
                        }

                        $success = 'Reply saved successfully.';

                        if (function_exists('audit')) {
                            audit(
                                $staffUsername ?: $staffName,
                                'REPLY_MESSAGE',
                                'Message ID ' . $messageId
                            );
                        }
                    } else {
                        $error = 'Unable to save the reply.';
                        $insert->close();
                    }
                } else {
                    $error = 'Unable to save the reply.';
                }
            }
        }
    }

    /* Priority */
    elseif ($action === 'priority') {

        $messageId = (int)($_POST['message_id'] ?? 0);
        $priority = trim($_POST['priority'] ?? 'Normal');

        if ($messageId <= 0) {
            $error = 'Invalid message.';
        } elseif (!in_array($priority, ['Normal','High','Urgent'], true)) {
            $error = 'Invalid priority.';
        } else {

            $stmt = $conn->prepare(
                "UPDATE messages SET priority = ? WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('si', $priority, $messageId);

                if ($stmt->execute()) {
                    $success = 'Priority updated.';
                } else {
                    $error = 'Unable to update priority.';
                }

                $stmt->close();
            }
        }
    }

    /* Archive */
    elseif ($action === 'archive') {

        $messageId = (int)($_POST['message_id'] ?? 0);

        if ($messageId <= 0) {
            $error = 'Invalid message.';
        } else {

            $stmt = $conn->prepare(
                "UPDATE messages SET status = 'Archived' WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $messageId);

                if ($stmt->execute()) {
                    $success = 'Message archived.';
                } else {
                    $error = 'Unable to archive the message.';
                }

                $stmt->close();
            }
        }
    }

    /* Restore */
    elseif ($action === 'restore') {

        $messageId = (int)($_POST['message_id'] ?? 0);

        if ($messageId <= 0) {
            $error = 'Invalid message.';
        } else {

            $stmt = $conn->prepare(
                "UPDATE messages SET status = 'Read' WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $messageId);

                if ($stmt->execute()) {
                    $success = 'Message restored.';
                } else {
                    $error = 'Unable to restore the message.';
                }

                $stmt->close();
            }
        }
    }

    /* Delete */
    elseif ($action === 'delete') {

        $messageId = (int)($_POST['message_id'] ?? 0);

        if ($messageId <= 0) {
            $error = 'Invalid message.';
        } elseif ($role !== 'Admin') {
            $error = 'Only an Administrator can permanently delete messages.';
        } else {

            $stmt = $conn->prepare(
                "DELETE FROM messages WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $messageId);

                if ($stmt->execute()) {
                    $success = 'Message permanently deleted.';

                    if (function_exists('audit')) {
                        audit(
                            $staffUsername ?: $staffName,
                            'DELETE_MESSAGE',
                            'Message ID ' . $messageId
                        );
                    }
                } else {
                    $error = 'Unable to delete the message.';
                }

                $stmt->close();
            }
        }
    }

    /* Add internal message */
    elseif ($action === 'add_message') {

        $senderName = trim($_POST['sender_name'] ?? '');
        $senderPhone = trim($_POST['sender_phone'] ?? '');
        $senderEmail = trim($_POST['sender_email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $messageType = trim($_POST['message_type'] ?? 'Internal');
        $priority = trim($_POST['priority'] ?? 'Normal');

        if ($senderName === '') {
            $error = 'Please enter the sender name.';
        } elseif ($subject === '') {
            $error = 'Please enter a subject.';
        } elseif ($message === '') {
            $error = 'Please enter the message.';
        } elseif (!in_array($priority, ['Normal','High','Urgent'], true)) {
            $error = 'Invalid priority.';
        } else {

            $stmt = $conn->prepare(
                "INSERT INTO messages
                    (name, email, phone, message, status, priority)
                 VALUES (?, ?, ?, ?, 'Unread', ?)"
            );

            if ($stmt) {

                /*
                 * The live messages table has no subject/message_type
                 * columns in the database currently used by Wasmer.
                 * Keep the subject in the message body so the information
                 * is not lost.
                 */
                $storedMessage = $subject !== ''
                    ? '[' . $subject . '] ' . $message
                    : $message;

                $stmt->bind_param(
                    'sssss',
                    $senderName,
                    $senderEmail,
                    $senderPhone,
                    $storedMessage,
                    $priority
                );

                if ($stmt->execute()) {
                    $success = 'Message created successfully.';

                    if (function_exists('audit')) {
                        audit(
                            $staffUsername ?: $staffName,
                            'CREATE_MESSAGE',
                            'Created internal message: ' . $subject
                        );
                    }
                } else {
                    $error = 'Unable to create the message.';
                }

                $stmt->close();
            }
        }
    }
}

/* ------------------------------------------------------------------
   FILTERS
------------------------------------------------------------------ */
$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['q'] ?? '');

$allowedFilters = [
    'all',
    'unread',
    'read',
    'replied',
    'high',
    'urgent',
    'archived'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$where = [];
$params = [];
$types = '';

if ($filter === 'unread') {
    $where[] = "status = 'Unread'";
} elseif ($filter === 'read') {
    $where[] = "status = 'Read'";
} elseif ($filter === 'replied') {
    $where[] = "status = 'Replied'";
} elseif ($filter === 'high') {
    $where[] = "priority = 'High'";
} elseif ($filter === 'urgent') {
    $where[] = "priority = 'Urgent'";
} elseif ($filter === 'archived') {
    $where[] = "status = 'Archived'";
} else {
    $where[] = "status <> 'Archived'";
}

if ($search !== '') {

    $like = '%' . $search . '%';

    $where[] = "(
        name LIKE ?
        OR phone LIKE ?
        OR email LIKE ?
        OR message LIKE ?
        OR message LIKE ?
    )";

    $params = [
        $like,
        $like,
        $like,
        $like,
        $like
    ];

    $types = 'sssss';
}

$sql =
    "SELECT *
     FROM messages
     WHERE " .
    implode(' AND ', $where) .
    "
     ORDER BY
        CASE priority
            WHEN 'Urgent' THEN 1
            WHEN 'High' THEN 2
            ELSE 3
        END,
        CASE status
            WHEN 'Unread' THEN 1
            WHEN 'Read' THEN 2
            WHEN 'Replied' THEN 3
            ELSE 4
        END,
        date DESC
     LIMIT 250";

$stmt = $conn->prepare($sql);

$messages = [];

if ($stmt) {

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['sender_name'] = $row['name'] ?? '';
            $row['sender_phone'] = $row['phone'] ?? '';
            $row['sender_email'] = $row['email'] ?? '';
            $row['subject'] = '';
            $row['message_type'] = 'Message';
            $row['created_at'] = $row['date'] ?? '';
            $row['reply'] = '';
            $row['replied_by'] = '';
            $row['replied_at'] = '';
            $messages[] = $row;
        }
    }

    $stmt->close();
}

/* Statistics */
$stats = [
    'total' => 0,
    'unread' => 0,
    'high' => 0,
    'urgent' => 0,
    'replied' => 0
];

$result = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'Unread') AS unread,
        SUM(priority = 'High' AND status <> 'Archived') AS high,
        SUM(priority = 'Urgent' AND status <> 'Archived') AS urgent,
        SUM(status = 'Replied') AS replied
     FROM messages
     WHERE status <> 'Archived'"
);

if ($result) {
    $row = $result->fetch_assoc();

    foreach ($stats as $key => $value) {
        $stats[$key] = (int)($row[$key] ?? 0);
    }
}

/* Optional patient/appointment details for selected message */
$selectedId = (int)($_GET['id'] ?? 0);
$selectedMessage = null;

if ($selectedId > 0) {

    $stmt = $conn->prepare(
        "SELECT *
         FROM messages
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $selectedId);
        $stmt->execute();
        $selectedMessage = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($selectedMessage) {
            $selectedMessage['sender_name'] = $selectedMessage['name'] ?? '';
            $selectedMessage['sender_phone'] = $selectedMessage['phone'] ?? '';
            $selectedMessage['sender_email'] = $selectedMessage['email'] ?? '';
            $selectedMessage['subject'] = '';
            $selectedMessage['message_type'] = 'Message';
            $selectedMessage['created_at'] = $selectedMessage['date'] ?? '';
            $selectedMessage['reply'] = '';
            $selectedMessage['replied_by'] = '';
            $selectedMessage['replied_at'] = '';
        }
    }

    if (
        $selectedMessage &&
        $selectedMessage['status'] === 'Unread'
    ) {
        $stmt = $conn->prepare(
            "UPDATE messages SET status = 'Read' WHERE id = ?"
        );

        if ($stmt) {
            $stmt->bind_param('i', $selectedId);
            $stmt->execute();
            $stmt->close();

            $selectedMessage['status'] = 'Read';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages | AmarDayal Clinic</title>
<link rel="stylesheet" href="<?= msg_e(BASE_URL) ?>Styles/style.css">

<style>
*{box-sizing:border-box}

html,body{
    width:100%;
    min-height:100%;
    margin:0;
    padding:0;
    overflow-x:hidden;
}

body{
    background:#f4f7fb;
    color:#10233f;
    font-family:"Segoe UI",Arial,sans-serif;
}

/* =========================================================
   SHARED ADMIN SIDEBAR / CONTENT LAYOUT
   sidebar.php is the single shared sidebar.
   ========================================================= */

.layout{
    display:block !important;
    position:relative;
    width:100%;
    min-height:100vh;
    margin:0;
    padding:0;
}

/*
 * The shared admin sidebar is 290px wide.
 * Reserve its complete width so Messages never renders
 * underneath the sidebar.
 */

.layout > .main{
    position:relative;
    display:block;
    width:calc(100% - 290px) !important;
    max-width:none !important;
    min-width:0;
    min-height:100vh;
    margin-left:290px !important;
    padding:26px;
    box-sizing:border-box;
    overflow:visible;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px
}

.header h1{
    margin:0;
    font-size:30px
}

.header p{
    color:#64748b;
    margin:6px 0 0
}

.staff-box{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:12px 16px;
    color:#64748b;
    font-size:12px
}

.staff-box strong{
    display:block;
    color:#10233f;
    margin-top:3px
}

.alert{
    padding:13px 16px;
    border-radius:13px;
    margin-bottom:18px;
    font-weight:700
}

.success{
    background:#ecfdf5;
    border:1px solid #a7f3d0;
    color:#047857
}

.error{
    background:#fef2f2;
    border:1px solid #fecaca;
    color:#b91c1c
}

.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:13px;
    margin-bottom:18px
}

.stat{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:17px;
    box-shadow:0 5px 18px rgba(15,23,42,.05)
}

.stat span{
    display:block;
    color:#64748b;
    font-size:10px;
    text-transform:uppercase;
    font-weight:900
}

.stat strong{
    display:block;
    font-size:26px;
    margin-top:5px
}

.toolbar{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:14px;
    display:flex;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px
}

.filters{
    display:flex;
    gap:7px;
    flex-wrap:wrap
}

.filter{
    padding:8px 11px;
    border-radius:9px;
    text-decoration:none;
    background:#f1f5f9;
    color:#475569;
    font-size:12px;
    font-weight:900
}

.filter.active{
    background:#2563eb;
    color:#fff
}

.search{
    display:flex;
    gap:7px;
    min-width:300px
}

.search input{
    min-width:240px
}

input,select,textarea{
    border:1px solid #cbd5e1;
    border-radius:10px;
    padding:10px 11px;
    font:inherit;
    color:#10233f;
    background:#fff
}

input:focus,select:focus,textarea:focus{
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.1)
}

.btn{
    border:0;
    border-radius:9px;
    padding:9px 12px;
    cursor:pointer;
    text-decoration:none;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center
}

.primary{background:#2563eb;color:#fff}
.secondary{background:#f1f5f9;color:#334155}
.green{background:#059669;color:#fff}
.red{background:#fef2f2;color:#b91c1c}
.orange{background:#fff7ed;color:#c2410c}

.grid{
    display:grid;
    grid-template-columns:minmax(0,1.55fr) minmax(330px,.8fr);
    gap:18px;
    align-items:start
}

.card{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:18px;
    box-shadow:0 5px 18px rgba(15,23,42,.05);
    overflow:hidden
}

.card-head{
    padding:17px 19px;
    border-bottom:1px solid #edf2f7;
    display:flex;
    justify-content:space-between;
    align-items:center
}

.card-head h2{
    margin:0;
    font-size:18px
}

.card-head small{
    color:#64748b
}

.message-list{
    display:flex;
    flex-direction:column
}

.message-item{
    display:block;
    text-decoration:none;
    color:inherit;
    padding:17px 19px;
    border-bottom:1px solid #edf2f7
}

.message-item:hover{
    background:#f8fafc
}

.message-item.unread{
    border-left:4px solid #2563eb;
    padding-left:15px;
    background:#f8fbff
}

.message-top{
    display:flex;
    justify-content:space-between;
    gap:15px
}

.sender{
    font-weight:900
}

.subject{
    margin:5px 0 3px;
    font-weight:800
}

.preview{
    color:#64748b;
    font-size:13px;
    line-height:1.5
}

.meta{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    margin-top:9px
}

.badge{
    display:inline-flex;
    padding:4px 8px;
    border-radius:999px;
    font-size:9px;
    font-weight:900
}

.badge.normal{background:#f1f5f9;color:#475569}
.badge.high{background:#fff7ed;color:#c2410c}
.badge.urgent{background:#fef2f2;color:#b91c1c}
.badge.unread{background:#eff6ff;color:#1d4ed8}
.badge.read{background:#f1f5f9;color:#475569}
.badge.replied{background:#ecfdf5;color:#047857}
.badge.archived{background:#e2e8f0;color:#475569}

.detail{
    padding:20px
}

.detail-title{
    margin:0 0 7px;
    font-size:21px
}

.detail-meta{
    color:#64748b;
    font-size:12px;
    line-height:1.7;
    margin-bottom:18px
}

.detail-message{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:15px;
    white-space:pre-wrap;
    line-height:1.6;
    font-size:14px
}

.reply-box{
    margin-top:18px
}

.reply-box textarea{
    width:100%;
    min-height:130px;
    resize:vertical
}

.reply-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:9px
}

.side-section{
    padding:18px;
    border-bottom:1px solid #edf2f7
}

.side-section:last-child{
    border-bottom:0
}

.side-section h3{
    margin:0 0 12px;
    font-size:15px
}

.form-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px
}

.field{
    margin-bottom:10px
}

.field label{
    display:block;
    font-size:11px;
    font-weight:900;
    margin-bottom:5px
}

.field input,
.field select,
.field textarea{
    width:100%
}

.field textarea{
    min-height:110px;
    resize:vertical
}

.empty{
    padding:45px 20px;
    text-align:center;
    color:#64748b
}

@media(max-width:1100px){

    .stats{grid-template-columns:repeat(2,1fr)}
    .grid{grid-template-columns:1fr}

    .layout > .main{
        width:calc(100% - 290px) !important;
        margin-left:290px !important;
    }
}

@media(max-width:700px){

    .layout{
        display:block !important;
    }

    .layout > .main{
        width:100% !important;
        margin-left:0 !important;
        min-height:auto;
        padding:18px;
    }

    .stats{grid-template-columns:1fr}

    .header{
        flex-direction:column;
    }

    .search{
        min-width:0;
        width:100%;
    }

    .search input{
        min-width:0;
        width:100%;
    }

    .form-row{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<div class="layout">

<?php require_once __DIR__ . '/sidebar.php'; ?>

<main class="main">

<div class="header">

    <div>
        <h1>Messages & Enquiries</h1>
        <p>Review patient enquiries, follow-ups and internal messages.</p>
    </div>

    <div class="staff-box">
        Signed in as
        <strong><?= msg_e($staffName) ?></strong>
    </div>

</div>

<?php if ($success !== ''): ?>
    <div class="alert success"><?= msg_e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert error"><?= msg_e($error) ?></div>
<?php endif; ?>

<section class="stats">

    <div class="stat">
        <span>Active Messages</span>
        <strong><?= number_format($stats['total']) ?></strong>
    </div>

    <div class="stat">
        <span>Unread</span>
        <strong><?= number_format($stats['unread']) ?></strong>
    </div>

    <div class="stat">
        <span>High Priority</span>
        <strong><?= number_format($stats['high']) ?></strong>
    </div>

    <div class="stat">
        <span>Urgent</span>
        <strong><?= number_format($stats['urgent']) ?></strong>
    </div>

    <div class="stat">
        <span>Replied</span>
        <strong><?= number_format($stats['replied']) ?></strong>
    </div>

</section>

<div class="toolbar">

    <div class="filters">

        <a
            class="filter <?= $filter === 'all' ? 'active' : '' ?>"
            href="Messages.php"
        >All</a>

        <a
            class="filter <?= $filter === 'unread' ? 'active' : '' ?>"
            href="Messages.php?filter=unread"
        >Unread</a>

        <a
            class="filter <?= $filter === 'read' ? 'active' : '' ?>"
            href="Messages.php?filter=read"
        >Read</a>

        <a
            class="filter <?= $filter === 'replied' ? 'active' : '' ?>"
            href="Messages.php?filter=replied"
        >Replied</a>

        <a
            class="filter <?= $filter === 'high' ? 'active' : '' ?>"
            href="Messages.php?filter=high"
        >High</a>

        <a
            class="filter <?= $filter === 'urgent' ? 'active' : '' ?>"
            href="Messages.php?filter=urgent"
        >Urgent</a>

        <a
            class="filter <?= $filter === 'archived' ? 'active' : '' ?>"
            href="Messages.php?filter=archived"
        >Archived</a>

    </div>

    <form class="search" method="get">

        <input
            type="hidden"
            name="filter"
            value="<?= msg_e($filter) ?>"
        >

        <input
            type="search"
            name="q"
            value="<?= msg_e($search) ?>"
            placeholder="Search messages..."
        >

        <button class="btn primary" type="submit">
            Search
        </button>

    </form>

</div>

<div class="grid">

<section class="card">

    <div class="card-head">

        <div>
            <h2>Inbox</h2>
            <small><?= number_format(count($messages)) ?> message(s) shown</small>
        </div>

        <a class="btn secondary" href="Messages.php">
            ↻ Refresh
        </a>

    </div>

    <div class="message-list">

    <?php if (!$messages): ?>

        <div class="empty">
            <div style="font-size:34px;margin-bottom:8px">✉</div>
            <strong>No messages found</strong>
            <div style="margin-top:5px">
                Try another filter or create an internal message.
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($messages as $message): ?>

            <?php
                $priorityClass = strtolower($message['priority']);
                $statusClass = strtolower($message['status']);
                $preview = trim((string)$message['message']);

                if (strlen($preview) > 150) {
                    $preview = substr($preview, 0, 150) . '…';
                }
            ?>

            <a
                class="message-item <?= $message['status'] === 'Unread' ? 'unread' : '' ?>"
                href="Messages.php?id=<?= (int)$message['id'] ?>&filter=<?= urlencode($filter) ?>"
            >

                <div class="message-top">

                    <div class="sender">
                        <?= msg_e($message['sender_name']) ?>
                    </div>

                    <small style="color:#64748b">
                        <?= msg_e(date('d M Y, h:i A', strtotime((string)$message['created_at']))) ?>
                    </small>

                </div>

                <div class="subject">
                    <?= msg_e($message['subject']) ?>
                </div>

                <div class="preview">
                    <?= msg_e($preview) ?>
                </div>

                <div class="meta">

                    <span class="badge <?= $priorityClass ?>">
                        <?= msg_e($message['priority']) ?>
                    </span>

                    <span class="badge <?= $statusClass ?>">
                        <?= msg_e($message['status']) ?>
                    </span>

                    <span class="badge normal">
                        <?= msg_e($message['message_type']) ?>
                    </span>

                </div>

            </a>

        <?php endforeach; ?>

    <?php endif; ?>

    </div>

</section>

<aside class="card">

<?php if ($selectedMessage): ?>

    <div class="card-head">

        <div>
            <h2>Message Details</h2>
            <small>Message #<?= (int)$selectedMessage['id'] ?></small>
        </div>

    </div>

    <div class="detail">

        <h2 class="detail-title">
            Message #<?= (int)$selectedMessage['id'] ?>
        </h2>

        <div class="detail-meta">

            <strong><?= msg_e($selectedMessage['sender_name']) ?></strong><br>

            <?php if ($selectedMessage['sender_phone']): ?>
                Phone:
                <?= msg_e($selectedMessage['sender_phone']) ?><br>
            <?php endif; ?>

            <?php if ($selectedMessage['sender_email']): ?>
                Email:
                <?= msg_e($selectedMessage['sender_email']) ?><br>
            <?php endif; ?>

            Type:
            <?= msg_e($selectedMessage['message_type']) ?><br>

            Received:
            <?= msg_e(date('d M Y, h:i A', strtotime((string)$selectedMessage['created_at']))) ?>

        </div>

        <div class="meta" style="margin-bottom:12px">

            <span class="badge <?= strtolower($selectedMessage['priority']) ?>">
                <?= msg_e($selectedMessage['priority']) ?> Priority
            </span>

            <span class="badge <?= strtolower($selectedMessage['status']) ?>">
                <?= msg_e($selectedMessage['status']) ?>
            </span>

        </div>

        <div class="detail-message">
            <?= msg_e($selectedMessage['message']) ?>
        </div>

        <?php if (!empty($selectedMessage['reply'])): ?>

            <div style="margin-top:16px">

                <strong>Previous Reply</strong>

                <div class="detail-message" style="margin-top:7px;background:#ecfdf5;border-color:#a7f3d0">
                    <?= msg_e($selectedMessage['reply']) ?>

                    <div style="margin-top:10px;font-size:11px;color:#047857">
                        Replied by
                        <?= msg_e($selectedMessage['replied_by']) ?>
                        <?php if ($selectedMessage['replied_at']): ?>
                            on
                            <?= msg_e(date('d M Y, h:i A', strtotime($selectedMessage['replied_at']))) ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        <?php endif; ?>

        <div class="reply-box">

            <form method="post">

                <input type="hidden" name="csrf_token" value="<?= msg_e(messages_csrf()) ?>">

                <input type="hidden" name="action" value="reply">
                <input
                    type="hidden"
                    name="message_id"
                    value="<?= (int)$selectedMessage['id'] ?>"
                >

                <label style="display:block;font-size:12px;font-weight:900;margin-bottom:6px">
                    <?= !empty($selectedMessage['reply']) ? 'Update Reply' : 'Reply' ?>
                </label>

                <textarea
                    name="reply"
                    placeholder="Write the clinic's response..."
                    required
                ><?= msg_e($selectedMessage['reply'] ?? '') ?></textarea>

                <div class="reply-actions">

                    <button class="btn green" type="submit">
                        ✓ Save Reply
                    </button>



                </div>

            </form>

        </div>

        <div style="margin-top:15px">

            <form method="post" style="display:inline">

                <input type="hidden" name="csrf_token" value="<?= msg_e(messages_csrf()) ?>">

                <input type="hidden" name="action" value="priority">
                <input type="hidden" name="message_id" value="<?= (int)$selectedMessage['id'] ?>">

                <select name="priority" onchange="this.form.submit()">

                    <option value="Normal" <?= $selectedMessage['priority'] === 'Normal' ? 'selected' : '' ?>>
                        Normal Priority
                    </option>

                    <option value="High" <?= $selectedMessage['priority'] === 'High' ? 'selected' : '' ?>>
                        High Priority
                    </option>

                    <option value="Urgent" <?= $selectedMessage['priority'] === 'Urgent' ? 'selected' : '' ?>>
                        Urgent Priority
                    </option>

                </select>

            </form>

            <?php if ($selectedMessage['status'] !== 'Archived'): ?>

                <form method="post" style="display:inline;margin-left:5px"
                      onsubmit="return confirm('Archive this message?');">

                <input type="hidden" name="csrf_token" value="<?= msg_e(messages_csrf()) ?>">

                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="message_id" value="<?= (int)$selectedMessage['id'] ?>">

                    <button class="btn orange" type="submit">
                        Archive
                    </button>

                </form>

            <?php else: ?>

                <form method="post" style="display:inline;margin-left:5px">

                <input type="hidden" name="csrf_token" value="<?= msg_e(messages_csrf()) ?>">

                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="message_id" value="<?= (int)$selectedMessage['id'] ?>">

                    <button class="btn secondary" type="submit">
                        Restore
                    </button>

                </form>

            <?php endif; ?>

            <?php if ($role === 'Admin'): ?>

                <form method="post" style="display:inline;margin-left:5px"
                      onsubmit="return confirm('Permanently delete this message?');">

                <input type="hidden" name="csrf_token" value="<?= msg_e(messages_csrf()) ?>">

                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="message_id" value="<?= (int)$selectedMessage['id'] ?>">

                    <button class="btn red" type="submit">
                        Delete
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>

<?php else: ?>

    <div class="card-head">

        <div>
            <h2>Create Internal Message</h2>
            <small>Add a follow-up or staff note.</small>
        </div>

    </div>

    <div class="side-section">

        <form method="post">

                <input type="hidden" name="csrf_token" value="<?= msg_e(messages_csrf()) ?>">

            <input type="hidden" name="action" value="add_message">

            <div class="form-row">

                <div class="field">
                    <label>Sender / Patient Name</label>
                    <input
                        type="text"
                        name="sender_name"
                        placeholder="Name"
                        required
                    >
                </div>

                <div class="field">
                    <label>Message Type</label>
                    <select name="message_type">
                        <option value="Internal">Internal</option>
                        <option value="Enquiry">Enquiry</option>
                        <option value="Follow-up">Follow-up</option>
                        <option value="Appointment">Appointment</option>
                    </select>
                </div>

            </div>

            <div class="form-row">

                <div class="field">
                    <label>Phone</label>
                    <input
                        type="text"
                        name="sender_phone"
                        placeholder="10-digit mobile"
                    >
                </div>

                <div class="field">
                    <label>Email</label>
                    <input
                        type="email"
                        name="sender_email"
                        placeholder="Email address"
                    >
                </div>

            </div>

            <div class="field">
                <label>Subject</label>
                <input
                    type="text"
                    name="subject"
                    placeholder="Message subject"
                    required
                >
            </div>

            <div class="form-row">

                <div class="field">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="Normal">Normal</option>
                        <option value="High">High</option>
                        <option value="Urgent">Urgent</option>
                    </select>
                </div>

                <div></div>

            </div>

            <div class="field">
                <label>Message</label>
                <textarea
                    name="message"
                    placeholder="Write the message..."
                    required
                ></textarea>
            </div>

            <button class="btn primary" type="submit">
                + Create Message
            </button>

        </form>

    </div>

    <div class="side-section">

        <h3>How Messages Work</h3>

        <div style="font-size:12px;color:#64748b;line-height:1.7">

            <strong>Unread</strong> — needs staff attention.<br>
            <strong>Read</strong> — opened by staff.<br>
            <strong>Replied</strong> — a response has been saved.<br>
            <strong>Archived</strong> — removed from the active inbox.<br>
            <strong>Urgent</strong> — shown at the top of the inbox.

        </div>

    </div>

<?php endif; ?>

</aside>

</div>

</main>

</div>

</body>
</html>
