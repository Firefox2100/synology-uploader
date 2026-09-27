<?php

declare(strict_types=1);

namespace SynoUploader\Auth;

use RuntimeException;

/** Sign-in failed. The message is safe to show to the user. */
final class LoginFailed extends RuntimeException
{
}
