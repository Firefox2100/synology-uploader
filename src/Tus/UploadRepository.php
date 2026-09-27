<?php

declare(strict_types=1);

namespace SynoUploader\Tus;

use RuntimeException;

final class UploadRepository
{
    public function __construct(private readonly string $dir)
    {
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function ensureDirectory(): void
    {
        if (is_link($this->dir)) {
            throw new RuntimeException("Staging directory is a symlink: {$this->dir}");
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create staging directory: {$this->dir}");
        }
        if (!is_writable($this->dir)) {
            throw new RuntimeException("Staging directory is not writable: {$this->dir}");
        }
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function partPath(string $id): string
    {
        $this->assertId($id);

        return $this->dir . '/' . $id . '.part';
    }

    public function create(Upload $upload): void
    {
        $this->ensureDirectory();
        $handle = @fopen($this->partPath($upload->id), 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create staging file.');
        }
        fclose($handle);
        $this->save($upload);
    }

    public function find(string $id): ?Upload
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $json = @file_get_contents($this->jsonPath($id));
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? Upload::fromArray($data) : null;
    }

    public function save(Upload $upload): void
    {
        $path = $this->jsonPath($upload->id);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($upload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write upload record.');
        }
    }

    public function delete(string $id): void
    {
        @unlink($this->partPath($id));
        @unlink($this->jsonPath($id));
    }

    public function offset(string $id): ?int
    {
        $path = $this->partPath($id);
        clearstatcache(true, $path);
        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    public function lastActivity(Upload $upload): int
    {
        $path = $upload->isComplete() ? $this->jsonPath($upload->id) : $this->partPath($upload->id);
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return $mtime === false ? ($upload->completedAt ?? $upload->createdAt) : $mtime;
    }

    /**
     * Opens the data file and takes an exclusive, non-blocking lock on it.
     *
     * @return resource|null null if another request holds the lock
     */
    public function lock(string $id)
    {
        $handle = @fopen($this->partPath($id), 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open staging file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return iterable<Upload> */
    public function all(): iterable
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $upload = $this->find(basename($file, '.json'));
            if ($upload !== null) {
                yield $upload;
            }
        }
    }

    /** @return list<string> */
    public function orphans(): array
    {
        $orphans = [];
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            $base = basename($file);
            if (preg_match('/^([a-f0-9]{32})\.part$/D', $base, $m) === 1) {
                if (!is_file($this->jsonPath($m[1]))) {
                    $orphans[] = $file;
                }
            } elseif (str_ends_with($base, '.tmp')) {
                $orphans[] = $file;
            }
        }

        return $orphans;
    }

    private function jsonPath(string $id): string
    {
        $this->assertId($id);

        return $this->dir . '/' . $id . '.json';
    }

    private function assertId(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new RuntimeException('Invalid upload id.');
        }
    }
}
