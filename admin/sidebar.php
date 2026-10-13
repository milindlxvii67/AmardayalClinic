<?php
/*
|--------------------------------------------------------------------------
| AMARDAYAL CLINIC - SHARED ADMIN SIDEBAR
|--------------------------------------------------------------------------
| Include this file on every admin page:
|
| <?php include __DIR__ . '/navbar.php'; ?>
|
| The active menu item is detected automatically from the current filename.
*/

$currentPage = basename($_SERVER['PHP_SELF']);

$role = trim((string)($_SESSION['staff_role'] ?? 'Admin'));
$name = trim((string)($_SESSION['staff_name'] ?? 'System Administrator'));

if ($role === '') {
    $role = 'Admin';
}

if ($name === '') {
    $name = 'Staff';
}

function sidebarActive($page)
{
    global $currentPage;

    return $currentPage === $page ? 'active' : '';
}
?>

<style>
/* =========================================================
   AMARDAYAL - SHARED ADMIN SIDEBAR
   Same sidebar on every admin page
========================================================= */

.admin-sidebar,
.admin-sidebar * {
    box-sizing: border-box;
}

.admin-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 1000;

    width: 270px;
    min-height: 100vh;

    display: flex;
    flex-direction: column;

    padding: 18px 14px;

    background: #0b1220;
    color: #ffffff;

    border-right: 1px solid #1e293b;

    font-family: "Segoe UI", Inter, Arial, sans-serif;

    overflow-y: auto;
    overflow-x: hidden;
}

.admin-sidebar::-webkit-scrollbar {
    width: 5px;
}

.admin-sidebar::-webkit-scrollbar-thumb {
    background: #334155;
    border-radius: 10px;
}

/* BRAND */

.admin-sidebar .sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 8px 8px 22px;
    margin-bottom: 8px;

    border-bottom: 1px solid #1e293b;
}

.admin-sidebar .brand-logo {
    width: 42px;
    height: 42px;

    display: grid;
    place-items: center;

    flex: 0 0 42px;

    border-radius: 12px;

    background: linear-gradient(135deg, #2563eb, #14b8a6);

    color: #ffffff;

    font-size: 20px;
    font-weight: 900;

    box-shadow: 0 8px 20px rgba(37, 99, 235, .25);
}

.admin-sidebar .brand-text {
    min-width: 0;

    display: flex;
    flex-direction: column;
}

.admin-sidebar .brand-text strong {
    color: #ffffff;

    font-size: 15px;
    line-height: 1.2;
    letter-spacing: .7px;
    font-weight: 900;
}

.admin-sidebar .brand-text span {
    margin-top: 4px;

    color: #94a3b8;

    font-size: 10px;
    line-height: 1.2;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
}

/* NAVIGATION */

.admin-sidebar .sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 5px;

    padding-top: 8px;
}

.admin-sidebar .sidebar-link {
    position: relative;

    display: flex;
    align-items: center;
    gap: 12px;

    min-height: 46px;

    padding: 11px 13px;

    border-radius: 11px;

    color: #cbd5e1;
    background: transparent;

    text-decoration: none;

    font-size: 13px;
    font-weight: 700;

    transition:
        background .18s ease,
        color .18s ease,
        transform .18s ease;
}

.admin-sidebar .sidebar-link:hover {
    color: #ffffff;
    background: #172033;
    transform: translateX(2px);
}

.admin-sidebar .sidebar-link.active {
    color: #ffffff;
    background: linear-gradient(
        90deg,
        rgba(37, 99, 235, .95),
        rgba(29, 78, 216, .78)
    );

    box-shadow: 0 8px 22px rgba(37, 99, 235, .20);
}

.admin-sidebar .sidebar-link.active::before {
    content: "";

    position: absolute;
    left: -14px;
    top: 9px;
    bottom: 9px;

    width: 3px;

    border-radius: 0 5px 5px 0;

    background: #5eead4;
}

.admin-sidebar .sidebar-icon {
    width: 24px;
    height: 24px;

    display: grid;
    place-items: center;

    flex: 0 0 24px;

    border-radius: 7px;

    color: #94a3b8;
    background: #111c2e;

    font-size: 15px;
    line-height: 1;
}

.admin-sidebar .sidebar-link:hover .sidebar-icon {
    color: #ffffff;
}

.admin-sidebar .sidebar-link.active .sidebar-icon {
    color: #ffffff;
    background: rgba(255, 255, 255, .14);
}

/* WEBSITE MANAGEMENT */

.admin-sidebar .sidebar-section-label {
    display: flex;
    align-items: center;
    gap: 8px;

    margin: 12px 8px 2px;
    padding-top: 10px;

    border-top: 1px solid #1e293b;

    color: #64748b;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 1.3px;
}

