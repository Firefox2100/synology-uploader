<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SynoUploader\App;
use SynoUploader\Http\Request;

#[RunTestsInSeparateProcesses]
final class AppTest extends TestCase
{
    private string $root;
    private App $app;

    protected function setUp(): void
    {
        $this->root = TestConfig::tempDir();
        $this->app = new App(TestConfig::make($this->root), dirname(__DIR__, 2));
        $_COOKIE = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $this->app->session->close();
        TestConfig::removeDir($this->root);
        $_COOKIE = [];
        $_POST = [];
    }

    public function testRoutingAndAnonymousPage(): void
    {
        self::assertSame(404, $this->app->dispatch('missing', new Request('GET'))->status);
        $method = $this->app->dispatch('index', new Request('POST'));
        self::assertSame(405, $method->status);
        self::assertSame('GET, HEAD', $method->headers['Allow']);

        $page = $this->app->dispatch('index', new Request('GET'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Sign in', $page->body);
        self::assertDirectoryDoesNotExist($this->root . '/var/sessions');
    }

    public function testUploadPreflightAndAnonymousFailures(): void
    {
        $options = $this->app->dispatch('upload', new Request('OPTIONS'));
        self::assertSame(204, $options->status);
        self::assertSame('1.0.0', $options->headers['Tus-Version']);

        $badOrigin = $this->app->dispatch('upload', new Request('POST', ['Origin' => 'https://evil.example']));
        self::assertSame(403, $badOrigin->status);
        self::assertSame('{"error":"forbidden_origin"}', $badOrigin->body);

        $anonymous = $this->app->dispatch('upload', new Request('POST', ['Origin' => 'https://upload.example.com']));
        self::assertSame(401, $anonymous->status);
        self::assertSame('1.0.0', $anonymous->headers['Tus-Resumable']);
    }

    public function testAuthenticatedPageAndUploadCreation(): void
    {
        $this->app->session->start();
        $this->app->session->login('alice', 'id-token');
        $_COOKIE[$this->app->session->cookieName()] = session_id();

        $page = $this->app->dispatch('index', new Request('GET'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('alice', $page->body);
        self::assertStringContainsString('upload.php', $page->body);

        $csrf = $this->app->session->csrfToken();
        $created = $this->app->dispatch('upload', new Request('POST', [
            'Origin' => 'https://upload.example.com',
            'X-CSRF-Token' => $csrf,
            'Tus-Resumable' => '1.0.0',
            'Upload-Length' => '3',
            'Upload-Metadata' => 'filename ' . base64_encode('test.txt'),
        ]));
        self::assertSame(201, $created->status, $created->body);
        self::assertMatchesRegularExpression('~^/upload\.php\?id=[a-f0-9]{32}$~', $created->headers['Location']);
    }

    public function testAuthMethodAndInvalidLogoutAreRejected(): void
    {
        $method = $this->app->dispatch('auth', new Request('PUT'));
        self::assertSame(405, $method->status);
        self::assertSame('GET, POST', $method->headers['Allow']);

        $_POST['csrf'] = 'wrong';
        $logout = $this->app->dispatch('auth', new Request('POST', ['Origin' => 'https://upload.example.com'], ['action' => 'logout']));
        self::assertSame(403, $logout->status);
        self::assertStringContainsString('Sign-out failed', $logout->body);
    }
}
