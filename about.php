<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* -------------------------------------------------------------
   Public About page
   Staff and gallery content are loaded from the database so the
   Admin > Edit Website page can control what visitors see.
-------------------------------------------------------------- */

function about_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Keep the gallery available even if the administrator has not yet
   opened website.php. */
$conn->query("CREATE TABLE IF NOT EXISTS clinic_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL DEFAULT '',
    description TEXT NULL,
    image VARCHAR(255) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$staffMembers = [];
$staffResult = $conn->query("SELECT id, name, position, specialization, description, photo
                             FROM clinic_staff
                             WHERE is_active = 1
                             ORDER BY display_order ASC, id ASC");
if ($staffResult) {
    while ($row = $staffResult->fetch_assoc()) {
        $staffMembers[] = $row;
    }
}

$galleryItems = [];
$galleryResult = $conn->query("SELECT id, title, description, image
                               FROM clinic_gallery
                               WHERE is_active = 1
                               ORDER BY display_order ASC, id DESC");
if ($galleryResult) {
    while ($row = $galleryResult->fetch_assoc()) {
        $galleryItems[] = $row;
    }
}

$aboutDescription = trim((string)site_content('about_description'));
if ($aboutDescription === '') {
    $aboutDescription = 'AmarDayal Joint & Pain Management Center is a patient-focused healthcare clinic committed to organized, compassionate and professional care.';
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>About Us | AmarDayal Clinic</title>
<link rel="stylesheet" href="<?= BASE_URL ?>Styles/style.css">
<style>
.about-page{background:#f5f8fc;color:#10233f;min-height:100vh}
.about-wrap{width:min(1180px,calc(100% - 40px));margin:auto}
.about-hero{padding:90px 0;background:linear-gradient(135deg,#073b6c,#087c76);color:#fff}
.about-hero-grid{display:grid;grid-template-columns:1.3fr .7fr;gap:55px;align-items:center}
.about-eyebrow{display:inline-block;color:#7ff0e4;font-weight:800;letter-spacing:.12em;font-size:12px;margin-bottom:18px}
.about-hero h1{font-size:clamp(42px,6vw,72px);line-height:1.03;margin:0 0 22px;letter-spacing:-2px}
.about-hero h1 span{display:block;color:#8bf2e7}
.about-hero p{font-size:18px;line-height:1.75;max-width:700px;color:rgba(255,255,255,.9)}
.about-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:28px}
.about-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;border-radius:12px;text-decoration:none;font-weight:800}
.about-btn.primary{background:#fff;color:#075b69}.about-btn.secondary{border:1px solid rgba(255,255,255,.4);color:#fff}
.about-stat-card{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:24px;padding:28px;backdrop-filter:blur(12px)}
.about-stat-card strong{display:block;font-size:52px}.about-stat-card span{color:rgba(255,255,255,.8)}
.about-section{padding:75px 0}.section-label{font-size:12px;font-weight:900;letter-spacing:.12em;color:#0b7285;margin-bottom:10px}.about-section h2{font-size:clamp(30px,4vw,46px);margin:0 0 14px;color:#0f2f55}.about-lead{color:#64748b;line-height:1.8;max-width:850px}
.staff-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:24px;margin-top:32px}
.staff-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;overflow:hidden;box-shadow:0 12px 30px rgba(15,47,85,.07)}
.staff-photo{height:290px;background:#edf4fa}.staff-photo img{width:100%;height:100%;object-fit:cover;display:block}.staff-placeholder{height:100%;display:flex;align-items:center;justify-content:center;font-size:70px;color:#94a3b8}
.staff-body{padding:20px}.staff-body h3{margin:0 0 7px;color:#0f2f55;font-size:21px}.staff-position{color:#2563eb;font-weight:800;font-size:14px}.staff-specialization{margin-top:8px;color:#0b7285;font-size:13px;font-weight:700}.staff-description{margin-top:12px;color:#64748b;line-height:1.65;font-size:14px}
.gallery-section{background:#fff}.gallery-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:32px}.gallery-card{border-radius:20px;overflow:hidden;background:#f8fafc;border:1px solid #e2e8f0}.gallery-image{height:250px}.gallery-image img{width:100%;height:100%;object-fit:cover;display:block}.gallery-body{padding:17px}.gallery-body h3{margin:0 0 6px;color:#0f2f55}.gallery-body p{margin:0;color:#64748b;font-size:13px;line-height:1.6}
.empty-box{padding:35px;border:1px dashed #cbd5e1;border-radius:18px;background:#f8fafc;color:#64748b;text-align:center;margin-top:25px}
.about-cta{margin:0 0 75px;padding:35px 40px;border-radius:25px;background:linear-gradient(135deg,#0f2f55,#0b7285);color:#fff;display:flex;justify-content:space-between;gap:25px;align-items:center}.about-cta h2{color:#fff;margin:0 0 7px}.about-cta p{margin:0;color:rgba(255,255,255,.82)}
@media(max-width:800px){.about-hero-grid{grid-template-columns:1fr}.gallery-grid{grid-template-columns:1fr 1fr}.about-cta{flex-direction:column;align-items:flex-start}}
@media(max-width:520px){.about-wrap{width:min(100% - 24px,1180px)}.about-section{padding:55px 0}.gallery-grid{grid-template-columns:1fr}.about-hero{padding:65px 0}.about-hero h1{font-size:44px}}
</style>
</head>
<body>

<?php nav(); ?>

<main class="about-page">
    <section class="about-hero">
        <div class="about-wrap about-hero-grid">
            <div>
                <span class="about-eyebrow">ABOUT AMARDAYAL CLINIC</span>
                <h1>Caring for your health, <span>one patient at a time.</span></h1>
                <p><?= about_e($aboutDescription) ?></p>
                <div class="about-actions">
                    <a class="about-btn primary" href="<?= BASE_URL ?>appointment.php">Book an Appointment →</a>
                    <a class="about-btn secondary" href="<?= BASE_URL ?>contact.php">Contact Us</a>
                </div>
            </div>
            <div class="about-stat-card">
                <strong><?= count($staffMembers) ?></strong>
                <span>Active doctors &amp; staff members</span>
                <hr style="border:0;border-top:1px solid rgba(255,255,255,.2);margin:22px 0">
                <strong><?= count($galleryItems) ?></strong>
                <span>Published clinic gallery images</span>
            </div>
        </div>
    </section>

    <section class="about-section">
        <div class="about-wrap">
            <div class="section-label">OUR TEAM</div>
            <h2>Meet our doctors &amp; staff</h2>
            <p class="about-lead">Get to know the professionals who support consultation, treatment, recovery and patient care at AmarDayal Clinic.</p>

            <?php if ($staffMembers): ?>
                <div class="staff-grid">
                    <?php foreach ($staffMembers as $member): ?>
                        <article class="staff-card">
                            <div class="staff-photo">
                                <?php if (!empty($member['photo']) && is_file(__DIR__ . '/img/doctors/' . basename($member['photo']))): ?>
                                    <img src="<?= BASE_URL ?>img/doctors/<?= about_e($member['photo']) ?>" alt="<?= about_e($member['name']) ?>">
                                <?php else: ?>
                                    <div class="staff-placeholder">👨‍⚕️</div>
                                <?php endif; ?>
                            </div>
                            <div class="staff-body">
                                <h3><?= about_e($member['name']) ?></h3>
                                <div class="staff-position"><?= about_e($member['position']) ?></div>
                                <?php if (!empty($member['specialization'])): ?><div class="staff-specialization"><?= about_e($member['specialization']) ?></div><?php endif; ?>
                                <?php if (!empty($member['description'])): ?><div class="staff-description"><?= nl2br(about_e($member['description'])) ?></div><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box">Staff profiles will appear here when they are added from <strong>Admin → Edit Website</strong>.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="about-section gallery-section">
        <div class="about-wrap">
            <div class="section-label">CLINIC GALLERY</div>
            <h2>Inside AmarDayal Clinic</h2>
            <p class="about-lead">Explore photographs of the clinic, team, facilities, events and patient-care environment.</p>

            <?php if ($galleryItems): ?>
                <div class="gallery-grid">
                    <?php foreach ($galleryItems as $item): ?>
                        <?php $galleryPath = __DIR__ . '/img/gallery/' . basename($item['image']); ?>
                        <?php if (is_file($galleryPath)): ?>
                            <article class="gallery-card">
                                <div class="gallery-image">
                                    <img src="<?= BASE_URL ?>img/gallery/<?= about_e($item['image']) ?>" alt="<?= about_e($item['title'] ?: 'AmarDayal Clinic') ?>">
                                </div>
                                <?php if (!empty($item['title']) || !empty($item['description'])): ?>
                                    <div class="gallery-body">
                                        <?php if (!empty($item['title'])): ?><h3><?= about_e($item['title']) ?></h3><?php endif; ?>
                                        <?php if (!empty($item['description'])): ?><p><?= nl2br(about_e($item['description'])) ?></p><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box">Clinic gallery images will appear here when they are added from <strong>Admin → Edit Website → Website Gallery</strong>.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="about-section">
        <div class="about-wrap">
            <div class="about-cta">
                <div>
                    <h2>Ready to take the next step?</h2>
                    <p>Schedule an appointment with AmarDayal Clinic.</p>
                </div>
                <a class="about-btn primary" href="<?= BASE_URL ?>appointment.php">Book Appointment →</a>
            </div>
        </div>
    </section>
</main>

</body>
</html>
