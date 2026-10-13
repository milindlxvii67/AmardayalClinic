<?php

require_once '../config.php';

require_staff(['Admin']);

$msg = '';
$error = '';

/* =========================================================
   GALLERY DATABASE SETUP
========================================================= */

$conn->query("CREATE TABLE IF NOT EXISTS clinic_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL DEFAULT '',
    description TEXT NULL,
    image VARCHAR(255) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function website_column_exists(mysqli $db, string $table, string $column): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    $r = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    $exists = $r && $r->num_rows > 0;
    if ($r) { $r->free(); }
    return $exists;
}

$galleryColumns = [
    'title' => "VARCHAR(180) NOT NULL DEFAULT ''",
    'description' => 'TEXT NULL',
    'image' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'display_order' => 'INT NOT NULL DEFAULT 0',
    'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
];
foreach ($galleryColumns as $column => $definition) {
    if (!website_column_exists($conn, 'clinic_gallery', $column)) {
        $conn->query("ALTER TABLE clinic_gallery ADD COLUMN `$column` $definition");
    }
}

/* =========================================================
   ADD GALLERY IMAGE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_gallery'])) {
    $title = trim($_POST['gallery_title'] ?? '');
    $description = trim($_POST['gallery_description'] ?? '');
    $displayOrder = (int)($_POST['gallery_display_order'] ?? 0);
    $imageName = null;

    if (!isset($_FILES['gallery_image']) || $_FILES['gallery_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a gallery image.';
    } elseif ($_FILES['gallery_image']['error'] !== UPLOAD_ERR_OK) {
        $error = 'There was a problem uploading the gallery image.';
    } elseif ($_FILES['gallery_image']['size'] > 8 * 1024 * 1024) {
        $error = 'Gallery image must be smaller than 8 MB.';
    } else {
        $tmp = $_FILES['gallery_image']['tmp_name'];
        $extension = strtolower(pathinfo($_FILES['gallery_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : '';
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($extension, $allowed, true) || ($mime !== '' && !in_array($mime, $allowedMime, true))) {
            $error = 'Only JPG, JPEG, PNG and WEBP images are allowed.';
        } elseif (@getimagesize($tmp) === false) {
            $error = 'The uploaded file is not a valid image.';
        } else {
            $uploadDir = dirname(__DIR__) . '/img/gallery/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                $error = 'Unable to create the gallery upload folder.';
            } else {
                $imageName = 'gallery_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
                if (!move_uploaded_file($tmp, $uploadDir . $imageName)) {
                    $error = 'Unable to save the gallery image.';
                    $imageName = null;
                }
            }
        }
    }

    if ($error === '' && $imageName !== null) {
        $stmt = $conn->prepare("INSERT INTO clinic_gallery (title, description, image, display_order, is_active) VALUES (?, ?, ?, ?, 1)");
        if ($stmt) {
            $stmt->bind_param('sssi', $title, $description, $imageName, $displayOrder);
            if ($stmt->execute()) {
                $msg = 'Gallery image added successfully.';
            } else {
                @unlink(dirname(__DIR__) . '/img/gallery/' . $imageName);
                $error = 'Unable to add gallery image: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            @unlink(dirname(__DIR__) . '/img/gallery/' . $imageName);
            $error = 'Unable to prepare the gallery insert query.';
        }
    }
}

/* =========================================================
   DELETE GALLERY IMAGE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_gallery'])) {
    $galleryId = (int)($_POST['gallery_id'] ?? 0);
    if ($galleryId > 0) {
        $stmt = $conn->prepare("SELECT image FROM clinic_gallery WHERE id = ? LIMIT 1");
        $image = null;
        if ($stmt) {
            $stmt->bind_param('i', $galleryId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $image = $row['image'] ?? null;
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM clinic_gallery WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $galleryId);
            if ($stmt->execute()) {
                if ($image) {
                    $path = dirname(__DIR__) . '/img/gallery/' . basename($image);
                    if (is_file($path)) { @unlink($path); }
                }
                $msg = 'Gallery image deleted successfully.';
            } else {
                $error = 'Unable to delete gallery image.';
            }
            $stmt->close();
        }
    }
}

/* =========================================================
   TOGGLE GALLERY VISIBILITY
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gallery'])) {
    $galleryId = (int)($_POST['gallery_id'] ?? 0);
    if ($galleryId > 0) {
        $stmt = $conn->prepare("UPDATE clinic_gallery SET is_active = IF(is_active=1,0,1) WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('i', $galleryId);
            $stmt->execute();
            $stmt->close();
            $msg = 'Gallery visibility updated.';
        }
    }
}


/* =========================================================
   WEBSITE CONTENT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_website'])
) {

    $keys = [
        'home_title',
        'home_subtitle',
        'home_button',
        'about_description',
        'doctor_name',
        'doctor_speciality',
        'doctor_description',
        'contact_address',
        'contact_phone',
        'contact_hours'
    ];

    foreach ($keys as $k) {

        $v = trim(
            $_POST[$k] ?? ''
        );

        $s = $conn->prepare("
            INSERT INTO site_content
            (
                content_key,
                content_value
            )
            VALUES
            (?, ?)

            ON DUPLICATE KEY UPDATE
            content_value = VALUES(content_value)
        ");

        $s->bind_param(
            'ss',
            $k,
            $v
        );

        $s->execute();

        $s->close();
    }

    $msg = 'Website content updated.';
}


/* =========================================================
   ADD STAFF
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['add_staff'])
) {

    $name =
        trim($_POST['name'] ?? '');

    $position =
        trim($_POST['position'] ?? '');

    $specialization =
        trim($_POST['specialization'] ?? '');

    $description =
        trim($_POST['description'] ?? '');

    $displayOrder =
        (int)($_POST['display_order'] ?? 0);


    /* REQUIRED FIELDS */

    if (
        $name === ''
        ||
        $position === ''
    ) {

        $error =
            'Staff name and position are required.';

    }


    /* PHOTO */

    $photoName = null;


    if (
        $error === ''
        &&
        isset($_FILES['photo'])
        &&
        $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES['photo']['error']
            !== UPLOAD_ERR_OK
        ) {

            $error =
                'There was a problem uploading the photo.';

        } else {

            $extension =
                strtolower(
                    pathinfo(
                        $_FILES['photo']['name'],
                        PATHINFO_EXTENSION
                    )
                );


            $allowedExtensions = [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ];


            if (
                !in_array(
                    $extension,
                    $allowedExtensions,
                    true
                )
            ) {

                $error =
                    'Only JPG, JPEG, PNG and WEBP images are allowed.';

            }


            if (
                $error === ''
                &&
                $_FILES['photo']['size']
                > 5 * 1024 * 1024
            ) {

                $error =
                    'Photo must be smaller than 5 MB.';

            }


            if ($error === '') {

                $uploadDir =
                    dirname(__DIR__)
                    . '/img/doctors/';


                if (
                    !is_dir($uploadDir)
                ) {

                    mkdir(
                        $uploadDir,
                        0755,
                        true
                    );

                }


                $photoName =
                    'staff_'
                    . time()
                    . '_'
                    . bin2hex(
                        random_bytes(4)
                    )
                    . '.'
                    . $extension;


                if (
                    !move_uploaded_file(
                        $_FILES['photo']['tmp_name'],
                        $uploadDir . $photoName
                    )
                ) {

                    $error =
                        'Unable to save the uploaded photo.';

                    $photoName = null;
                }

            }

        }

    }


    /* INSERT STAFF */

    if ($error === '') {

        $s = $conn->prepare("
            INSERT INTO clinic_staff
            (
                name,
                position,
                specialization,
                description,
                photo,
                display_order,
                is_active
            )
            VALUES
            (?, ?, ?, ?, ?, ?, 1)
        ");


        $s->bind_param(
            'sssssi',
            $name,
            $position,
            $specialization,
            $description,
            $photoName,
            $displayOrder
        );


        if ($s->execute()) {

            $msg =
                'Staff member added successfully.';

        } else {

            $error =
                'Unable to add staff member: '
                . $s->error;

        }


        $s->close();

    }

}


