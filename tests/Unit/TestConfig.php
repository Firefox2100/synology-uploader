<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use SynoUploader\Config;

final class TestConfig
{
    /** @param array<string, mixed> $overrides */
    public static function make(string $root, array $overrides = []): Config
    {
        $raw = array_replace_recursive([
            'base_url' => 'https://upload.example.com/',
            'destination_dir' => $root . '/dest',
            'staging_dir' => $root . '/staging',
            'var_dir' => $root . '/var',
            'upload' => [
                'chunk_size' => 1024 * 1024,
                'max_chunk_size' => 2 * 1024 * 1024,
                'min_free_space' => 0,
                'max_active_uploads_per_user' => 3,
            ],
            'oidc' => [
                'issuer' => 'https://sso.example.com/webman/sso',
                'client_id' => 'client',
                'client_secret' => 'secret',
            ],
        ], $overrides);

        return Config::fromArray($raw, $root);
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/synoup-' . bin2hex(random_bytes(6));
        mkdir($dir . '/dest', 0777, true);

        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
