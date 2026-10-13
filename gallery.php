<?php
require_once 'config.php';

$rows = $conn->query("
    SELECT *
    FROM clinic_gallery
    ORDER BY id DESC
");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Clinic Gallery | AmarDayal Clinic</title>

    <link rel="stylesheet" href="Styles/style.css">

    <style>
        .gallery-page {
            padding: 40px 20px 70px;
        }

        .gallery-container {
            max-width: 1200px;
            margin: auto;
        }

        .gallery-heading {
            text-align: center;
            margin-bottom: 35px;
        }

        .gallery-heading h1 {
            margin-bottom: 8px;
        }

        .gallery-heading p {
            color: #6b7280;
            margin: 0;
        }

        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
        }

        .gallery-card {
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            transition: all .25s ease;
        }

        .gallery-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.10);
        }

        .gallery-image {
            width: 100%;
            height: 230px;
            object-fit: cover;
            display: block;
            background: #f3f4f6;
        }

        .gallery-content {
            padding: 20px;
        }

        .gallery-content h3 {
            margin: 0 0 8px;
            font-size: 19px;
        }

        .gallery-content p {
            margin: 0;
            color: #6b7280;
            line-height: 1.6;
            font-size: 14px;
        }

        .empty-gallery {
            max-width: 550px;
            margin: 40px auto;
            text-align: center;
            padding: 50px 25px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
        }

        .empty-gallery .icon {
            font-size: 45px;
            margin-bottom: 15px;
        }

        @media (max-width: 600px) {
            .gallery-page {
                padding: 25px 15px 50px;
            }

            .gallery {
                grid-template-columns: 1fr;
            }

            .gallery-image {
                height: 210px;
            }
        }
    </style>
</head>

<body>

<?php nav(); ?>

<main class="gallery-page">

    <div class="gallery-container">

        <div class="gallery-heading">
            <h1>Clinic Gallery</h1>
            <p>
                Explore AmarDayal Clinic and our patient care environment.
            </p>
        </div>

        <?php if ($rows && $rows->num_rows > 0): ?>

            <div class="gallery">

                <?php while ($r = $rows->fetch_assoc()): ?>

                    <article class="gallery-card">

                        <img
                            class="gallery-image"
                            src="img/<?= e($r['filename']) ?>"
                            alt="<?= e($r['title']) ?>"
                            loading="lazy"
                            onerror="this.style.display='none';"
                        >

                        <div class="gallery-content">

                            <h3>
                                <?= e($r['title']) ?>
                            </h3>

                            <?php if (!empty($r['caption'])): ?>
                                <p>
                                    <?= e($r['caption']) ?>
                                </p>
                            <?php endif; ?>

                        </div>

                    </article>

                <?php endwhile; ?>

            </div>

        <?php else: ?>

            <div class="empty-gallery">

                <div class="icon">🖼️</div>

                <h2>No Gallery Images</h2>

                <p class="muted">
                    Clinic gallery images will appear here once they are
                    added by the administrator.
                </p>

            </div>

        <?php endif; ?>

    </div>

</main>

<footer class="footer">
    © <?= date('Y') ?> AmarDayal Clinic. All rights reserved.
</footer>

</body>
</html>