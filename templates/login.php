<?php require __DIR__ . '/_head.php'; ?>
<section class="card center">
    <h1>Upload files to the NAS</h1>
    <p>Sign in with your Synology account to continue.</p>
    <a class="button" href="<?= $e($basePath) ?>auth.php">Sign in with Synology SSO</a>
    <?php if (!empty($ssoLogoutUrl)): ?>
        <p class="hint">You have been signed out of the uploader and Synology SSO.</p>
        <iframe class="sso-logout" src="<?= $e($ssoLogoutUrl) ?>" title="Signing out of Synology SSO" hidden></iframe>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_foot.php'; ?>
