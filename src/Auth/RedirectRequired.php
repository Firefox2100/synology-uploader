<?php

declare(strict_types=1);

namespace SynoUploader\Auth;

use RuntimeException;

final class RedirectRequired extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('Redirect required');
    }
}
