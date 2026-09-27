<?php

declare(strict_types=1);

namespace SynoUploader\Storage;

use RuntimeException;

final class Finalizer
{
    private const MAX_ATTEMPTS = 1000;

    public function __construct(
        private readonly string $destinationDir,
        private readonly bool $userSubdirectory,
        private readonly FilenameSanitizer $sanitizer,
    ) {
    }

    /**
     * @return string Absolute path of the final file.
     */
    public function finalize(string $stagedPath, string $filename, string $owner): string
    {
        $dir = $this->targetDirectory($owner);
        $name = $this->sanitizer->sanitize($filename) ?? 'upload';

        for ($n = 0; $n < self::MAX_ATTEMPTS; $n++) {
            $candidate = $n === 0 ? $name : $this->sanitizer->withSuffix($name, $n);
            if (str_contains($candidate, '/') || $candidate === '.' || $candidate === '..') {
                throw new RuntimeException('Refusing unsafe filename.');
            }
            $target = $dir . '/' . $candidate;

            // Reserve the name atomically before replacing the placeholder.
            $placeholder = @fopen($target, 'x');
            if ($placeholder === false) {
                if (file_exists($target) || is_link($target)) {
                    continue;
                }
                throw new RuntimeException("Cannot create file in destination directory: $dir");
            }
            fclose($placeholder);

            if (!@rename($stagedPath, $target)) {
                @unlink($target);
                throw new RuntimeException("Cannot move staged upload to $target");
            }

            return $target;
        }

        throw new RuntimeException("Too many files named like '$name' in $dir");
    }

    private function targetDirectory(string $owner): string
    {
        if (!$this->userSubdirectory) {
            return $this->destinationDir;
        }

        $segment = $this->sanitizer->sanitize($owner);
        if ($segment === null) {
            throw new RuntimeException('Username cannot be used as a directory name.');
        }
        $dir = $this->destinationDir . '/' . $segment;
        if (is_link($dir)) {
            throw new RuntimeException("User directory is a symlink: $dir");
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create user directory: $dir");
        }

        return $dir;
    }
}
