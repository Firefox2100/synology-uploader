<?php

declare(strict_types=1);

namespace SynoUploader\Tus;

use Closure;
use SynoUploader\Config;
use SynoUploader\Http\Request;
use SynoUploader\Http\Response;
use SynoUploader\Storage\Finalizer;
use SynoUploader\Storage\FilenameSanitizer;
use SynoUploader\Support\Logger;
use Throwable;

final class TusServer
{
    public const VERSION = '1.0.0';
    public const EXTENSIONS = 'creation,termination,expiration';
    public const CONTENT_TYPE = 'application/offset+octet-stream';

    private const CLEANUP_ONE_IN = 20;

    private readonly Closure $clock;

    public function __construct(
        private readonly Config $config,
        private readonly UploadRepository $uploads,
        private readonly Finalizer $finalizer,
        private readonly FilenameSanitizer $sanitizer,
        private readonly Logger $logger,
        private readonly string $endpointPath,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function options(): Response
    {
        $headers = [
            'Tus-Version' => self::VERSION,
            'Tus-Extension' => self::EXTENSIONS,
        ];
        if ($this->config->maxFileSize > 0) {
            $headers['Tus-Max-Size'] = (string) $this->config->maxFileSize;
        }

        return $this->respond(204, $headers);
    }

    public function handle(Request $request, string $owner): Response
    {
        if ($request->method === 'OPTIONS') {
            return $this->options();
        }

        if ($request->header('Tus-Resumable') !== self::VERSION) {
            return $this->respond(412, ['Tus-Version' => self::VERSION]);
        }

        $id = $request->queryString('id');

        if ($id === null) {
            return $request->method === 'POST'
                ? $this->create($request, $owner)
                : $this->respond(405, ['Allow' => 'OPTIONS, POST']);
        }

        if (!UploadRepository::isValidId($id)) {
            return $this->respond(404);
        }

        return match ($request->method) {
            'HEAD' => $this->head($id, $owner),
            'PATCH' => $this->patch($request, $id, $owner),
            'DELETE' => $this->terminate($id, $owner),
            default => $this->respond(405, ['Allow' => 'OPTIONS, HEAD, PATCH, DELETE']),
        };
    }

    private function create(Request $request, string $owner): Response
    {
        if ($request->hasHeader('Upload-Concat')) {
            return $this->error(400, 'Concatenation is not supported.');
        }
        if ($request->hasHeader('Upload-Defer-Length')) {
            return $this->error(400, 'Deferred length is not supported.');
        }
        if (($request->contentLength() ?? 0) !== 0) {
            return $this->error(400, 'Creation with upload is not supported.');
        }

        $length = $request->header('Upload-Length');
        if ($length === null || preg_match('/^\d{1,18}$/D', $length) !== 1) {
            return $this->error(400, 'Missing or invalid Upload-Length.');
        }
        $size = (int) $length;
        if ($this->config->maxFileSize > 0 && $size > $this->config->maxFileSize) {
            return $this->error(413, 'File is larger than the allowed maximum.');
        }

        $metadata = Metadata::parse($request->header('Upload-Metadata'));
        if ($metadata === null) {
            return $this->error(400, 'Invalid Upload-Metadata.');
        }
        $filename = $metadata['filename'] ?? $metadata['name'] ?? '';
        $safeName = $this->sanitizer->sanitize($filename);
        if ($safeName === null) {
            return $this->error(400, 'Invalid filename.');
        }

        if (random_int(1, self::CLEANUP_ONE_IN) === 1) {
            $this->cleanup();
        }

        if ($this->activeUploadCount($owner) >= $this->config->maxActiveUploadsPerUser) {
            return $this->error(403, 'Too many unfinished uploads. Finish or cancel some first.');
        }

        $this->uploads->ensureDirectory();
        if (!$this->hasFreeSpace($size)) {
            return $this->error(507, 'Not enough free space on the NAS.');
        }

        $upload = new Upload(UploadRepository::newId(), $owner, $safeName, $size, $this->now());
        $this->uploads->create($upload);
        $this->logger->info('upload created', ['id' => $upload->id, 'user' => $owner, 'name' => $safeName, 'size' => $size]);

        // Zero-byte files are complete right away.
        if ($size === 0) {
            $failure = $this->withLock($upload, fn ($handle) => $this->finalize($upload));
            if ($failure !== null) {
                return $failure;
            }
        }

        return $this->respond(201, [
            'Location' => $this->endpointPath . '?id=' . $upload->id,
            'Upload-Expires' => $this->expiresHeader($this->now()),
        ]);
    }

    private function head(string $id, string $owner): Response
    {
        $upload = $this->findOwned($id, $owner);
        if ($upload === null) {
            return $this->respond(404);
        }

        if ($upload->isComplete()) {
            return $this->respond(200, [
                'Upload-Offset' => (string) $upload->size,
                'Upload-Length' => (string) $upload->size,
            ]);
        }

        $offset = $this->uploads->offset($id);
        if ($offset === null) {
            $this->uploads->delete($id);

            return $this->respond(404);
        }

        // All bytes arrived but moving the file failed earlier: try again now.
        if ($offset >= $upload->size) {
            $failure = $this->withLock($upload, fn ($handle) => $this->finalize($upload));
            if ($failure !== null) {
                return $failure;
            }
            $offset = $upload->size;
        }

        return $this->respond(200, [
            'Upload-Offset' => (string) $offset,
            'Upload-Length' => (string) $upload->size,
            'Upload-Expires' => $this->expiresHeader($this->uploads->lastActivity($upload)),
        ]);
    }

    private function patch(Request $request, string $id, string $owner): Response
    {
        if ($request->header('Content-Type') !== self::CONTENT_TYPE) {
            return $this->error(415, 'Content-Type must be ' . self::CONTENT_TYPE . '.');
        }
        $offsetHeader = $request->header('Upload-Offset');
        if ($offsetHeader === null || preg_match('/^\d{1,18}$/D', $offsetHeader) !== 1) {
            return $this->error(400, 'Missing or invalid Upload-Offset.');
        }
        $clientOffset = (int) $offsetHeader;
        $length = $request->contentLength();
        if ($length === null) {
            return $this->error(411, 'Content-Length is required.');
        }
        if ($length > $this->config->maxChunkSize) {
            return $this->error(413, 'Chunk is larger than the allowed maximum.');
        }

        $upload = $this->findOwned($id, $owner);
        if ($upload === null) {
            return $this->respond(404);
        }
        if ($upload->isComplete()) {
            // Probably a retry after the response to the final chunk was lost. 409 makes
            // the client send HEAD, which reports that the upload is complete.
            return $this->respond(409);
        }

        $handle = $this->uploads->lock($id);
        if ($handle === null) {
            return $this->respond(423);
        }

        try {
            $stat = fstat($handle);
            $current = $stat === false ? -1 : $stat['size'];
            if ($current !== $clientOffset) {
                return $this->respond(409, ['Upload-Offset' => (string) max(0, $current)]);
            }
            if ($current + $length > $upload->size) {
                return $this->error(413, 'Chunk exceeds the declared upload length.');
            }
            if (!$this->hasFreeSpace($length)) {
                return $this->error(507, 'Not enough free space on the NAS.');
            }

            // Keep going if the client disconnects, so the bookkeeping below always runs.
            ignore_user_abort(true);
            @set_time_limit(max(300, (int) ini_get('max_execution_time')));

            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new \RuntimeException('Cannot seek in staging file.');
            }
            $written = $length === 0 ? 0 : stream_copy_to_stream($request->body(), $handle, $length);
            if ($written === false || !fflush($handle)) {
                throw new \RuntimeException('Writing to staging file failed.');
            }
            $offset = $current + $written;

            if ($written < $length) {
                $this->logger->warning('short chunk', ['id' => $id, 'user' => $owner, 'expected' => $length, 'received' => $written]);
            }

            if ($offset === $upload->size) {
                $failure = $this->finalize($upload);
                if ($failure !== null) {
                    return $failure;
                }
            }

            return $this->respond(204, [
                'Upload-Offset' => (string) $offset,
                'Upload-Expires' => $this->expiresHeader($this->now()),
            ]);
        } finally {
            $this->uploads->unlock($handle);
        }
    }

