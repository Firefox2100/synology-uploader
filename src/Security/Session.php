<?php

declare(strict_types=1);

namespace SynoUploader\Security;

use RuntimeException;
use SynoUploader\Config;

final class Session
{
    private const USER = 'user';
    private const CREATED = 'login_at';
    private const SEEN = 'last_seen';
    private const CSRF = 'csrf';
    private const ID_TOKEN = 'id_token';
    private const TOUCH_INTERVAL = 60;

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $dir = $this->config->varDir . '/sessions';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create session directory $dir: the web server user (http) needs write access to {$this->config->varDir}, and it must be inside open_basedir.");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Session directory $dir is not writable by the web server user (http).");
        }

        if (ini_get('session.save_handler') !== 'files' && ini_set('session.save_handler', 'files') === false) {
            throw new RuntimeException('Cannot switch session.save_handler to "files".');
        }

        $path = $this->config->basePath();

        session_name($this->cookieName());
        session_save_path($dir);
        session_cache_limiter('');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'domain' => '',
            // Local HTTP is supported for development; production HTTPS always sets Secure.
            'secure' => $this->config->isHttps(), // NOSONAR: false is intentional only for an HTTP base_url.
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $ok = session_start([
            'use_strict_mode' => 1,
            'use_cookies' => 1,
            'use_only_cookies' => 1,
            'use_trans_sid' => 0,
            'gc_maxlifetime' => $this->config->sessionIdleTimeout,
            'gc_probability' => 1,
            'gc_divisor' => 100,
            'lazy_write' => 1,
        ]);
        if (!$ok) {
            $last = error_get_last();
            throw new RuntimeException('Cannot start session: ' . ($last['message'] ?? 'unknown error'));
        }

        $this->enforceTimeouts();
    }

    public function cookieName(): string
    {
        return ($this->config->isHttps() && $this->config->basePath() === '/') ? '__Host-synouploader' : 'synouploader';
    }

    /** Whether the request carries a session cookie, checked without starting a session. */
    public function hasCookie(): bool
    {
        $value = $_COOKIE[$this->cookieName()] ?? null;

        return is_string($value) && $value !== '';
    }

    public function user(): ?string
    {
        $user = $_SESSION[self::USER] ?? null;

        return is_string($user) ? $user : null;
    }

    public function login(string $user, ?string $idToken = null): void
    {
        // Get a fresh session ID (prevents session fixation) and drop all pre-login data,
        // including the OIDC state/nonce.
        session_regenerate_id(true);
        $now = time();
        $_SESSION = [
            self::USER => $user,
            self::CREATED => $now,
            self::SEEN => $now,
            self::CSRF => bin2hex(random_bytes(32)),
            self::ID_TOKEN => $idToken,
        ];
    }

    public function flash(string $key, string $value): void
    {
        $_SESSION['flash:' . $key] = $value;
    }

    public function pull(string $key): ?string
    {
        $value = $_SESSION['flash:' . $key] ?? null;
        unset($_SESSION['flash:' . $key]);

        return is_string($value) ? $value : null;
    }

    /** The ID token from login, used as id_token_hint for single logout. */
    public function idToken(): ?string
    {
        $token = $_SESSION[self::ID_TOKEN] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function touch(): void
    {
        $seen = $_SESSION[self::SEEN] ?? 0;
        if (is_int($seen) && time() - $seen >= self::TOUCH_INTERVAL) {
            $_SESSION[self::SEEN] = time();
        }
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF] = $token;
        }

        return $token;
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $_SESSION[self::CSRF] ?? null;

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * @return mixed a raw session value, used for the OIDC client's state/nonce
     */
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function enforceTimeouts(): void
    {
        if ($this->user() === null) {
            return;
        }
        $now = time();
        $created = $_SESSION[self::CREATED] ?? 0;
        $seen = $_SESSION[self::SEEN] ?? 0;
        if (!is_int($created) || !is_int($seen)
            || $now - $created > $this->config->sessionAbsoluteTimeout
            || $now - $seen > $this->config->sessionIdleTimeout) {
            $this->logout();
        }
    }
}