.admin-sidebar .website-link {
    min-height: 54px;

    background:
        linear-gradient(
            135deg,
            rgba(20, 184, 166, .10),
            rgba(37, 99, 235, .08)
        );

    border: 1px solid rgba(45, 212, 191, .12);
}

.admin-sidebar .website-link .sidebar-icon {
    color: #5eead4;
    background: rgba(20, 184, 166, .12);
}

.admin-sidebar .website-link:hover {
    background:
        linear-gradient(
            135deg,
            rgba(20, 184, 166, .20),
            rgba(37, 99, 235, .16)
        );

    border-color: rgba(45, 212, 191, .28);
    transform: translateX(2px);
}

.admin-sidebar .website-link.active {
    background:
        linear-gradient(
            135deg,
            #0f766e,
            #2563eb
        );

    border-color: transparent;
    box-shadow:
        0 10px 24px rgba(20, 184, 166, .18),
        0 8px 22px rgba(37, 99, 235, .16);
}

.admin-sidebar .website-link.active .sidebar-icon {
    color: #ffffff;
    background: rgba(255,255,255,.15);
}

.admin-sidebar .website-link-text {
    min-width: 0;
    flex: 1;

    display: flex;
    flex-direction: column;
}

.admin-sidebar .website-link-text strong {
    color: #e2e8f0;
    font-size: 12px;
    line-height: 1.25;
    font-weight: 850;
}

.admin-sidebar .website-link-text small {
    margin-top: 2px;
    color: #7dd3c7;
    font-size: 9px;
    line-height: 1.2;
    font-weight: 650;
}

.admin-sidebar .website-link.active .website-link-text strong,
.admin-sidebar .website-link.active .website-link-text small {
    color: #ffffff;
}

.admin-sidebar .website-arrow {
    color: #5eead4;
    font-size: 20px;
    line-height: 1;
    font-weight: 300;
    transition: transform .18s ease;
}

.admin-sidebar .website-link:hover .website-arrow {
    transform: translateX(3px);
}

.admin-sidebar .website-link.active .website-arrow {
    color: #ffffff;
}


/* BOTTOM USER AREA */

.admin-sidebar .sidebar-bottom {
    margin-top: auto;

    padding-top: 14px;
}

.admin-sidebar .sidebar-user {
    display: flex;
    align-items: center;
    gap: 10px;

    padding: 11px;

    border: 1px solid #243247;
    border-radius: 13px;

    background: #111c2e;
}

.admin-sidebar .user-avatar {
    width: 38px;
    height: 38px;

    display: grid;
    place-items: center;

    flex: 0 0 38px;

    border-radius: 10px;

    background: #2563eb;

    color: #ffffff;

    font-size: 14px;
    font-weight: 900;
}

.admin-sidebar .user-info {
    min-width: 0;

    display: flex;
    flex-direction: column;
}

.admin-sidebar .user-info strong {
    overflow: hidden;

    color: #ffffff;

    font-size: 12px;
    line-height: 1.3;

    text-overflow: ellipsis;
    white-space: nowrap;
}

.admin-sidebar .user-info span {
    margin-top: 3px;

    color: #5eead4;

    font-size: 10px;
    font-weight: 700;
}

.admin-sidebar .sidebar-logout {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-top: 8px;
    padding: 10px 12px;

    border-radius: 10px;

    color: #fca5a5;
    background: transparent;

    text-decoration: none;

    font-size: 12px;
    font-weight: 800;

    transition: background .18s ease, color .18s ease;
}

.admin-sidebar .sidebar-logout:hover {
    color: #fecaca;
    background: #2a1720;
}

/* Keep normal page content beside the shared fixed sidebar.
   Existing admin pages can keep their own .content/.main/.layout
   styles; this rule handles common main elements without touching
   cards/forms inside the pages. */

@media (max-width: 900px) {
    .admin-sidebar {
        width: 78px;
        padding: 14px 9px;
    }

    .admin-sidebar .sidebar-brand {
        justify-content: center;
        padding: 7px 4px 18px;
    }

    .admin-sidebar .brand-text {
        display: none;
    }

    .admin-sidebar .sidebar-link {
        justify-content: center;
        padding: 11px 7px;
    }

    .admin-sidebar .sidebar-link span:not(.sidebar-icon) {
        display: none;
    }

    .admin-sidebar .website-link-text,
    .admin-sidebar .website-arrow {
        display: none;
    }

    .admin-sidebar .sidebar-section-label {
        justify-content: center;
        margin-left: 0;
        margin-right: 0;
    }

    .admin-sidebar .sidebar-section-label span {
        display: none;
    }

    .admin-sidebar .sidebar-section-label::before {
        content: "•";
        color: #5eead4;
        font-size: 12px;
    }

    .admin-sidebar .sidebar-icon {
        width: 30px;
        height: 30px;
    }

    .admin-sidebar .sidebar-link.active::before {
        left: -9px;
    }

    .admin-sidebar .sidebar-user {
        justify-content: center;
        padding: 8px;
    }

    .admin-sidebar .user-info {
        display: none;
    }

    .admin-sidebar .sidebar-logout {
        justify-content: center;
        padding: 10px 7px;
    }

    .admin-sidebar .sidebar-logout span:last-child {
        display: none;
    }
}

