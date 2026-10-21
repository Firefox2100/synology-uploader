<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SynoUploader\Http\Request;
use SynoUploader\Http\Response;
use SynoUploader\Security\OriginCheck;

final class HttpSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_SERVER = [];
    }

    public function testRequestNormalizesInputsAndContentLength(): void
    {
        $request = new Request('POST', ['X-Test' => 'yes', 'Content-Length' => '42'], ['id' => 'abc', 'array' => []]);
        self::assertSame('yes', $request->header('x-test'));
        self::assertTrue($request->hasHeader('X-TEST'));
        self::assertSame('abc', $request->queryString('id'));
        self::assertNull($request->queryString('array'));
        self::assertSame(42, $request->contentLength());
        self::assertNull((new Request('POST', ['Content-Length' => '-1']))->contentLength());
        self::assertNull((new Request('POST'))->contentLength());
        self::assertIsResource((new Request('POST'))->body());
    }

    public function testRequestCanBeBuiltFromGlobals(): void
    {
        $_GET = ['id' => '42'];
        $_SERVER = [
            'REQUEST_METHOD' => 'patch',
            'HTTP_X_CSRF_TOKEN' => 'token',
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => '3',
            'REMOTE_ADDR' => '192.0.2.1',
            'IGNORED' => ['not a string'],
        ];
        $request = Request::fromGlobals();
        self::assertSame('PATCH', $request->method);
        self::assertSame('token', $request->header('X-CSRF-Token'));
        self::assertSame('text/plain', $request->header('Content-Type'));
        self::assertSame(3, $request->contentLength());
        self::assertSame('42', $request->queryString('id'));
        self::assertSame('192.0.2.1', $request->remoteAddr);
    }

    public function testOriginPolicy(): void
    {
        $check = new OriginCheck('https://upload.example.com');
        self::assertTrue($check->allows(new Request('GET')));
        self::assertTrue($check->allows(new Request('HEAD', ['Origin' => 'HTTPS://UPLOAD.EXAMPLE.COM/'])));
        self::assertFalse($check->allows(new Request('GET', ['Origin' => 'https://evil.example'])));
        self::assertTrue($check->allows(new Request('POST', ['Origin' => 'https://upload.example.com'])));
        self::assertFalse($check->allows(new Request('POST')));
        self::assertFalse($check->allows(new Request('POST', [
            'Origin' => 'https://upload.example.com',
            'Sec-Fetch-Site' => 'cross-site',
        ])));
    }

    public function testResponseFactoriesAndHeaderMerging(): void
    {
        $redirect = Response::redirect('/next', 302);
        self::assertSame(302, $redirect->status);
        self::assertSame('/next', $redirect->headers['Location']);

        $json = Response::json(['path' => '/a'], 201, ['X-Test' => 'yes'])->withHeaders(['Cache-Control' => 'private']);
        self::assertSame('{"path":"/a"}', $json->body);
        self::assertSame('private', $json->headers['Cache-Control']);
        self::assertSame('yes', $json->headers['X-Test']);

        $html = Response::html('<p>ok</p>');
        self::assertSame('text/html; charset=utf-8', $html->headers['Content-Type']);
        ob_start();
        $html->send();
        self::assertSame('<p>ok</p>', ob_get_clean());
    }
}
