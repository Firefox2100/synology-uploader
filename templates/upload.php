<?php
/** @var array<string, mixed> $clientConfig */
require __DIR__ . '/_head.php';
?>
<section class="uploader">
    <div id="notice" class="notice" hidden></div>
    <div id="uploader"></div>
    <p class="hint">
        Large files are sent in chunks and resume automatically after network errors.
        If you close the page, add the same file again to continue where it stopped.
    </p>
</section>
<script type="application/json" id="app-config"><?= json_encode($clientConfig, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script type="module" src="<?= $e($basePath) ?>assets/app.js?v=<?= $e($assetVersion) ?>"></script>
<?php require __DIR__ . '/_foot.php'; ?>
