<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SynoUploader\Config;
use SynoUploader\Http\Request;
use SynoUploader\Http\Response;
use SynoUploader\Storage\FilenameSanitizer;
use SynoUploader\Storage\Finalizer;
use SynoUploader\Support\Logger;
use SynoUploader\Tus\TusServer;
use SynoUploader\Tus\UploadRepository;

final class TusServerTest extends TestCase
{
    private string $root;
    private Config $config;
    private int $now;

    protected function setUp(): void
    {
        $this->root = TestConfig::tempDir();
        mkdir($this->root . '/var', 0700, true);
        $this->config = TestConfig::make($this->root);
        $this->now = time();
    }

    protected function tearDown(): void
    {
        TestConfig::removeDir($this->root);
    }

    private function server(?Config $config = null): TusServer
    {
        $config ??= $this->config;
        $sanitizer = new FilenameSanitizer($config->maxFilenameBytes);

        return new TusServer(
            $config,
            new UploadRepository($config->stagingDir),
            new Finalizer($config->destinationDir, $config->userSubdirectory, $sanitizer),
            $sanitizer,
            new Logger($this->root . '/var/test.log'),
            '/upload.php',
            fn (): int => $this->now,
        );
    }

    /** @param array<string, string> $headers */
    private function request(string $method, array $headers = [], ?string $id = null, string $body = ''): Request
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        $headers += ['Tus-Resumable' => '1.0.0'];
        if ($method === 'PATCH' && !isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        return new Request($method, $headers, $id === null ? [] : ['id' => $id], $stream);
    }

    private function create(string $name = 'file.bin', int $size = 10, string $owner = 'alice', ?TusServer $server = null): string
    {
        $response = ($server ?? $this->server())->handle($this->request('POST', [
            'Upload-Length' => (string) $size,
            'Upload-Metadata' => 'filename ' . base64_encode($name) . ',filetype ' . base64_encode('application/octet-stream'),
        ]), $owner);
        self::assertSame(201, $response->status, $response->body);
        self::assertMatchesRegularExpression('~^/upload\.php\?id=[a-f0-9]{32}$~', $response->headers['Location']);

        return substr($response->headers['Location'], strlen('/upload.php?id='));
    }

    private function patch(string $id, int $offset, string $data, string $owner = 'alice', ?TusServer $server = null): Response
    {
        return ($server ?? $this->server())->handle($this->request('PATCH', [
            'Upload-Offset' => (string) $offset,
            'Content-Type' => 'application/offset+octet-stream',
        ], $id, $data), $owner);
    }

    public function testOptionsAdvertisesExtensions(): void
    {
        $response = $this->server()->handle(new Request('OPTIONS'), 'alice');
        self::assertSame(204, $response->status);
        self::assertSame('1.0.0', $response->headers['Tus-Version']);
        self::assertSame('creation,termination,expiration', $response->headers['Tus-Extension']);
    }

    public function testRejectsMissingTusResumable(): void
    {
        $response = $this->server()->handle(new Request('POST', ['Upload-Length' => '1']), 'alice');
        self::assertSame(412, $response->status);
    }

    public function testChunkedUploadCompletesIntoDestination(): void
    {
        $id = $this->create('video.mp4', 10);

        $head = $this->server()->handle($this->request('HEAD', [], $id), 'alice');
        self::assertSame(200, $head->status);
        self::assertSame('0', $head->headers['Upload-Offset']);
        self::assertSame('10', $head->headers['Upload-Length']);
        self::assertSame('no-store', $head->headers['Cache-Control']);

        $first = $this->patch($id, 0, '01234');
        self::assertSame(204, $first->status);
        self::assertSame('5', $first->headers['Upload-Offset']);
        self::assertFileDoesNotExist($this->root . '/dest/video.mp4');

        $second = $this->patch($id, 5, '56789');
        self::assertSame(204, $second->status);
        self::assertSame('10', $second->headers['Upload-Offset']);
        self::assertSame('0123456789', file_get_contents($this->root . '/dest/video.mp4'));
        self::assertFileDoesNotExist($this->root . '/staging/' . $id . '.part');

        // A retry after a lost response reports completion instead of starting over.
        $again = $this->server()->handle($this->request('HEAD', [], $id), 'alice');
        self::assertSame(200, $again->status);
        self::assertSame('10', $again->headers['Upload-Offset']);
        self::assertSame(409, $this->patch($id, 10, '')->status);
    }

