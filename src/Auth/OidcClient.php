<?php

declare(strict_types=1);

namespace SynoUploader\Auth;

use Jumbojett\OpenIDConnectClient;

final class OidcClient extends OpenIDConnectClient
{
    public function redirect(string $url): void
    {
        throw new RedirectRequired($url);
    }

    protected function startSession(): void
    {
        // Session lifecycle is managed by Session.
    }

    protected function commitSession(): void
    {
        // Session lifecycle is managed by Session.
    }
}
