<?php

declare(strict_types=1);

namespace SynoUploader\Tus;

/**
 * State of one tus upload, stored as <id>.json next to the <id>.part data file.
 * The number of bytes received (the tus offset) is always the size of the .part file.
 */
final class Upload
{
    public const ACTIVE = 'active';
    public const COMPLETE = 'complete';

    public function __construct(
        public readonly string $id,
        public readonly string $owner,
        public readonly string $filename,
        public readonly int $size,
        public readonly int $createdAt,
        public readonly string $status = self::ACTIVE,
        public readonly ?string $finalName = null,
        public readonly ?int $completedAt = null,
    ) {
    }

    public function complete(string $finalName, int $at): self
    {
        return new self($this->id, $this->owner, $this->filename, $this->size, $this->createdAt, self::COMPLETE, $finalName, $at);
    }

    public function isComplete(): bool
    {
        return $this->status === self::COMPLETE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'filename' => $this->filename,
            'size' => $this->size,
            'created_at' => $this->createdAt,
            'status' => $this->status,
            'final_name' => $this->finalName,
            'completed_at' => $this->completedAt,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['id'] ?? null) || !is_string($data['owner'] ?? null) || !is_string($data['filename'] ?? null)
            || !is_int($data['size'] ?? null) || !is_int($data['created_at'] ?? null)
            || !in_array($data['status'] ?? null, [self::ACTIVE, self::COMPLETE], true)) {
            return null;
        }

        return new self(
            $data['id'],
            $data['owner'],
            $data['filename'],
            $data['size'],
            $data['created_at'],
            $data['status'],
            is_string($data['final_name'] ?? null) ? $data['final_name'] : null,
            is_int($data['completed_at'] ?? null) ? $data['completed_at'] : null,
        );
    }
}