@media (max-width: 520px) {
    .admin-sidebar {
        width: 66px;
    }

    .admin-sidebar .sidebar-link {
        min-height: 43px;
    }
}
</style>

<aside class="admin-sidebar">

    <div class="sidebar-brand">

        <div class="brand-logo">
            A
        </div>

        <div class="brand-text">
            <strong>AMARDAYAL</strong>
            <span>Clinic Management</span>
        </div>

    </div>

    <nav class="sidebar-nav">

        <a
            href="index.php"
            class="sidebar-link <?= sidebarActive('index.php') ?>"
        >
            <span class="sidebar-icon">⌂</span>
            <span>Dashboard</span>
        </a>

        <a
            href="doctor.php"
            class="sidebar-link <?= sidebarActive('doctor.php') ?>"
        >
            <span class="sidebar-icon">⚕</span>
            <span>Doctor Consultation</span>
        </a>

        <a
            href="patients.php"
            class="sidebar-link <?= sidebarActive('patients.php') ?>"
        >
            <span class="sidebar-icon">♟</span>
            <span>Patients</span>
        </a>

        <a
            href="appointments.php"
            class="sidebar-link <?= sidebarActive('appointments.php') ?>"
        >
            <span class="sidebar-icon">▣</span>
            <span>Appointments</span>
        </a>

        <a
            href="reception.php"
            class="sidebar-link <?= sidebarActive('reception.php') ?>"
        >
            <span class="sidebar-icon">＋</span>
            <span>Reception</span>
        </a>

        <a
            href="billing.php"
            class="sidebar-link <?= sidebarActive('billing.php') ?>"
        >
            <span class="sidebar-icon">▤</span>
            <span>Billing</span>
        </a>

        <a
            href="reports.php"
            class="sidebar-link <?= sidebarActive('reports.php') ?>"
        >
            <span class="sidebar-icon">▥</span>
            <span>Reports</span>
        </a>

        <?php if (($role === 'Admin') || ($role === 'Administrator')): ?>

            <a
                href="administration.php"
                class="sidebar-link <?= sidebarActive('administration.php') ?>"
            >
                <span class="sidebar-icon">⚙</span>
                <span>Administration</span>
            </a>

        <?php endif; ?>

        <a
            href="Messages.php"
            class="sidebar-link <?= sidebarActive('Messages.php') ?>"
        >
            <span class="sidebar-icon">✉</span>
            <span>Messages</span>
        </a>

 <?php if (($role === 'Admin') || ($role === 'Administrator')): ?>

    <!-- CLINIC MANAGEMENT -->

    <div class="sidebar-section-label">
        <span>CLINIC MANAGEMENT</span>
    </div>

    <a
        href="manage_medicines.php"
        class="sidebar-link <?= sidebarActive('manage_medicines.php') ?>"
    >
        <span class="sidebar-icon">💊</span>
        <span>Manage Medicines</span>
    </a>


    <!-- WEBSITE -->

    <div class="sidebar-section-label">
        <span>WEBSITE</span>
    </div>

    <a
        href="website.php"
        class="sidebar-link website-link <?= sidebarActive('website.php') ?>"
    >
        <span class="sidebar-icon">🌐</span>

        <span class="website-link-text">
            <strong>Edit Website</strong>
            <small>Manage clinic website</small>
        </span>

        <span class="website-arrow">›</span>
    </a>

<?php endif; ?>

    </nav>

    <div class="sidebar-bottom">

        <div class="sidebar-user">

            <div class="user-avatar">
                <?= e(strtoupper(substr($name, 0, 1) ?: 'S')) ?>
            </div>

            <div class="user-info">

                <strong>
                    <?= e($name) ?>
                </strong>

                <span>
                    <?= e($role) ?>
                </span>

            </div>

        </div>

        <a
            href="Logout.php"
            class="sidebar-logout"
        >
            <span>↪</span>
            <span>Logout</span>
        </a>

    </div>

</aside>
