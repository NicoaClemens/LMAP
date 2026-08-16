<?php
require __DIR__ . '/auth/app.php';
lmap_require_login();

$user = lmap_current_user();
require __DIR__ . '/windows/header.php';
require __DIR__ . '/windows/layers.php';
require __DIR__ . '/windows/edit_controls.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LMAP</title>
    <link rel="stylesheet" href="/assets/lmap.css">
</head>
<body>
    <div class="lmap-shell">
        <?php lmap_render_header($user); ?>

        <div class="lmap-body">
            <?php lmap_render_layers(); ?>

            <main class="lmap-main">
                <div class="lmap-view">
                    <?php require __DIR__ . '/windows/window.php'; ?>
                </div>
            </main>

            <?php lmap_render_controls(); ?>
        </div>
    </div>
</body>
</html>