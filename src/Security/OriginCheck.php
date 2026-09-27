<?php

declare(strict_types=1);

namespace SynoUploader\Security;

use SynoUploader\Http\Request;

final class OriginCheck
{
    public function __construct(private readonly string $origin)
    {
    }

    public function allows(Request $request): bool
    {
        $site = $request->header('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin') {
            return false;
        }

        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = $request->header('Origin');

            return $origin === null || $this->matches($origin);
        }

        $origin = $request->header('Origin');

        return $origin !== null && $this->matches($origin);
    }

    private function matches(string $origin): bool
    {
        return strtolower(rtrim($origin, '/')) === $this->origin;
    }
}
