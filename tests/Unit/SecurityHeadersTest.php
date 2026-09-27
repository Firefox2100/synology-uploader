<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SynoUploader\Http\Response;
use SynoUploader\Security\SecurityHeaders;

final class SecurityHeadersTest extends TestCase
{
    public function testStrictPolicy(): void
    {
        $headers = SecurityHeaders::apply(Response::html('x'), ['https://sso.example.com'], true)->headers;
        $csp = $headers['Content-Security-Policy'];

        self::assertStringStartsWith("default-src 'none';", $csp);
        self::assertMatchesRegularExpression("~script-src 'self' 'nonce-[A-Za-z0-9+/]{24}';~", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringContainsString("form-action 'self' https://sso.example.com;", $csp);
        self::assertStringContainsString('frame-src https://sso.example.com;', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("base-uri 'none'", $csp);
        self::assertSame('max-age=31536000', $headers['Strict-Transport-Security']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testNonceIsFreshAndNoHstsOverHttp(): void
    {
        $a = SecurityHeaders::apply(new Response())->headers;
        $b = SecurityHeaders::apply(new Response())->headers;
        self::assertNotSame($a['Content-Security-Policy'], $b['Content-Security-Policy']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $a);
        self::assertStringNotContainsString('frame-src', $a['Content-Security-Policy']);
    }
}