/* =========================================================
   DELETE STAFF
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['delete_staff'])
) {

    $staffId =
        (int)($_POST['staff_id'] ?? 0);


    if ($staffId > 0) {

        /* Get photo first */

        $s = $conn->prepare("
            SELECT photo
            FROM clinic_staff
            WHERE id = ?
            LIMIT 1
        ");

        $s->bind_param(
            'i',
            $staffId
        );

        $s->execute();

        $result =
            $s->get_result();

        $staff =
            $result
            ? $result->fetch_assoc()
            : null;

        $s->close();


        /* Delete database record */

        $s = $conn->prepare("
            DELETE FROM clinic_staff
            WHERE id = ?
        ");

        $s->bind_param(
            'i',
            $staffId
        );

        $s->execute();

        $s->close();


        /* Delete photo */

        if (
            $staff
            &&
            !empty($staff['photo'])
        ) {

            $photoPath =
                dirname(__DIR__)
                . '/img/doctors/'
                . $staff['photo'];


            if (
                is_file($photoPath)
            ) {

                unlink($photoPath);

            }

        }


        $msg =
            'Staff member deleted successfully.';

    }

}


/* =========================================================
   LOAD STAFF
========================================================= */

$staffMembers = [];

$result = $conn->query("
    SELECT
        id,
        name,
        position,
        specialization,
        description,
        photo,
        display_order,
        is_active
    FROM clinic_staff
    ORDER BY
        display_order ASC,
        id ASC
");


if ($result) {

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $staffMembers[] =
            $row;

    }

}

