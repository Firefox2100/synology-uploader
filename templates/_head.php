<?php
/** @var callable $e */
/** @var string $title */
/** @var string $basePath */
/** @var string $assetVersion */
$assetVersion ??= '0';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> · NAS Uploader</title>
    <link rel="stylesheet" href="<?= $e($basePath) ?>assets/app.css?v=<?= $e($assetVersion) ?>">
</head>
<body>
<header class="topbar">
    <span class="brand">NAS Uploader</span>
    <?php if (isset($user)): ?>
        <form class="session" method="post" action="<?= $e($basePath) ?>auth.php?action=logout">
            <span class="who">Signed in as <strong><?= $e($user) ?></strong></span>
            <input type="hidden" name="csrf" value="<?= $e($csrfToken) ?>">
            <button type="submit" class="link-button">Sign out</button>
        </form>
    <?php endif; ?>
</header>
<main>
