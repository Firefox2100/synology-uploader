<?php

declare(strict_types=1);

namespace SynoUploader\Storage;

use Normalizer;

final class FilenameSanitizer
{
    /** Names DSM uses for its own metadata and recycle bin. */
    private const RESERVED = ['@eadir', '#recycle', '#snapshot'];

    public function __construct(private readonly int $maxBytes = 255)
    {
    }

    /**
     * @return string|null The sanitized name, or null if nothing usable is left.
     */
    public function sanitize(string $name): ?string
    {
        if (preg_match('//u', $name) !== 1) {
            return null;
        }

        $name = Normalizer::normalize($name, Normalizer::FORM_C);
        if ($name === false) {
            return null;
        }

        $name = (string) preg_replace('~^.*[/\\\\]~su', '', $name);

        // Strip controls and invisible formatting characters, including bidi overrides.
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);

        $name = strtr($name, ['<' => '_', '>' => '_', ':' => '_', '"' => '_', '|' => '_', '?' => '_', '*' => '_']);

        $name = (string) preg_replace('/^[\s.]+|[\s.]+$/u', '', $name);

        if ($name === '' || in_array(strtolower($name), self::RESERVED, true)) {
            return null;
        }

        return $this->truncate($name, $this->maxBytes);
    }

    /**
     * Returns "name (n).ext" for collision n, still within the byte limit.
     */
    public function withSuffix(string $name, int $n): string
    {
        [$stem, $ext] = self::split($name);
        $suffix = ' (' . $n . ')';
        $budget = $this->maxBytes - strlen($suffix) - strlen($ext);
        $stem = self::cutUtf8($stem, max(1, $budget));

        return $stem . $suffix . $ext;
    }

    private function truncate(string $name, int $maxBytes): string
    {
        if (strlen($name) <= $maxBytes) {
            return $name;
        }
        [$stem, $ext] = self::split($name);
        if (strlen($ext) >= $maxBytes / 2) {
            return self::cutUtf8($name, $maxBytes);
        }

        return self::cutUtf8($stem, $maxBytes - strlen($ext)) . $ext;
    }

    /**
     * @return array{0: string, 1: string} Stem and extension (with its dot, or '').
     */
    private static function split(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), substr($name, $dot)];
    }

    /** Cut a UTF-8 string to at most $bytes bytes without splitting a character. */
    private static function cutUtf8(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        $cut = substr($value, 0, $bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