$galleryItems = [];
$galleryResult = $conn->query("SELECT id, title, description, image, display_order, is_active FROM clinic_gallery ORDER BY display_order ASC, id DESC");
if ($galleryResult) {
    while ($row = $galleryResult->fetch_assoc()) {
        $galleryItems[] = $row;
    }
}

?>


<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    Website Manager
</title>

<link
    rel="stylesheet"
    href="../Styles/style.css"
>


<style>

/* =========================================================
   ADMIN PAGE LAYOUT
   Keep the shared fixed sidebar and page content aligned.
   This page must not inherit an old .layout/.content rule from
   Styles/style.css that can push the website manager downward.
========================================================= */

html,
body {
    width: 100%;
    min-height: 100%;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden;
}

body {
    background: #f4f7fb;
    color: #10233f;
    font-family: "Segoe UI", Arial, sans-serif;
}

.layout {
    display: block !important;
    position: relative;
    width: 100%;
    min-height: 100vh;
    margin: 0;
    padding: 0;
}

.layout > .content {
    position: relative;
    display: block;
    width: calc(100% - 270px);
    max-width: none;
    min-width: 0;
    min-height: 100vh;
    margin: 0 0 0 270px !important;
    padding: 30px 34px 60px !important;
    box-sizing: border-box;
}

.website-section {
    margin-bottom: 28px;
}

.website-section h2 {
    margin-bottom: 6px;
}

.website-section > p {
    color: #64748b;
    margin-bottom: 24px;
}


.staff-grid {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(240px, 1fr)
        );

    gap: 20px;

    margin-top: 25px;
}


.staff-card {
    background: #fff;

    border: 1px solid #e2e8f0;

    border-radius: 16px;

    overflow: hidden;

    box-shadow:
        0 8px 24px
        rgba(15, 47, 85, 0.06);
}


.staff-photo {
    width: 100%;
    height: 230px;

    background: #eef4fb;

    overflow: hidden;
}


.staff-photo img {
    width: 100%;
    height: 100%;

    object-fit: cover;

    display: block;
}


.staff-no-photo {
    height: 100%;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 55px;

    color: #94a3b8;
}


.staff-info {
    padding: 18px;
}


.staff-info h3 {
    margin: 0 0 6px;

    color: #0f2f55;
}


.staff-position {
    color: #2563eb;

    font-weight: 700;

    font-size: 14px;

    margin-bottom: 8px;
}


.staff-specialization {
    color: #64748b;

    font-size: 13px;

    margin-bottom: 10px;
}


.staff-description {
    color: #64748b;

    font-size: 13px;

    line-height: 1.6;
}


.staff-actions {
    margin-top: 16px;
}


.delete-btn {
    background: #fee2e2;

    color: #dc2626;

    border: 0;

    padding: 9px 14px;

    border-radius: 8px;

    cursor: pointer;

    font-weight: 700;
}