    private function terminate(string $id, string $owner): Response
    {
        $upload = $this->findOwned($id, $owner);
        if ($upload === null || $upload->isComplete()) {
            return $this->respond(404);
        }

        $failure = $this->withLock($upload, function () use ($upload): ?Response {
            $this->uploads->delete($upload->id);
            $this->logger->info('upload terminated', ['id' => $upload->id, 'user' => $upload->owner]);

            return null;
        });

        return $failure ?? $this->respond(204);
    }

    public function cleanup(): int
    {
        $removed = 0;
        $now = $this->now();

        foreach ($this->uploads->all() as $upload) {
            if (!$this->isExpired($upload)) {
                continue;
            }
            if ($upload->isComplete()) {
                $this->uploads->delete($upload->id);
                continue;
            }
            if (!$this->deleteUnlessBusy($upload)) {
                continue;
            }
            $this->logger->info('upload expired', ['id' => $upload->id, 'user' => $upload->owner]);
            $removed++;
        }

        foreach ($this->uploads->orphans() as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime + $this->config->expirySeconds < $now) {
                @unlink($file);
            }
        }

        return $removed;
    }

    private function findOwned(string $id, string $owner): ?Upload
    {
        $upload = $this->uploads->find($id);
        // Do not reveal uploads owned by another user.
        if ($upload === null || !hash_equals($upload->owner, $owner)) {
            return null;
        }
        if (!$upload->isComplete() && $this->isExpired($upload)) {
            return null;
        }

        return $upload;
    }

    private function deleteUnlessBusy(Upload $upload): bool
    {
        try {
            $handle = $this->uploads->lock($upload->id);
        } catch (Throwable) {
            $this->uploads->delete($upload->id);

            return true;
        }
        if ($handle === null) {
            return false;
        }
        $this->uploads->delete($upload->id);
        $this->uploads->unlock($handle);

        return true;
    }

    private function isExpired(Upload $upload): bool
    {
        return $this->uploads->lastActivity($upload) + $this->config->expirySeconds < $this->now();
    }

    /** Must be called while holding the upload's lock. */
    private function finalize(Upload $upload): ?Response
    {
        try {
            $path = $this->finalizer->finalize($this->uploads->partPath($upload->id), $upload->filename, $upload->owner);
        } catch (Throwable $e) {
            $this->logger->error('finalize failed', ['id' => $upload->id, 'user' => $upload->owner, 'error' => $e->getMessage()]);

            return $this->error(500, 'The file was received but could not be saved. It will be retried when the upload resumes.');
        }

        $this->uploads->save($upload->complete(basename($path), $this->now()));
        $this->logger->info('upload complete', ['id' => $upload->id, 'user' => $upload->owner, 'path' => $path, 'size' => $upload->size]);

        return null;
    }

    /**
     * Runs $fn while holding the upload's lock. Returns the response from $fn, 423 if the
     * lock is held elsewhere, or null on success.
     *
     * @param callable(resource): ?Response $fn
     */
    private function withLock(Upload $upload, callable $fn): ?Response
    {
        $handle = $this->uploads->lock($upload->id);
        if ($handle === null) {
            return $this->respond(423);
        }
        try {
            return $fn($handle);
        } finally {
            $this->uploads->unlock($handle);
        }
    }

    private function activeUploadCount(string $owner): int
    {
        $count = 0;
        foreach ($this->uploads->all() as $upload) {
            if (!$upload->isComplete() && $upload->owner === $owner && !$this->isExpired($upload)) {
                $count++;
            }
        }

        return $count;
    }

    private function hasFreeSpace(int $bytes): bool
    {
        $free = @disk_free_space($this->uploads->directory());
        if ($free === false) {
            return true;
        }

        return $free - $this->config->minFreeSpace >= $bytes;
    }

    private function expiresHeader(int $lastActivity): string
    {
        return gmdate('D, d M Y H:i:s \G\M\T', $lastActivity + $this->config->expirySeconds);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function error(int $status, string $message): Response
    {
        return $this->respond($status, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    /** @param array<string, string> $headers */
    private function respond(int $status, array $headers = [], string $body = ''): Response
    {
        return new Response($status, $headers + ['Tus-Resumable' => self::VERSION, 'Cache-Control' => 'no-store'], $body);
    }
}
