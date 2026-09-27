<?php

declare(strict_types=1);

namespace SynoUploader\Auth;

/**
 * How to end the user's session at the SSO server after the local logout.
 */
final class SsoLogout
{
    /** Top-level redirect; the provider sends the browser back to base_url. */
    public const REDIRECT = 'redirect';
    /** Load the URL in a hidden iframe (Synology SSO Server doesn't redirect back). */
    public const IFRAME = 'iframe';

    public function __construct(public readonly string $type, public readonly string $url)
    {
    }
}
