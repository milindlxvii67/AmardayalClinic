<?php
/*
|--------------------------------------------------------------------------
| AMARDAYAL CLINIC - NAVBAR
|--------------------------------------------------------------------------
| Shows:
| - Public navigation for everyone
| - Staff Login when staff is NOT logged in
| - Staff Dashboard + Logout when staff IS logged in
|--------------------------------------------------------------------------
*/

$staff = !empty($_SESSION['staff_id']);
?>

<header class="site-header">

    <!-- ================================
         CLINIC BRAND / LOGO
         ================================ -->
    <a class="brand" href="<?= BASE_URL ?>index.php">

        <img
            src="<?= BASE_URL ?>img/logo.png"
            alt="AmarDayal Clinic"
            onerror="this.style.display='none'"
        >

        <span>
            <b>AMARDAYAL</b>
            <small>JOINT &amp; PAIN CARE CENTER</small>
        </span>

    </a>


    <!-- ================================
         MOBILE MENU BUTTON
         ================================ -->
    <button
        class="nav-toggle"
        type="button"
        aria-label="Open navigation"
        aria-expanded="false"
        onclick="
            const nav = document.querySelector('.main-nav');
            nav.classList.toggle('open');
            this.setAttribute(
                'aria-expanded',
                nav.classList.contains('open') ? 'true' : 'false'
            );
        "
    >
        ☰
    </button>


    <!-- ================================
         MAIN NAVIGATION
         ================================ -->
    <nav class="main-nav">

        <!-- HOME -->
        <a href="<?= BASE_URL ?>index.php">
            Home
        </a>


        <!-- ABOUT -->
        <a href="<?= BASE_URL ?>about.php">
            About
        </a>


        <!-- SERVICES -->
        <a href="<?= BASE_URL ?>services.php">
            Services
        </a>


        <!-- APPOINTMENT
             No patient login required -->
        <a href="<?= BASE_URL ?>appointment.php">
            Appointment
        </a>


        <!-- CONTACT -->
        <a href="<?= BASE_URL ?>contact.php">
            Contact
        </a>


        <!-- GALLERY -->
        <a href="<?= BASE_URL ?>gallery.php">
            Gallery
        </a>


        <?php if (!$staff): ?>

            <!-- =================================
                 STAFF LOGIN
                 Visible when staff is NOT logged in
                 ================================= -->
            <a
                href="<?= BASE_URL ?>admin/Login.php"
                class="staff-login-btn"
            >
                <span class="staff-login-icon">👤</span>
                <span>Staff Login</span>
            </a>


        <?php else: ?>

            <!-- =================================
                 STAFF DASHBOARD
                 Visible after staff login
                 ================================= -->
            <a
                href="<?= BASE_URL ?>admin/index.php"
                class="staff-dashboard-btn"
            >
                <span class="staff-login-icon">📊</span>
                <span>Staff Dashboard</span>
            </a>


            <!-- =================================
                 STAFF LOGOUT
                 ================================= -->
            <a
                class="danger-link"
                href="<?= BASE_URL ?>admin/Logout.php"
            >
                <span>Logout</span>
            </a>

        <?php endif; ?>

    </nav>

</header>


<!-- =========================================================
     NAVBAR BUTTON STYLES
     ========================================================= -->
<style>

    /* -----------------------------------------
       Staff Login Button
       ----------------------------------------- */

    .staff-login-btn {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        gap: 7px;

        padding: 9px 15px !important;
        margin-left: 6px;

        background: linear-gradient(
            135deg,
            #1557b0,
            #2477e8
        );

        color: #ffffff !important;

        border-radius: 8px;

        text-decoration: none !important;

        font-size: 13px;
        font-weight: 700;

        border: 1px solid rgba(255,255,255,0.15);

        box-shadow:
            0 4px 12px rgba(21,87,176,0.20);

        transition:
            all 0.2s ease;
    }


    .staff-login-btn:hover {
        background: linear-gradient(
            135deg,
            #10458e,
            #155fc5
        );

        color: #ffffff !important;

        transform: translateY(-2px);

        box-shadow:
            0 7px 18px rgba(21,87,176,0.30);
    }


    /* -----------------------------------------
       Staff Login Icon
       ----------------------------------------- */

    .staff-login-icon {
        font-size: 14px;
        line-height: 1;
    }


    /* -----------------------------------------
       Staff Dashboard Button
       ----------------------------------------- */

    .staff-dashboard-btn {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        gap: 7px;

        padding: 9px 15px !important;
        margin-left: 6px;

        background: linear-gradient(
            135deg,
            #0f766e,
            #14b8a6
        );

        color: #ffffff !important;

        border-radius: 8px;

        text-decoration: none !important;

        font-size: 13px;
        font-weight: 700;

        box-shadow:
            0 4px 12px rgba(20,184,166,0.20);

        transition:
            all 0.2s ease;
    }


    .staff-dashboard-btn:hover {
        background: linear-gradient(
            135deg,
            #0b5f59,
            #0f988d
        );

        color: #ffffff !important;

        transform: translateY(-2px);

        box-shadow:
            0 7px 18px rgba(20,184,166,0.30);
    }


    /* -----------------------------------------
       Logout
       ----------------------------------------- */

    .danger-link {
        color: #dc2626 !important;
        font-weight: 700 !important;
    }


    .danger-link:hover {
        color: #b91c1c !important;
    }


    /* -----------------------------------------
       Mobile Navigation
       ----------------------------------------- */

    @media (max-width: 768px) {

        .main-nav {
            flex-direction: column;
            align-items: stretch;
        }


        .staff-login-btn,
        .staff-dashboard-btn {
            width: 100%;
            margin: 6px 0 0 0 !important;
            padding: 11px 15px !important;
        }


        .danger-link {
            width: 100%;
            margin-top: 6px;
        }

    }

</style>