    public function testNeverOverwritesExistingFiles(): void
    {
        file_put_contents($this->root . '/dest/doc.txt', 'original');
        $id = $this->create('doc.txt', 3);
        self::assertSame(204, $this->patch($id, 0, 'new')->status);
        self::assertSame('original', file_get_contents($this->root . '/dest/doc.txt'));
        self::assertSame('new', file_get_contents($this->root . '/dest/doc (1).txt'));
    }

    public function testSameNameConcurrentUploadsDoNotCollide(): void
    {
        $a = $this->create('same.bin', 3);
        $b = $this->create('same.bin', 3);
        self::assertNotSame($a, $b);
        $this->patch($a, 0, 'aaa');
        $this->patch($b, 0, 'bbb');
        $contents = [file_get_contents($this->root . '/dest/same.bin'), file_get_contents($this->root . '/dest/same (1).bin')];
        sort($contents);
        self::assertSame(['aaa', 'bbb'], $contents);
    }

    public function testOffsetMismatchIsConflict(): void
    {
        $id = $this->create('f.bin', 10);
        $this->patch($id, 0, 'abc');
        $response = $this->patch($id, 0, 'abc');
        self::assertSame(409, $response->status);
        self::assertSame('3', $response->headers['Upload-Offset']);
    }

    public function testRejectsChunkOverLimitAndPastLength(): void
    {
        $id = $this->create('f.bin', 10);
        $tooBig = $this->server()->handle($this->request('PATCH', [
            'Upload-Offset' => '0',
            'Content-Type' => 'application/offset+octet-stream',
            'Content-Length' => (string) (2 * 1024 * 1024 + 1),
        ], $id), 'alice');
        self::assertSame(413, $tooBig->status);

        self::assertSame(413, $this->patch($id, 0, str_repeat('x', 11))->status);
        self::assertSame(0, filesize($this->root . '/staging/' . $id . '.part'));
    }

    public function testRequiresOffsetContentTypeAndLength(): void
    {
        $id = $this->create('f.bin', 10);
        $server = $this->server();
        self::assertSame(415, $server->handle($this->request('PATCH', ['Upload-Offset' => '0', 'Content-Type' => 'text/plain'], $id, 'a'), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('PATCH', ['Content-Type' => 'application/offset+octet-stream'], $id, 'a'), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('PATCH', ['Upload-Offset' => '-1', 'Content-Type' => 'application/offset+octet-stream'], $id, 'a'), 'alice')->status);
        $noLength = new Request('PATCH', ['Tus-Resumable' => '1.0.0', 'Upload-Offset' => '0', 'Content-Type' => 'application/offset+octet-stream'], ['id' => $id]);
        self::assertSame(411, $server->handle($noLength, 'alice')->status);
    }