.gallery-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-top:25px}
.gallery-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(15,47,85,.06)}
.gallery-image{height:210px;background:#eef4fb;overflow:hidden}.gallery-image img{width:100%;height:100%;object-fit:cover;display:block}
.gallery-info{padding:16px}.gallery-info h3{margin:0 0 6px;color:#0f2f55}.gallery-info p{margin:0;color:#64748b;font-size:13px;line-height:1.55}.gallery-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.gallery-status{font-size:11px;font-weight:800;padding:5px 9px;border-radius:999px;background:#ecfdf5;color:#047857}.gallery-status.off{background:#fef2f2;color:#b91c1c}
.gallery-form-note{padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;color:#64748b;font-size:12px;line-height:1.6;margin-bottom:16px}

@media (max-width: 900px) {
    .layout > .content {
        width: calc(100% - 78px);
        margin-left: 78px !important;
        padding: 24px 20px 50px !important;
    }
}

@media (max-width: 520px) {
    .layout > .content {
        width: calc(100% - 66px);
        margin-left: 66px !important;
        padding: 20px 12px 40px !important;
    }
}

</style>

</head>


<body>


<div class="layout">


<?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="content">


        <h1>
            Website Manager
        </h1>


        <?php if ($msg): ?>

            <div class="alert success">

                <?= e($msg) ?>

            </div>

        <?php endif; ?>


        <?php if ($error): ?>

            <div class="alert error">

                <?= e($error) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             WEBSITE CONTENT
        ================================================== -->

        <div class="card website-section">

            <h2>
                Website Content
            </h2>

            <p>
                Update the main content displayed on
                the AmarDayal Clinic website.
            </p>


            <form
                method="post"
            >

                <div class="form-grid">


                    <?php

                    $contentFields = [

                        'home_title',
                        'home_subtitle',
                        'home_button',
                        'doctor_name',
                        'doctor_speciality',
                        'contact_phone',
                        'contact_hours'

                    ];

                    ?>


                    <?php foreach (
                        $contentFields
                        as $k
                    ): ?>

                        <div class="field">

                            <label>

                                <?= e(
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $k
                                        )
                                    )
                                ) ?>

                            </label>


                            <input
                                name="<?= e($k) ?>"
                                value="<?= e(
                                    site_content($k)
                                ) ?>"
                            >

                        </div>

                    <?php endforeach; ?>


                    <div class="field full">

                        <label>
                            About Description
                        </label>


                        <textarea
                            name="about_description"
                            rows="4"
                        ><?= e(
                            site_content(
                                'about_description'
                            )
                        ) ?></textarea>

                    </div>


                    <div class="field full">

                        <label>
                            Doctor Description
                        </label>


                        <textarea
                            name="doctor_description"
                            rows="4"
                        ><?= e(
                            site_content(
                                'doctor_description'
                            )
                        ) ?></textarea>

                    </div>


                    <div class="field full">

                        <label>
                            Contact Address
                        </label>


                        <textarea
                            name="contact_address"
                            rows="3"
                        ><?= e(
                            site_content(
                                'contact_address'
                            )
                        ) ?></textarea>

                    </div>


                </div>


                <br>


                <button
                    type="submit"
                    name="save_website"
                    class="btn success"
                >
                    Save Website Content
                </button>


            </form>

        </div>


        <!-- =================================================
             WEBSITE GALLERY
        ================================================== -->

        <div class="card website-section">
            <h2>Website Gallery</h2>
            <p>Add clinic photographs, facilities, events and other images that can be displayed on the public website.</p>

            <div class="gallery-form-note">
                Recommended: clear JPG, PNG or WEBP images. Maximum 8 MB per image. The image is stored in <strong>img/gallery/</strong> and its details are stored in the <strong>clinic_gallery</strong> table.
            </div>

            <form method="post" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="field">
                        <label>Image Title</label>
                        <input type="text" name="gallery_title" placeholder="e.g. AmarDayal Clinic Reception">
                    </div>
                    <div class="field">
                        <label>Display Order</label>
                        <input type="number" name="gallery_display_order" value="0" min="0">
                    </div>
                    <div class="field full">
                        <label>Gallery Image *</label>
                        <input type="file" name="gallery_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="field full">
                        <label>Description</label>
                        <textarea name="gallery_description" rows="3" placeholder="Short description of this photograph"></textarea>
                    </div>
                </div>
                <br>
                <button type="submit" name="add_gallery" class="btn success">＋ Add Image to Gallery</button>
            </form>

            <?php if ($galleryItems): ?>
                <div class="gallery-grid">
                    <?php foreach ($galleryItems as $item): ?>
                        <article class="gallery-card">
                            <div class="gallery-image">
                                <img src="../img/gallery/<?= e($item['image']) ?>" alt="<?= e($item['title'] ?: 'Clinic gallery image') ?>">
                            </div>
                            <div class="gallery-info">
                                <h3><?= e($item['title'] ?: 'Clinic Gallery') ?></h3>
                                <?php if (!empty($item['description'])): ?><p><?= e($item['description']) ?></p><?php endif; ?>
                                <div class="gallery-actions">
                                    <span class="gallery-status <?= (int)$item['is_active'] === 1 ? '' : 'off' ?>">
                                        <?= (int)$item['is_active'] === 1 ? 'Visible on website' : 'Hidden from website' ?>
                                    </span>
                                </div>
                                <div class="gallery-actions">
                                    <form method="post"><input type="hidden" name="gallery_id" value="<?= (int)$item['id'] ?>"><button class="btn" type="submit" name="toggle_gallery"><?= (int)$item['is_active'] === 1 ? 'Hide' : 'Show' ?></button></form>
                                    <form method="post" onsubmit="return confirm('Delete this gallery image?');"><input type="hidden" name="gallery_id" value="<?= (int)$item['id'] ?>"><button class="delete-btn" type="submit" name="delete_gallery">Delete</button></form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="notice">No gallery images have been added yet.</div>
            <?php endif; ?>
        </div>

        <!-- =================================================
             STAFF MANAGEMENT
        ================================================== -->

        <div class="card website-section">

            <h2>
                Staff &amp; Doctors
            </h2>

            <p>
                Add doctors and clinic staff members
                who should appear on the public website.
            </p>


            <form
                method="post"
                enctype="multipart/form-data"
            >

                <div class="form-grid">


                    <!-- NAME -->

                    <div class="field">

                        <label>
                            Staff Name *
                        </label>


                        <input
                            type="text"
                            name="name"
                            placeholder="Dr. John Doe"
                            required
                        >

                    </div>


                    <!-- POSITION -->

                    <div class="field">

                        <label>
                            Position / Designation *
                        </label>


                        <input
                            type="text"
                            name="position"
                            placeholder="Joint & Pain Specialist"
                            required
                        >

                    </div>


                    <!-- SPECIALIZATION -->

                    <div class="field">

                        <label>
                            Specialization
                        </label>


                        <input
                            type="text"
                            name="specialization"
                            placeholder="Pain Management & Orthopaedics"
                        >

                    </div>


                    <!-- DISPLAY ORDER -->

                    <div class="field">

                        <label>
                            Display Order
                        </label>


                        <input
                            type="number"
                            name="display_order"
                            value="0"
                            min="0"
                        >

                    </div>


                    <!-- PHOTO -->

                    <div class="field full">

                        <label>
                            Staff Photo
                        </label>


                        <input
                            type="file"
                            name="photo"
                            accept=".jpg,.jpeg,.png,.webp"
                        >


                        <small>
                            JPG, JPEG, PNG or WEBP.
                            Maximum 5 MB.
                        </small>

                    </div>


                    <!-- DESCRIPTION -->

                    <div class="field full">

                        <label>
                            Description
                        </label>


                        <textarea
                            name="description"
                            rows="4"
                            placeholder="Short description about this staff member"
                        ></textarea>

                    </div>


                </div>


                <br>


                <button
                    type="submit"
                    name="add_staff"
                    class="btn success"
                >
                    + Add Staff Member
                </button>


            </form>


            <!-- EXISTING STAFF -->

            <?php if ($staffMembers): ?>

                <div class="staff-grid">


                    <?php foreach (
                        $staffMembers
                        as $member
                    ): ?>

                        <div class="staff-card">


                            <div class="staff-photo">

                                <?php if (
                                    !empty(
                                        $member['photo']
                                    )
                                ): ?>

                                    <img
                                        src="../img/doctors/<?= e(
                                            $member['photo']
                                        ) ?>"
                                        alt="<?= e(
                                            $member['name']
                                        ) ?>"
                                    >

                                <?php else: ?>

                                    <div class="staff-no-photo">
                                        👨‍⚕️
                                    </div>

                                <?php endif; ?>

                            </div>


                            <div class="staff-info">


                                <h3>

                                    <?= e(
                                        $member['name']
                                    ) ?>

                                </h3>


                                <div class="staff-position">

                                    <?= e(
                                        $member['position']
                                    ) ?>

                                </div>


                                <?php if (
                                    !empty(
                                        $member[
                                            'specialization'
                                        ]
                                    )
                                ): ?>

                                    <div
                                        class="staff-specialization"
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
                                        $member[
                                            'description'
                                        ]
                                    )
                                ): ?>

                                    <div
                                        class="staff-description"
                                    >

                                        <?= e(
                                            $member[
                                                'description'
                                            ]
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <div class="staff-actions">

                                    <form
                                        method="post"
                                        onsubmit="
                                            return confirm(
                                                'Delete this staff member?'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="staff_id"
                                            value="<?= (int)$member['id'] ?>"
                                        >


                                        <button
                                            type="submit"
                                            name="delete_staff"
                                            class="delete-btn"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </div>


                            </div>

                        </div>

                    <?php endforeach; ?>


                </div>

            <?php else: ?>

                <div class="notice">

                    No staff members have been added yet.

                </div>

            <?php endif; ?>


        </div>


    </main>

</div>


</body>

</html>