<?php

require_once 'config.php';

$services = $conn->query("
    SELECT *
    FROM clinic_services
    WHERE is_active = 1
    ORDER BY id
    LIMIT 4
");


/* =========================================================
   STAFF / DOCTORS
========================================================= */

$staffMembers = [];

$staffResult = $conn->query("
    SELECT
        id,
        name,
        position,
        specialization,
        description,
        photo
    FROM clinic_staff
    WHERE is_active = 1
    ORDER BY display_order ASC, id ASC
");


if ($staffResult) {

    while (
        $staffRow =
        $staffResult->fetch_assoc()
    ) {

        $staffMembers[] =
            $staffRow;

    }

}


$patient =
    !empty($_SESSION['patient_id']);

$staff =
    !empty($_SESSION['staff_id']);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="AmarDayal Joint & Pain Care Center — professional pain relief, physiotherapy and patient-centered care.">
    <title>AmarDayal | Joint & Pain Care Center</title>
    <link rel="stylesheet" href="Styles/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ------------------------------------------------------------
           Home page styles — every class is prefixed "hp-" so nothing
           here collides with Styles/style.css or the navbar.
           Colour tokens: swap these hex values for the exact ones in
           your style.css if any look slightly off.
           ------------------------------------------------------------ */
        .hp {
            --hp-primary: #0b7285;
            --hp-primary-deep: #07475a;
            --hp-primary-soft: #e4f3f6;
            --hp-blue: #2f6fed;
            --hp-purple: #7c4dff;
            --hp-orange: #f08a24;
            --hp-ink: #14262e;
            --hp-muted: #5a6c75;
            --hp-line: #dce7ea;
            --hp-bg: #f6fafb;
            --hp-white: #ffffff;
            font-family: "DM Sans", system-ui, sans-serif;
            color: var(--hp-ink);
            background: var(--hp-bg);
        }
        .hp *, .hp *::before, .hp *::after { box-sizing: border-box; }
        .hp h1, .hp h2, .hp h3 { font-family: "Manrope", "DM Sans", sans-serif; margin: 0; letter-spacing: -0.02em; line-height: 1.12; }
        .hp p { margin: 0; line-height: 1.65; }
        .hp a { color: inherit; }
        .hp-wrap { width: min(1160px, 100% - 40px); margin-inline: auto; }

        .hp a:focus-visible, .hp button:focus-visible {
            outline: 3px solid var(--hp-orange);
            outline-offset: 3px;
            border-radius: 8px;
        }

        /* Buttons */
        .hp-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 14px 26px; border-radius: 999px;
            font-weight: 700; font-size: 1rem; text-decoration: none;
            transition: background .2s, transform .2s, border-color .2s;
        }
        .hp-btn-primary { background: var(--hp-primary); color: #fff !important; }
        .hp-btn-primary:hover { background: var(--hp-primary-deep); }
        .hp-btn-ghost { border: 2px solid var(--hp-primary); color: var(--hp-primary-deep) !important; }
        .hp-btn-ghost:hover { background: var(--hp-primary-soft); }
        .hp-btn-light { background: #fff; color: var(--hp-primary-deep) !important; }
        .hp-btn-light:hover { background: var(--hp-primary-soft); }
        .hp-btn-outline-light { border: 2px solid rgba(255,255,255,.7); color: #fff !important; }
        .hp-btn-outline-light:hover { background: rgba(255,255,255,.12); }

        /* ---------- Hero ---------- */
        .hp-hero { padding: 56px 0 110px; background: linear-gradient(180deg, var(--hp-primary-soft) 0%, var(--hp-bg) 100%); }
        .hp-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(360px, 0.9fr);
            gap: 56px;
            align-items: center;
        }
        .hp-hero-grid > div:first-child { max-width: 820px; }
        .hp-hero h1 { font-size: clamp(2.4rem, 5.2vw, 4rem); font-weight: 800; color: var(--hp-primary-deep); max-width: 14ch; }

        /* ---------- Hero Image ---------- */
        .hp-hero-image {
            width: 100%;
            height: 440px;
            border-radius: 34px;
            overflow: hidden;
            position: relative;
            background: var(--hp-primary-soft);
            box-shadow: 0 20px 45px rgba(7, 71, 90, .12);
        }

        .hp-hero-image img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            object-position: center;
        }
        .hp-hero-lead { margin-top: 20px; font-size: 1.15rem; color: var(--hp-muted); max-width: 46ch; }
        .hp-hero-actions { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 14px; }
        .hp-focus-list { list-style: none; margin: 36px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 10px; }
        .hp-focus-list li {
            padding: 8px 16px; border-radius: 999px; background: var(--hp-white);
            border: 1px solid var(--hp-line); font-weight: 600; font-size: .95rem; color: var(--hp-primary-deep);
        }
        .hp-quick-item {
            display: flex; gap: 14px; align-items: center; padding: 22px 24px;
            text-decoration: none; border-right: 1px solid var(--hp-line); transition: background .2s;
        }
        .hp-quick-item:last-child { border-right: 0; }
        .hp-quick-item:hover { background: var(--hp-primary-soft); }
        .hp-quick-icon {
            flex: none; width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center;
            font-size: 1.2rem; font-weight: 700; color: #fff;
        }
        .hp-quick-item:nth-child(1) .hp-quick-icon { background: var(--hp-blue); }
        .hp-quick-item:nth-child(2) .hp-quick-icon { background: var(--hp-primary); }
        .hp-quick-item:nth-child(3) .hp-quick-icon { background: var(--hp-purple); }
        .hp-quick-item:nth-child(4) .hp-quick-icon { background: var(--hp-orange); }
        .hp-quick-item strong { display: block; font-size: 1rem; }
        .hp-quick-item small { color: var(--hp-muted); font-size: .88rem; }

        /* ---------- Services (sticky intro + rows) ---------- */
        .hp-services { padding: 96px 0 40px; }
        .hp-services-grid { display: grid; grid-template-columns: 0.8fr 1.2fr; gap: 64px; align-items: start; }
        .hp-services-intro { position: sticky; top: 110px; }
        .hp-services-intro h2 { font-size: clamp(1.9rem, 3.4vw, 2.7rem); font-weight: 800; color: var(--hp-primary-deep); }
        .hp-services-intro p { margin-top: 16px; color: var(--hp-muted); max-width: 38ch; }
        .hp-services-intro .hp-btn { margin-top: 26px; }
        .hp-service-list { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--hp-line); }
        .hp-service-row {
            display: grid; grid-template-columns: 64px 1fr auto; gap: 22px; align-items: center;
            padding: 28px 8px; border-bottom: 1px solid var(--hp-line); transition: background .2s;
        }
        .hp-service-row:hover { background: var(--hp-white); }
        .hp-service-icon {
            width: 56px; height: 56px; border-radius: 16px; background: var(--hp-primary-soft); color: var(--hp-primary-deep);
            display: grid; place-items: center; font-size: 1.5rem;
        }
        .hp-service-row h3 { font-size: 1.25rem; font-weight: 700; }
        .hp-service-row p { margin-top: 6px; color: var(--hp-muted); font-size: .98rem; }
        .hp-service-row a { font-weight: 700; color: var(--hp-primary); text-decoration: none; border-bottom: 2px solid transparent; white-space: nowrap; }
        .hp-service-row a:hover { border-bottom-color: var(--hp-primary); }

        /* ---------- Care journey (a real sequence, so numbered) ---------- */
        .hp-journey { padding: 80px 0; }
        .hp-journey-head { max-width: 620px; }
        .hp-journey-head h2 { font-size: clamp(1.8rem, 3.2vw, 2.5rem); font-weight: 800; color: var(--hp-primary-deep); }
        .hp-journey-head p { margin-top: 14px; color: var(--hp-muted); }
        .hp-steps { list-style: none; margin: 44px 0 0; padding: 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; counter-reset: step; position: relative; }
        .hp-steps::before {
            content: ""; position: absolute; top: 22px; left: 22px; right: 22px; height: 2px; background: var(--hp-line);
        }
        .hp-step { position: relative; padding-right: 24px; counter-increment: step; }
        .hp-step::before {
            content: counter(step); position: relative; z-index: 1;
            display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%;
            background: var(--hp-primary); color: #fff; font-family: "Manrope", sans-serif; font-weight: 800;
            box-shadow: 0 0 0 6px var(--hp-bg);
        }
        .hp-step h3 { margin-top: 20px; font-size: 1.1rem; font-weight: 700; }
        .hp-step p { margin-top: 8px; color: var(--hp-muted); font-size: .96rem; }

        /* ---------- Reasons (dark band, text-led) ---------- */
        .hp-reasons { background: var(--hp-primary-deep); color: #fff; padding: 72px 0; }
        .hp-reasons-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 48px; }
        .hp-reasons h2 { grid-column: 1 / -1; font-size: clamp(1.7rem, 3vw, 2.3rem); font-weight: 800; max-width: 22ch; }
        .hp-reason { border-top: 3px solid var(--hp-orange); padding-top: 18px; }
        .hp-reason:nth-of-type(2) { border-top-color: #6fd0df; }
        .hp-reason:nth-of-type(3) { border-top-color: #b79dff; }
        .hp-reason h3 { font-size: 1.15rem; font-weight: 700; }
        .hp-reason p { margin-top: 8px; color: rgba(255,255,255,.78); font-size: .98rem; }

        /* ---------- Final CTA ---------- */
        .hp-cta-section { padding: 88px 0 96px; }
        .hp-cta {
            display: grid; grid-template-columns: 1.3fr 1fr; gap: 32px; align-items: center;
            background: var(--hp-primary); color: #fff; border-radius: 32px; padding: 56px;
        }
        .hp-cta h2 { font-size: clamp(1.7rem, 3vw, 2.4rem); font-weight: 800; }
        .hp-cta p { margin-top: 12px; color: rgba(255,255,255,.85); max-width: 46ch; }
        .hp-cta-actions { display: flex; flex-wrap: wrap; gap: 14px; justify-content: flex-end; }

        .hp-footer { text-align: center; padding: 26px 20px; color: var(--hp-muted); font-size: .92rem; border-top: 1px solid var(--hp-line); background: var(--hp-white); }
/* =========================================================
   STAFF / DOCTORS
========================================================= */

.hp-staff {
    padding: 96px 0;
    background: var(--hp-white);
}

.hp-staff-head {
    max-width: 680px;
    margin-bottom: 46px;
}

.hp-staff-kicker {
    color: var(--hp-primary);
    font-size: .82rem;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.hp-staff-head h2 {
    margin-top: 10px;
    font-size: clamp(1.9rem, 3.4vw, 2.7rem);
    font-weight: 800;
    color: var(--hp-primary-deep);
}

.hp-staff-head p {
    margin-top: 14px;
    color: var(--hp-muted);
    max-width: 58ch;
}


.hp-staff-grid {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(240px, 1fr)
        );

    gap: 26px;
}


.hp-staff-card {
    background: var(--hp-white);

    border: 1px solid var(--hp-line);

    border-radius: 22px;

    overflow: hidden;

    box-shadow:
        0 12px 32px
        rgba(7, 71, 90, .08);

    transition:
        transform .25s ease,
        box-shadow .25s ease;
}


.hp-staff-card:hover {
    transform: translateY(-6px);

    box-shadow:
        0 20px 44px
        rgba(7, 71, 90, .14);
}


.hp-staff-photo {
    height: 290px;

    background:
        linear-gradient(
            180deg,
            var(--hp-primary-soft),
            #edf7f8
        );

    overflow: hidden;
}


.hp-staff-photo img {
    width: 100%;
    height: 100%;

    object-fit: cover;

    display: block;
}


.hp-staff-placeholder {
    height: 100%;

    display: grid;
    place-items: center;

    font-size: 64px;

    color: var(--hp-primary);
}


.hp-staff-info {
    padding: 22px;
}


.hp-staff-info h3 {
    font-size: 1.2rem;

    color: var(--hp-primary-deep);

    font-weight: 800;
}


.hp-staff-position {
    margin-top: 7px;

    color: var(--hp-primary);

    font-size: .92rem;

    font-weight: 700;
}


.hp-staff-specialization {
    margin-top: 6px;

    color: var(--hp-muted);

    font-size: .86rem;

    font-weight: 600;
}


.hp-staff-description {
    margin-top: 12px;

    color: var(--hp-muted);

    font-size: .9rem;

    line-height: 1.65;
}


.hp-staff-empty {
    padding: 30px;

    border: 1px dashed var(--hp-line);

    border-radius: 16px;

    color: var(--hp-muted);
}


@media (max-width: 560px) {

    .hp-staff {
        padding: 70px 0;
    }

    .hp-staff-photo {
        height: 320px;
    }

}
        /* ---------- Responsive ---------- */
        @media (max-width: 960px) {
            .hp-hero { padding: 36px 0 100px; }
            .hp-hero-grid { grid-template-columns: 1fr; gap: 36px; }
            .hp-hero h1 { max-width: 18ch; }
            .hp-hero-image {
                height: 380px;
                max-width: 620px;
                width: 100%;
                margin-inline: auto;
            }
            .hp-quick-grid { grid-template-columns: repeat(2, 1fr); }
            .hp-quick-item:nth-child(2) { border-right: 0; }
            .hp-quick-item:nth-child(-n+2) { border-bottom: 1px solid var(--hp-line); }
            .hp-services-grid { grid-template-columns: 1fr; gap: 32px; }
            .hp-services-intro { position: static; }
            .hp-steps { grid-template-columns: 1fr 1fr; row-gap: 36px; }
            .hp-steps::before { display: none; }
            .hp-reasons-grid { grid-template-columns: 1fr; gap: 32px; }
            .hp-cta { grid-template-columns: 1fr; padding: 40px 28px; }
            .hp-cta-actions { justify-content: flex-start; }
        }
        @media (max-width: 560px) {
            .hp-quick-grid { grid-template-columns: 1fr; }
            .hp-quick-item { border-right: 0; border-bottom: 1px solid var(--hp-line); }
            .hp-hero-image { height: 320px; }
            .hp-quick-item:last-child { border-bottom: 0; }
            .hp-service-row { grid-template-columns: 52px 1fr; }
            .hp-service-row a { grid-column: 2; }
            .hp-steps { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) {
            .hp * { transition: none !important; }
        }
    </style>
</head>
<body>
<?php nav(); ?>

<main class="hp">

    <!-- Hero: headline on the left, care team on the right -->
    <section class="hp-hero">
        <div class="hp-wrap hp-hero-grid">
            <div>
                <h1><?=e(site_content('home_title','Relief From Pain Starts Here'))?></h1>
                <p class="hp-hero-lead"><?=e(site_content('home_subtitle','Professional Pain Relief & Physiotherapy Clinic'))?></p>

                <div class="hp-hero-actions">
                    <a class="hp-btn hp-btn-primary" href="<?=BASE_URL?>appointment.php">Book an appointment</a>
                    <a class="hp-btn hp-btn-ghost" href="<?=BASE_URL?>services.php">See our services</a>
                </div>

                <ul class="hp-focus-list" aria-label="What we treat">
                    <li>Joint &amp; back care</li>
                    <li>Physiotherapy &amp; rehab</li>
                    <li>Diabetes &amp; general care</li>
                </ul>
            </div>

            <!-- Hero image -->
            <div class="hp-hero-image">
                <img
                    src="<?=BASE_URL?>img/hero/clinic-hero.jpg"
                    alt="AmarDayal Clinic"
                >
            </div>
        </div>
    </section>

    <!-- Quick actions overlapping the hero -->
    <section class="hp-quick" aria-label="Quick links">
        <div class="hp-wrap">
            <div class="hp-quick-grid">
                <a class="hp-quick-item" href="<?=BASE_URL?>appointment.php">
                    <span class="hp-quick-icon" aria-hidden="true">▣</span>
                    <span><strong>Book online</strong><small>Takes a few minutes</small></span>
                </a>
                <a class="hp-quick-item" href="<?=BASE_URL?>services.php">
                    <span class="hp-quick-icon" aria-hidden="true">✦</span>
                    <span><strong>Our services</strong><small>Find the right care</small></span>
                </a>
                <a class="hp-quick-item" href="<?=BASE_URL?>about.php">
                    <span class="hp-quick-icon" aria-hidden="true">♙</span>
                    <span><strong>About the clinic</strong><small>Meet the team</small></span>
                </a>
                <a class="hp-quick-item" href="<?=BASE_URL?>contact.php">
                    <span class="hp-quick-icon" aria-hidden="true">✆</span>
                    <span><strong>Contact us</strong><small>Ask a question</small></span>
                </a>
            </div>
        </div>
    </section>

    <!-- Services: sticky intro + list rows -->
    <section class="hp-services">
        <div class="hp-wrap hp-services-grid">
            <div class="hp-services-intro">
                <h2>Support for a healthier, more active life</h2>
                <p>Explore the care available at AmarDayal Joint &amp; Pain Care Center, from first consultation to recovery.</p>
                <a class="hp-btn hp-btn-primary" href="<?=BASE_URL?>services.php">View all services</a>
            </div>

            <ul class="hp-service-list">
                <?php while($s = $services->fetch_assoc()): ?>
                    <li class="hp-service-row">
                        <div class="hp-service-icon" aria-hidden="true"><?=e($s['icon'])?></div>
                        <div>
                            <h3><?=e($s['title'])?></h3>
                            <p><?=e($s['description'])?></p>
                        </div>
                        <a href="<?=BASE_URL?>services.php">Learn more</a>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </section>
    <!-- =====================================================
         DOCTORS & STAFF
    ====================================================== -->

    <?php if ($staffMembers): ?>

        <section class="hp-staff">

            <div class="hp-wrap">


                <div class="hp-staff-head">

                    <span class="hp-staff-kicker">
                        OUR TEAM
                    </span>


                    <h2>
                        Meet Our Doctors &amp; Staff
                    </h2>


                    <p>
                        Meet the experienced professionals
                        who are here to support your treatment,
                        recovery and overall wellbeing.
                    </p>

                </div>


                <div class="hp-staff-grid">


                    <?php foreach (
                        $staffMembers
                        as $member
                    ): ?>


                        <article class="hp-staff-card">


                            <!-- PHOTO -->

                            <div class="hp-staff-photo">

                                <?php if (
                                    !empty(
                                        $member['photo']
                                    )
                                ): ?>

                                    <img
                                        src="img/doctors/<?= e(
                                            $member['photo']
                                        ) ?>"
                                        alt="<?= e(
                                            $member['name']
                                        ) ?>"
                                        loading="lazy"
                                    >

                                <?php else: ?>

                                    <div
                                        class="hp-staff-placeholder"
                                        aria-label="Staff member"
                                    >
                                        👨‍⚕️
                                    </div>

                                <?php endif; ?>

                            </div>


                            <!-- INFORMATION -->

                            <div class="hp-staff-info">


                                <h3>

                                    <?= e(
                                        $member['name']
                                    ) ?>

                                </h3>


                                <?php if (
                                    !empty(
                                        $member['position']
                                    )
                                ): ?>

                                    <div
                                        class="hp-staff-position"
                                    >

                                        <?= e(
                                            $member['position']
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <?php if (
                                    !empty(
                                        $member['specialization']
                                    )
                                ): ?>

                                    <div
                                        class="
                                            hp-staff-specialization
                                        "
                                    >

                                        <?= e(
                                            $member[
                                                'specialization'
                                            ]
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <?php if (
                                    !empty(
                                        $member['description']
                                    )
                                ): ?>

                                    <p
                                        class="
                                            hp-staff-description
                                        "
                                    >

                                        <?= e(
                                            $member[
                                                'description'
                                            ]
                                        ) ?>

                                    </p>

                                <?php endif; ?>


                            </div>


                        </article>


                    <?php endforeach; ?>


                </div>

            </div>

        </section>

    <?php endif; ?>

    <!-- Care journey -->
    <section class="hp-journey">
        <div class="hp-wrap">
            <div class="hp-journey-head">
                <h2>One connected clinic, from booking to follow-up</h2>
                <p>Your appointment, consultation, prescription and billing all live in the same system, so nothing gets lost between visits.</p>
            </div>
            <ol class="hp-steps">
                <li class="hp-step">
                    <h3>Book your visit</h3>
                    <p>Choose a time online in just a few minutes.</p>
                </li>
                <li class="hp-step">
                    <h3>Meet your doctor</h3>
                    <p>A consultation focused on your pain and recovery.</p>
                </li>
                <li class="hp-step">
                    <h3>Get your care plan</h3>
                    <p>Prescription and billing handled in one place.</p>
                </li>
                <li class="hp-step">
                    <h3>Follow up</h3>
                    <p>Your records stay organized for every return visit.</p>
                </li>
            </ol>
        </div>
    </section>

    <!-- Why patients trust us -->
    <section class="hp-reasons">
        <div class="hp-wrap hp-reasons-grid">
            <h2>Care that is personal, reliable and easy to access</h2>
            <div class="hp-reason">
                <h3>Experienced team</h3>
                <p>Professionals focused on pain management and recovery.</p>
            </div>
            <div class="hp-reason">
                <h3>Patient first</h3>
                <p>Care plans designed around your needs and condition.</p>
            </div>
            <div class="hp-reason">
                <h3>Modern and organized</h3>
                <p>A smooth clinic experience with well-kept patient records.</p>
            </div>
        </div>
    </section>

    <!-- Final call to action -->
    <section class="hp-cta-section">
        <div class="hp-wrap">
            <div class="hp-cta">
                <div>
                    <h2>Take the next step toward better movement and less pain</h2>
                    <p>Book an appointment and let our team help you plan your care.</p>
                </div>
                <div class="hp-cta-actions">
                    <a class="hp-btn hp-btn-light" href="<?=BASE_URL?>appointment.php">Book an appointment</a>
                    <a class="hp-btn hp-btn-outline-light" href="<?=BASE_URL?>contact.php">Contact us</a>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="hp-footer">© <?=date('Y')?> AmarDayal Joint &amp; Pain Care Center</footer>
</body>
</html>