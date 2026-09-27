<?php

declare(strict_types=1);

namespace SynoUploader\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, ['Location' => $location, 'Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            $headers + ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], $html);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers + $this->headers, $this->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
