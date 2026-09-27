<?php require __DIR__ . '/_head.php'; ?>
<section class="card center">
    <h1><?= $e($title) ?></h1>
    <p><?= $e($message) ?></p>
    <?php if (!empty($retry)): ?>
        <a class="button" href="<?= $e($basePath) ?>auth.php">Try again</a>
    <?php else: ?>
        <a class="button" href="<?= $e($basePath) ?>">Back</a>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_foot.php'; ?>
