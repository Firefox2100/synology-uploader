<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use Jumbojett\OpenIDConnectClientException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SynoUploader\Auth\LoginFailed;
use SynoUploader\Auth\OidcLogin;
use SynoUploader\Auth\RedirectRequired;
use SynoUploader\Auth\SsoLogout;
use SynoUploader\Http\Request;
use SynoUploader\Security\Session;
use SynoUploader\Support\Logger;

/**
 * The OIDC protocol itself is covered end to end (tests/e2e) against a mock provider with the
 * real library. These tests cover the extra checks this app adds around the library.
 */
#[RunTestsInSeparateProcesses]
final class OidcLoginTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestConfig::tempDir();
    }

    protected function tearDown(): void
    {
        TestConfig::removeDir($this->root);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{OidcLogin, Session, object}
     */
    private function login(?object $claims, bool $authenticates = true, array $config = []): array
    {
        $cfg = TestConfig::make($this->root, $config);
        $session = new Session($cfg);
        $session->start();
        $client = new class ($claims, $authenticates) {
            public int $calls = 0;

            public function __construct(private ?object $claims, private bool $authenticates)
            {
            }

            public function authenticate(): bool
            {
                $this->calls++;
                if (!isset($_GET['code'])) {
                    throw new RedirectRequired('https://sso.example.com/authorize');
                }
                if (!$this->authenticates) {
                    throw new OpenIDConnectClientException('bad signature');
                }

                return true;
            }

            public function getVerifiedClaims(): ?object
            {
                return $this->claims;
            }

            public function getIdToken(): string
            {
                return 'header.payload.signature';
            }

            public function signOut(string $idToken, ?string $redirect): void
            {
                if ($this->claims === null) {
                    throw new OpenIDConnectClientException('The provider end_session_endpoint could not be fetched.');
                }
                throw new RedirectRequired('https://sso.example.com/logout?' . http_build_query(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => $redirect]));
            }
        };
        $login = new OidcLogin($cfg, $session, new Logger($this->root . '/log'), fn () => $client);

        return [$login, $session, $client];
    }

    private static function callbackRequest(string $state = 'state-1'): Request
    {
        $_GET = ['code' => 'abc', 'state' => $state];

        return new Request('GET', [], $_GET);
    }

    private static function claims(array $overrides = []): object
    {
        return (object) ($overrides + ['sub' => 'alice', 'nonce' => 'nonce-1', 'exp' => time() + 60]);
    }

    public function testStartRedirectsToProvider(): void
    {
        [$login] = $this->login(null);
        $_GET = [];
        $response = $login->handle(new Request('GET'));
        self::assertSame(302, $response->status);
        self::assertSame('https://sso.example.com/authorize', $response->headers['Location']);
    }

    public function testSuccessfulCallbackLogsIn(): void
    {
        [$login, $session] = $this->login(self::claims());
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        $response = $login->handle(self::callbackRequest());
        self::assertSame(303, $response->status);
        self::assertSame('alice', $session->user());
        self::assertSame('header.payload.signature', $session->idToken());
        self::assertArrayNotHasKey('openid_connect_state', $_SESSION);
    }

    public function testSsoLogoutPrefersEndSessionEndpoint(): void
    {
        [$login] = $this->login(self::claims());
        $sso = $login->ssoLogout('tok');
        self::assertNotNull($sso);
        self::assertSame(SsoLogout::REDIRECT, $sso->type);
        self::assertSame('https://sso.example.com/logout?id_token_hint=tok&post_logout_redirect_uri=' . rawurlencode('https://upload.example.com/'), $sso->url);
    }

    public function testSynologyFallbackUsesIframeLogout(): void
    {
        // No end_session_endpoint in discovery, and the issuer is a Synology SSO Server.
        [$login] = $this->login(null);
        $sso = $login->ssoLogout('tok');
        self::assertNotNull($sso);
        self::assertSame(SsoLogout::IFRAME, $sso->type);
        self::assertSame(
            'https://sso.example.com/webman/sso/SSOOauth.cgi?method=logout&app_id=client&scope=user_id&redirect_uri='
            . rawurlencode('https://upload.example.com/auth.php'),
            $sso->url,
        );
        // Also without an ID token (for example, sessions from before this feature).
        self::assertSame(SsoLogout::IFRAME, $login->ssoLogout(null)?->type);
    }

    public function testNoSsoLogoutForOtherProvidersWithoutEndSession(): void
    {
        [$login] = $this->login(null, true, ['oidc' => ['issuer' => 'https://idp.example.com/realms/x']]);
        self::assertNull($login->ssoLogout('tok'));
    }

    public function testConfiguredLogoutUrlWins(): void
    {
        [$login] = $this->login(self::claims(), true, ['oidc' => ['logout_url' => 'https://sso.example.com/custom-logout?x=1']]);
        $sso = $login->ssoLogout(null);
        self::assertSame(SsoLogout::REDIRECT, $sso?->type);
        self::assertStringStartsWith('https://sso.example.com/custom-logout?x=1&post_logout_redirect_uri=', $sso->url);
        self::assertStringContainsString('client_id=client', $sso->url);
    }

    public function testStateMismatchIsRejectedBeforeCodeExchange(): void
    {
        [$login, , $client] = $this->login(self::claims());
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        try {
            $login->handle(self::callbackRequest('forged'));
            self::fail('Expected LoginFailed');
        } catch (LoginFailed) {
            self::assertSame(0, $client->calls);
        }
    }

    public function testMissingNonceClaimIsRejected(): void
    {
        $claims = self::claims();
        unset($claims->nonce);
        [$login, $session] = $this->login($claims);
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        $this->expectException(LoginFailed::class);
        try {
            $login->handle(self::callbackRequest());
        } finally {
            self::assertNull($session->user());
        }
    }

    public function testMissingExpIsRejected(): void
    {
        $claims = self::claims();
        unset($claims->exp);
        [$login] = $this->login($claims);
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        $this->expectException(LoginFailed::class);
        $login->handle(self::callbackRequest());
    }

    public function testLibraryFailureBecomesLoginFailed(): void
    {
        [$login] = $this->login(self::claims(), false);
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        $this->expectException(LoginFailed::class);
        $login->handle(self::callbackRequest());
    }

    public function testAllowListIsCaseInsensitive(): void
    {
        [$login, $session] = $this->login(self::claims(['sub' => 'Alice']), true, ['oidc' => ['allowed_users' => ['alice']]]);
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        $login->handle(self::callbackRequest());
        self::assertSame('Alice', $session->user());
    }

    public function testAllowListDenies(): void
    {
        [$login, $session] = $this->login(self::claims(['sub' => 'mallory']), true, ['oidc' => ['allowed_users' => ['alice']]]);
        $_SESSION['openid_connect_state'] = 'state-1';
        $_SESSION['openid_connect_nonce'] = 'nonce-1';
        try {
            $login->handle(self::callbackRequest());
            self::fail('Expected LoginFailed');
        } catch (LoginFailed) {
            self::assertNull($session->user());
        }
    }

    public function testProviderErrorIsReported(): void
    {
        [$login] = $this->login(null);
        $this->expectException(LoginFailed::class);
        $login->handle(new Request('GET', [], ['error' => 'access_denied']));
    }
}
