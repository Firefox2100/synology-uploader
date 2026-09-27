<?php

declare(strict_types=1);

namespace SynoUploader\Security;

use SynoUploader\Http\Response;

final class SecurityHeaders
{
    /** @param list<string> $ssoOrigins */
    public static function apply(Response $response, array $ssoOrigins = [], bool $https = false): Response
    {
        // Cloudflare copies this nonce to its injected bot-detection script.
        $nonce = base64_encode(random_bytes(18));
        $formAction = implode(' ', array_merge(["'self'"], $ssoOrigins));
        $frameSrc = $ssoOrigins === [] ? '' : ' frame-src ' . implode(' ', $ssoOrigins) . ';';
        $csp = "default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'self'; img-src 'self' data: blob:; "
            . "font-src 'self'; connect-src 'self'; form-action $formAction;$frameSrc frame-ancestors 'none'; base-uri 'none'";

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $response->withHeaders($headers + $response->headers);
    }
}
