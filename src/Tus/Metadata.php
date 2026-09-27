<?php

declare(strict_types=1);

namespace SynoUploader\Tus;

/**
 * Strict parser for the tus Upload-Metadata header: comma-separated "key base64value" pairs.
 */
final class Metadata
{
    public const MAX_HEADER_BYTES = 8192;

    /**
     * @return array<string, string>|null Decoded pairs, or null if the header is malformed.
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }
        if (strlen($header) > self::MAX_HEADER_BYTES) {
            return null;
        }

        $result = [];
        foreach (explode(',', $header) as $pair) {
            $parts = explode(' ', trim($pair));
            if (count($parts) > 2) {
                return null;
            }
            $key = $parts[0];
            if (preg_match('/^[\x21-\x2B\x2D-\x7E]+$/', $key) !== 1 || array_key_exists($key, $result)) {
                return null;
            }
            $value = '';
            if (isset($parts[1]) && $parts[1] !== '') {
                $value = base64_decode($parts[1], true);
                if ($value === false) {
                    return null;
                }
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
