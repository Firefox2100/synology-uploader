<?php

declare(strict_types=1);

namespace SynoUploader;

use RuntimeException;

/**
 * Validated, immutable application configuration.
 */
final class Config
{
    private const MIB = 1024 * 1024;

    private function __construct(
        public readonly string $baseUrl,
        public readonly string $origin,
        public readonly string $destinationDir,
        public readonly string $stagingDir,
        public readonly bool $userSubdirectory,
        public readonly string $varDir,
        public readonly int $chunkSize,
        public readonly int $maxChunkSize,
        public readonly int $maxFileSize,
        public readonly int $minFreeSpace,
        public readonly int $expirySeconds,
        public readonly int $maxActiveUploadsPerUser,
        public readonly int $parallelFiles,
        public readonly int $maxFilenameBytes,
        public readonly string $oidcIssuer,
        public readonly string $oidcClientId,
        public readonly string $oidcClientSecret,
        /** @var list<string> */
        public readonly array $oidcScopes,
        public readonly string $oidcUsernameClaim,
        public readonly ?string $oidcCaFile,
        public readonly ?string $oidcLogoutUrl,
        public readonly bool $oidcSingleLogout,
        /** @var list<string> */
        public readonly array $allowedUsers,
        public readonly int $sessionIdleTimeout,
        public readonly int $sessionAbsoluteTimeout,
        /** @var list<string> */
        public readonly array $trustedProxies,
    ) {
    }

    public static function load(string $appRoot): self
    {
        // The environment override is meant for development and tests. On DSM the default path is used.
        $file = getenv('SYNO_UPLOADER_CONFIG') ?: $appRoot . '/config/config.php';
        if (!is_file($file)) {
            throw new RuntimeException("Missing config file $file (copy config/config.example.php to config/config.php).");
        }

        $raw = require $file;
        if (!is_array($raw)) {
            throw new RuntimeException('config/config.php must return an array.');
        }

        return self::fromArray($raw, $appRoot);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, string $appRoot): self
    {
        $baseUrl = self::string($raw, 'base_url');
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new RuntimeException('base_url must be an absolute http(s) URL.');
        }
        if (!str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }
        $origin = $parts['scheme'] . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $destinationDir = self::absolutePath(self::string($raw, 'destination_dir'), 'destination_dir');
        $stagingDir = isset($raw['staging_dir'])
            ? self::absolutePath(self::string($raw, 'staging_dir'), 'staging_dir')
            : $destinationDir . '/.upload-staging';
        $varDir = isset($raw['var_dir'])
            ? self::absolutePath(self::string($raw, 'var_dir'), 'var_dir')
            : $appRoot . '/var';

        $upload = self::section($raw, 'upload');
        $chunkSize = self::int($upload, 'chunk_size', 32 * self::MIB);
        $maxChunkSize = self::int($upload, 'max_chunk_size', 64 * self::MIB);
        if ($chunkSize < self::MIB) {
            throw new RuntimeException('upload.chunk_size must be at least 1 MiB.');
        }
        if ($maxChunkSize < $chunkSize) {
            throw new RuntimeException('upload.max_chunk_size must be >= upload.chunk_size.');
        }

        $maxFilenameBytes = self::int($upload, 'max_filename_bytes', 255);
        if ($maxFilenameBytes < 32 || $maxFilenameBytes > 255) {
            throw new RuntimeException('upload.max_filename_bytes must be between 32 and 255.');
        }

        $oidc = self::section($raw, 'oidc');
        $session = self::section($raw, 'session');

        $caFile = $oidc['ca_file'] ?? null;
        if ($caFile !== null && (!is_string($caFile) || !is_file($caFile))) {
            throw new RuntimeException('oidc.ca_file must be null or an existing file.');
        }

        $logoutUrl = $oidc['logout_url'] ?? null;
        if ($logoutUrl !== null && (!is_string($logoutUrl) || !preg_match('~^https?://~i', $logoutUrl))) {
            throw new RuntimeException('oidc.logout_url must be null or an absolute http(s) URL.');
        }

        return new self(
            baseUrl: $baseUrl,
            origin: $origin,
            destinationDir: $destinationDir,
            stagingDir: $stagingDir,
            userSubdirectory: (bool) ($raw['user_subdirectory'] ?? false),
            varDir: $varDir,
            chunkSize: $chunkSize,
            maxChunkSize: $maxChunkSize,
            maxFileSize: self::int($upload, 'max_file_size', 0, allowZero: true),
            minFreeSpace: self::int($upload, 'min_free_space', 1024 * self::MIB, allowZero: true),
            expirySeconds: self::int($upload, 'expiry_seconds', 48 * 3600),
            maxActiveUploadsPerUser: self::int($upload, 'max_active_uploads_per_user', 20),
            parallelFiles: self::int($upload, 'parallel_files', 3),
            maxFilenameBytes: $maxFilenameBytes,
            oidcIssuer: rtrim(self::string($oidc, 'issuer'), '/'),
            oidcClientId: self::string($oidc, 'client_id'),
            oidcClientSecret: self::string($oidc, 'client_secret'),
            oidcScopes: self::stringList($oidc, 'scopes'),
            oidcUsernameClaim: isset($oidc['username_claim']) ? self::string($oidc, 'username_claim') : 'sub',
            oidcCaFile: $caFile,
            oidcLogoutUrl: $logoutUrl,
            oidcSingleLogout: (bool) ($oidc['single_logout'] ?? true),
            allowedUsers: self::stringList($oidc, 'allowed_users'),
            sessionIdleTimeout: self::int($session, 'idle_timeout', 2 * 3600),
            sessionAbsoluteTimeout: self::int($session, 'absolute_timeout', 24 * 3600),
            trustedProxies: self::stringList($raw, 'trusted_proxies'),
        );
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->origin, 'https://');
    }

    /**
     * Origins the browser may be sent to after the logout form is submitted. CSP form-action
     * also applies to redirects that follow a form submission.
     *
     * @return list<string>
     */
    public function logoutOrigins(): array
    {
        if (!$this->oidcSingleLogout) {
            return [];
        }
        $origins = [];
        foreach ([$this->oidcIssuer, $this->oidcLogoutUrl] as $url) {
            $parts = $url === null ? false : parse_url($url);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        return array_values(array_unique(array_diff($origins, [$this->origin])));
    }

    /** Path component of base_url, always with leading and trailing slash. */
    public function basePath(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_PATH) ?: '/';
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function section(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException("Config section '$key' must be an array.");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function string(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Config value '$key' must be a non-empty string.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $raw */
    private static function int(array $raw, string $key, int $default, bool $allowZero = false): int
    {
        $value = $raw[$key] ?? $default;
        if (!is_int($value) || $value < 0 || ($value === 0 && !$allowZero)) {
            throw new RuntimeException("Config value '$key' must be a " . ($allowZero ? 'non-negative' : 'positive') . ' integer.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function stringList(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("Config value '$key' must be a list of strings.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new RuntimeException("Config value '$key' must be a list of non-empty strings.");
            }
        }

        return $value;
    }

    private static function absolutePath(string $path, string $key): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new RuntimeException("Config value '$key' must be an absolute path without '..'.");
        }

        return rtrim($path, '/') ?: '/';
    }
}
