<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Services | AmarDayal Clinic</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>Styles/style.css">

    <style>
        /* ==============================
           SERVICES PAGE
        ============================== */

        .services-page {
            background: #f5f8fc;
            color: #10233f;
        }

        /* HERO */

        .services-hero {
            min-height: 430px;

            display: flex;
            align-items: center;
            justify-content: center;

            text-align: center;

            padding: 70px 6%;

            color: white;

            background:
                radial-gradient(
                    circle at 80% 20%,
                    rgba(40, 220, 200, .22),
                    transparent 35%
                ),
                linear-gradient(
                    135deg,
                    #073b72,
                    #086579,
                    #08786f
                );
        }

        .services-hero-content {
            max-width: 850px;
        }

        .services-eyebrow {
            display: inline-block;

            margin-bottom: 18px;

            color: #79f1e5;

            font-size: 14px;
            font-weight: 800;

            letter-spacing: 2px;
        }

        .services-hero h1 {
            margin: 0 0 20px;

            font-size: clamp(45px, 6vw, 72px);

            line-height: 1.05;

            letter-spacing: -2px;
        }

        .services-hero p {
            max-width: 700px;

            margin: auto;

            color: rgba(255,255,255,.82);

            font-size: 19px;

            line-height: 1.8;
        }


        /* INTRO */

        .services-intro {
            padding: 90px 9% 50px;

            background: white;

            text-align: center;
        }

        .services-label {
            display: inline-block;

            margin-bottom: 15px;

            color: #2563eb;

            font-size: 13px;

            font-weight: 900;

            letter-spacing: 2px;
        }

        .services-intro h2 {
            max-width: 850px;

            margin: 0 auto 18px;

            font-size: clamp(34px, 4vw, 50px);

            line-height: 1.15;

            letter-spacing: -1.5px;
        }

        .services-intro h2 span {
            color: #2563eb;
        }

        .services-intro p {
            max-width: 760px;

            margin: auto;

            color: #64748b;

            font-size: 17px;

            line-height: 1.8;
        }


        /* SERVICE GRID */

        .services-section {
            padding: 60px 9% 100px;

            background: white;
        }

        .services-grid {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 25px;

            max-width: 1200px;

            margin: auto;
        }


        .service-card {
            position: relative;

            padding: 38px;

            border: 1px solid #e2e8f0;

            border-radius: 25px;

            background: white;

            overflow: hidden;

            transition:
                transform .3s ease,
                box-shadow .3s ease,
                border-color .3s ease;
        }

        .service-card::before {
            content: "";

            position: absolute;

            top: 0;
            left: 0;

            width: 100%;
            height: 4px;

            background:
                linear-gradient(
                    90deg,
                    #2563eb,
                    #14b8a6
                );

            transform: scaleX(0);

            transform-origin: left;

            transition: .3s ease;
        }

        .service-card:hover {
            transform: translateY(-7px);

            border-color: #c8d9f5;

            box-shadow:
                0 25px 55px
                rgba(15, 23, 42, .09);
        }

        .service-card:hover::before {
            transform: scaleX(1);
        }


        /* ICON */

        .service-icon {
            width: 68px;
            height: 68px;

            display: grid;

            place-items: center;

            margin-bottom: 25px;

            border-radius: 19px;

            background: #edf5ff;

            color: #2563eb;

            font-size: 30px;
        }

        .service-card:nth-child(2) .service-icon {
            background: #e9faf7;
            color: #0d9488;
        }

        .service-card:nth-child(3) .service-icon {
            background: #f1edff;
            color: #7c3aed;
        }

        .service-card:nth-child(4) .service-icon {
            background: #fff2e8;
            color: #ea580c;
        }

        .service-card:nth-child(5) .service-icon {
            background: #edf7ff;
            color: #0284c7;
        }

        .service-card:nth-child(6) .service-icon {
            background: #fff1f2;
            color: #e11d48;
        }


        .service-card h3 {
            margin: 0 0 12px;

            font-size: 25px;
        }

        .service-card p {
            margin: 0 0 22px;

            color: #64748b;

            font-size: 16px;

            line-height: 1.75;
        }


        .service-list {
            margin: 0;

            padding: 0;

            list-style: none;
        }

        .service-list li {
            position: relative;

            padding-left: 25px;

            margin-bottom: 9px;

            color: #475569;

            font-size: 14px;
        }

        .service-list li::before {
            content: "✓";

            position: absolute;

            left: 0;

            color: #14b8a6;

            font-weight: 900;
        }


        /* FEATURED */

        .service-card.featured {
            background:
                linear-gradient(
                    145deg,
                    #073d72,
                    #087c75
                );

            color: white;

            border: none;
        }

        .service-card.featured p,
        .service-card.featured .service-list li {
            color: rgba(255,255,255,.75);
        }

        .service-card.featured .service-icon {
            background: rgba(255,255,255,.13);

            color: #7ff0e4;
        }

        .service-card.featured .service-list li::before {
            color: #7ff0e4;
        }


        /* APPOINTMENT CTA */

        .services-cta {
            margin: 0 9% 90px;

            padding: 60px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 40px;

            border-radius: 30px;

            color: white;

            background:
                radial-gradient(
                    circle at 90% 15%,
                    rgba(20,184,166,.3),
                    transparent 35%
                ),
                linear-gradient(
                    135deg,
                    #073b72,
                    #087b75
                );
        }

        .services-cta h2 {
            max-width: 700px;

            margin: 0 0 12px;

            font-size: clamp(30px, 4vw, 46px);

            line-height: 1.15;
        }

        .services-cta p {
            margin: 0;

            color: rgba(255,255,255,.75);

            font-size: 16px;
        }

        .services-cta-btn {
            flex-shrink: 0;

            display: inline-flex;

            align-items: center;

            gap: 12px;

            padding: 16px 24px;

            border-radius: 13px;

            background: white;

            color: #1262d6;

            text-decoration: none;

            font-weight: 800;

            transition: .25s;
        }

        .services-cta-btn:hover {
            transform: translateY(-3px);
        }


        /* RESPONSIVE */

        @media(max-width: 850px) {

            .services-grid {
                grid-template-columns: 1fr;
            }

            .services-cta {
                flex-direction: column;

                align-items: flex-start;
            }
        }


        @media(max-width: 600px) {

            .services-hero {
                min-height: 400px;

                padding:
                    60px 6%;
            }

            .services-hero h1 {
                font-size: 43px;
            }

            .services-intro,
            .services-section {
                padding-left: 6%;
                padding-right: 6%;
            }

            .service-card {
                padding: 30px;
            }

            .services-cta {
                margin:
                    0 6% 60px;

                padding:
                    35px 25px;
            }
        }
    </style>
