<?php

declare(strict_types=1);

namespace SynoUploader\Http;

final class Request
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    /** @var resource|null */
    private $body;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param resource|null $body
     */
    public function __construct(
        public readonly string $method,
        array $headers = [],
        public readonly array $query = [],
        $body = null,
        public readonly string $remoteAddr = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $headers,
            $_GET,
            null,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function queryString(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return resource */
    public function body()
    {
        if ($this->body === null) {
            $stream = fopen('php://input', 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open request body.');
            }
            $this->body = $stream;
        }

        return $this->body;
    }

    public function contentLength(): ?int
    {
        $value = $this->header('Content-Length');
        if ($value === null || preg_match('/^\d{1,18}$/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