    public function testCreationValidation(): void
    {
        $server = $this->server();
        $meta = 'filename ' . base64_encode('ok.txt');
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Metadata' => $meta]), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Length' => 'abc', 'Upload-Metadata' => $meta]), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Length' => '5', 'Upload-Metadata' => 'filename ' . base64_encode('..')]), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Length' => '5']), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Length' => '5', 'Upload-Metadata' => $meta, 'Upload-Concat' => 'partial']), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Defer-Length' => '1', 'Upload-Metadata' => $meta]), 'alice')->status);
        self::assertSame(400, $server->handle($this->request('POST', ['Upload-Length' => '5', 'Upload-Metadata' => $meta, 'Content-Length' => '5'], null, 'hello'), 'alice')->status);
        // Clients can't pick the upload id.
        self::assertSame(405, $server->handle($this->request('POST', ['Upload-Length' => '5', 'Upload-Metadata' => $meta], str_repeat('a', 32)), 'alice')->status);
    }

    public function testMaxFileSize(): void
    {
        $config = TestConfig::make($this->root, ['upload' => ['max_file_size' => 100]]);
        $server = $this->server($config);
        self::assertSame('100', $server->options()->headers['Tus-Max-Size']);
        $response = $server->handle($this->request('POST', ['Upload-Length' => '101', 'Upload-Metadata' => 'filename ' . base64_encode('a')]), 'alice');
        self::assertSame(413, $response->status);
    }

    public function testActiveUploadLimitPerUser(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->create("f$i", 10);
        }
        $response = $this->server()->handle($this->request('POST', ['Upload-Length' => '1', 'Upload-Metadata' => 'filename ' . base64_encode('x')]), 'alice');
        self::assertSame(403, $response->status);
        // Other users are not affected.
        $this->create('mine', 10, 'bob');
    }

    public function testOtherUsersCannotSeeOrTouchUploads(): void
    {
        $id = $this->create('secret.bin', 10);
        $server = $this->server();
        self::assertSame(404, $server->handle($this->request('HEAD', [], $id), 'mallory')->status);
        self::assertSame(404, $this->patch($id, 0, 'x', 'mallory')->status);
        self::assertSame(404, $server->handle($this->request('DELETE', [], $id), 'mallory')->status);
        self::assertSame(0, filesize($this->root . '/staging/' . $id . '.part'));
    }

    public function testInvalidIdsAreNotFound(): void
    {
        $server = $this->server();
        foreach (['../../etc/passwd', 'ABCDEF', str_repeat('a', 31), str_repeat('a', 32) . "\n"] as $id) {
            self::assertSame(404, $server->handle($this->request('HEAD', [], $id), 'alice')->status);
        }
    }

    public function testTermination(): void
    {
        $id = $this->create('f.bin', 10);
        $this->patch($id, 0, 'abc');
        $response = $this->server()->handle($this->request('DELETE', [], $id), 'alice');
        self::assertSame(204, $response->status);
        self::assertFileDoesNotExist($this->root . '/staging/' . $id . '.part');
        self::assertFileDoesNotExist($this->root . '/staging/' . $id . '.json');
        self::assertSame(404, $this->server()->handle($this->request('HEAD', [], $id), 'alice')->status);
    }

    public function testLockedUploadReturns423(): void
    {
        $id = $this->create('f.bin', 10);
        $repo = new UploadRepository($this->config->stagingDir);
        $handle = $repo->lock($id);
        self::assertNotNull($handle);
        // flock is per open file description, so a second fopen() in this process conflicts.
        self::assertSame(423, $this->patch($id, 0, 'abc')->status);
        self::assertSame(423, $this->server()->handle($this->request('DELETE', [], $id), 'alice')->status);
        $repo->unlock($handle);
        self::assertSame(204, $this->patch($id, 0, 'abc')->status);
    }

    public function testZeroByteUpload(): void
    {
        $id = $this->create('empty.txt', 0);
        self::assertSame('', file_get_contents($this->root . '/dest/empty.txt'));
        $head = $this->server()->handle($this->request('HEAD', [], $id), 'alice');
        self::assertSame('0', $head->headers['Upload-Offset']);
    }

    public function testFinalizeFailureIsRetriedOnHead(): void
    {
        $config = TestConfig::make($this->root, ['destination_dir' => $this->root . '/missing', 'staging_dir' => $this->root . '/staging']);
        $server = $this->server($config);
        $id = $this->create('late.bin', 3, 'alice', $server);
        $response = $this->patch($id, 0, 'abc', 'alice', $server);
        self::assertSame(500, $response->status);

        mkdir($this->root . '/missing');
        $head = $server->handle($this->request('HEAD', [], $id), 'alice');
        self::assertSame(200, $head->status);
        self::assertSame('3', $head->headers['Upload-Offset']);
        self::assertSame('abc', file_get_contents($this->root . '/missing/late.bin'));
    }

    public function testUserSubdirectory(): void
    {
        $config = TestConfig::make($this->root, ['user_subdirectory' => true]);
        $server = $this->server($config);
        $id = $this->create('a.txt', 1, 'Alice', $server);
        $this->patch($id, 0, 'x', 'Alice', $server);
        self::assertSame('x', file_get_contents($this->root . '/dest/Alice/a.txt'));
    }

    public function testExpiryAndCleanup(): void
    {
        $stale = $this->create('stale.bin', 10);
        $this->patch($stale, 0, 'abc');
        $done = $this->create('done.bin', 1);
        $this->patch($done, 0, 'x');
        file_put_contents($this->root . '/staging/' . str_repeat('b', 32) . '.part', 'orphan');

        $this->now += $this->config->expirySeconds + 10;
        $server = $this->server();

        self::assertSame(404, $server->handle($this->request('HEAD', [], $stale), 'alice')->status);
        self::assertSame(1, $server->cleanup());
        self::assertSame([], glob($this->root . '/staging/*'));
        // The completed file itself is never touched by cleanup.
        self::assertSame('x', file_get_contents($this->root . '/dest/done.bin'));
    }
}