</head>

<body>

<?php nav(); ?>


<main class="services-page">


    <!-- ================= HERO ================= -->

    <section class="services-hero">

        <div class="services-hero-content">

            <span class="services-eyebrow">
                AMARDAYAL JOINT & PAIN MANAGEMENT CENTER
            </span>

            <h1>
                Care that helps you
                <br>
                move forward.
            </h1>

            <p>
                Focused healthcare services for pain management,
                physiotherapy, rehabilitation and general health
                needs.
            </p>

        </div>

    </section>



    <!-- ================= INTRO ================= -->

    <section class="services-intro">

        <span class="services-label">
            OUR SERVICES
        </span>

        <h2>
            Healthcare designed around
            <span>your needs.</span>
        </h2>

        <p>
            At AmarDayal Clinic, we provide patient-focused care
            with attention to comfort, consultation, treatment
            and follow-up.
        </p>

    </section>



    <!-- ================= SERVICES ================= -->

    <section class="services-section">

        <div class="services-grid">


            <!-- JOINT & PAIN -->

            <div class="service-card featured">

                <div class="service-icon">
                    🦴
                </div>

                <h3>
                    Joint & Pain Management
                </h3>

                <p>
                    Focused care and consultation for patients
                    experiencing joint, back and other
                    pain-related concerns.
                </p>

                <ul class="service-list">

                    <li>
                        Joint pain consultation
                    </li>

                    <li>
                        Back and neck pain
                    </li>

                    <li>
                        Musculoskeletal concerns
                    </li>

                    <li>
                        Follow-up care
                    </li>

                </ul>

            </div>



            <!-- PHYSIOTHERAPY -->

            <div class="service-card">

                <div class="service-icon">
                    🏃
                </div>

                <h3>
                    Physiotherapy
                </h3>

                <p>
                    Supportive physiotherapy and rehabilitation
                    aimed at improving mobility and everyday
                    physical function.
                </p>

                <ul class="service-list">

                    <li>
                        Mobility support
                    </li>

                    <li>
                        Rehabilitation
                    </li>

                    <li>
                        Exercise guidance
                    </li>

                    <li>
                        Recovery support
                    </li>

                </ul>

            </div>



            <!-- DIABETES -->

            <div class="service-card">

                <div class="service-icon">
                    🩺
                </div>

                <h3>
                    Diabetes Care
                </h3>

                <p>
                    Healthcare consultation and ongoing guidance
                    for patients managing diabetes and related
                    health concerns.
                </p>

                <ul class="service-list">

                    <li>
                        Health consultation
                    </li>

                    <li>
                        Routine monitoring
                    </li>

                    <li>
                        Lifestyle guidance
                    </li>

                    <li>
                        Follow-up consultation
                    </li>

                </ul>

            </div>



            <!-- GENERAL -->

            <div class="service-card">

                <div class="service-icon">
                    ✚
                </div>

                <h3>
                    General Healthcare
                </h3>

                <p>
                    General healthcare consultation and guidance
                    for common health concerns.
                </p>

                <ul class="service-list">

                    <li>
                        General consultation
                    </li>

                    <li>
                        Health assessment
                    </li>

                    <li>
                        Basic healthcare guidance
                    </li>

                    <li>
                        Follow-up care
                    </li>

                </ul>

            </div>



            <!-- CONSULTATION -->

            <div class="service-card">

                <div class="service-icon">
                    👨‍⚕️
                </div>

                <h3>
                    Doctor Consultation
                </h3>

                <p>
                    Patients can book appointments for consultation
                    and discuss their health concerns with the
                    clinical team.
                </p>

                <ul class="service-list">

                    <li>
                        Appointment booking
                    </li>

                    <li>
                        Patient consultation
                    </li>

                    <li>
                        Diagnosis discussion
                    </li>

                    <li>
                        Follow-up planning
                    </li>

                </ul>

            </div>



            <!-- FOLLOW UP -->

            <div class="service-card">

                <div class="service-icon">
                    📋
                </div>

                <h3>
                    Patient Follow-up
                </h3>

                <p>
                    Organized patient records support continued
                    care and make important medical information
                    easier to access during future visits.
                </p>

                <ul class="service-list">

                    <li>
                        Visit history
                    </li>

                    <li>
                        Prescription records
                    </li>

                    <li>
                        Follow-up information
                    </li>

                    <li>
                        Digital patient records
                    </li>

                </ul>

            </div>


        </div>

    </section>



    <!-- ================= CTA ================= -->

    <section class="services-cta">

        <div>

            <span class="services-eyebrow">
                NEED AN APPOINTMENT?
            </span>

            <h2>
                Take the first step
                towards better care.
            </h2>

            <p>
                Schedule an appointment with AmarDayal Clinic.
            </p>

        </div>


        <a
            href="<?= BASE_URL ?>appointment.php"
            class="services-cta-btn"
        >
            Book Appointment
            <span>→</span>
        </a>

    </section>


</main>


<footer class="footer">

    © <?= date('Y') ?>
    AmarDayal Joint & Pain Management Center

</footer>


</body>
</html>