<?php

declare(strict_types=1);

namespace SynoUploader\Auth;

use Closure;
use Jumbojett\OpenIDConnectClientException;
use SynoUploader\Config;
use SynoUploader\Http\Request;
use SynoUploader\Http\Response;
use SynoUploader\Security\Session;
use SynoUploader\Support\Logger;

final class OidcLogin
{
    /** Session keys used by jumbojett/openid-connect-php. */
    private const STATE_KEY = 'openid_connect_state';
    private const NONCE_KEY = 'openid_connect_nonce';

    private readonly Closure $clientFactory;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly Logger $logger,
        ?Closure $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory ?? fn (): OidcClient => $this->createClient();
    }

    /** @throws LoginFailed */
    public function handle(Request $request): Response
    {
        // jumbojett reads code, state and error from $_REQUEST. Restrict that to the query
        // string, so cookies (settable by sibling subdomains) or POST data can't supply them.
        $_REQUEST = $_GET;

        if ($request->queryString('error') !== null) {
            $this->logger->warning('oidc error response', [
                'error' => $request->queryString('error'),
                'description' => $request->queryString('error_description'),
            ]);
            throw new LoginFailed('Sign-in was cancelled or refused by the SSO server.');
        }

        $isCallback = $request->queryString('code') !== null;
        $expectedNonce = null;
        if ($isCallback) {
            $state = $request->queryString('state');
            $expectedState = $this->session->get(self::STATE_KEY);
            if (!is_string($state) || !is_string($expectedState) || !hash_equals($expectedState, $state)) {
                throw new LoginFailed('Your sign-in attempt expired or was started in another tab. Please try again.');
            }
            $expectedNonce = $this->session->get(self::NONCE_KEY);
            if (!is_string($expectedNonce) || $expectedNonce === '') {
                throw new LoginFailed('Your sign-in attempt expired. Please try again.');
            }
        }

        /** @var OidcClient $client */
        $client = ($this->clientFactory)();

        try {
            if (!$client->authenticate()) {
                throw new LoginFailed('Sign-in did not complete.');
            }
        } catch (RedirectRequired $redirect) {
            return Response::redirect($redirect->url, 302);
        } catch (OpenIDConnectClientException $e) {
            $this->logger->warning('oidc authentication failed', ['error' => $e->getMessage()]);
            throw new LoginFailed('Sign-in failed. Please try again or contact the administrator.');
        }

        $claims = $client->getVerifiedClaims();
        if (!is_object($claims)) {
            throw new LoginFailed('Sign-in failed: no claims received.');
        }
        if (!isset($claims->nonce) || !is_string($claims->nonce) || !hash_equals((string) $expectedNonce, $claims->nonce)) {
            $this->logger->warning('oidc nonce missing or mismatched');
            throw new LoginFailed('Sign-in failed: invalid token.');
        }
        if (!isset($claims->exp) || !is_int($claims->exp)) {
            $this->logger->warning('oidc token without exp');
            throw new LoginFailed('Sign-in failed: invalid token.');
        }

        $claim = $this->config->oidcUsernameClaim;
        $username = $claims->{$claim} ?? null;
        if (!is_string($username) || trim($username) === '') {
            $this->logger->warning('oidc username claim missing', ['claim' => $claim]);
            throw new LoginFailed('Sign-in failed: the SSO server did not provide a username.');
        }
        $username = trim($username);

        if (!$this->isAllowed($username)) {
            $this->logger->warning('login denied by allow-list', ['user' => $username]);
            throw new LoginFailed('Your account is not allowed to use this uploader.');
        }

        $idToken = $client->getIdToken();
        $this->session->login($username, is_string($idToken) ? $idToken : null);
        $this->logger->info('login', ['user' => $username, 'ip' => $request->remoteAddr]);

        return Response::redirect($this->config->baseUrl);
    }

    public function ssoLogout(?string $idToken): ?SsoLogout
    {
        if ($this->config->oidcLogoutUrl !== null) {
            $params = ['post_logout_redirect_uri' => $this->config->baseUrl, 'client_id' => $this->config->oidcClientId];
            if ($idToken !== null) {
                $params = ['id_token_hint' => $idToken] + $params;
            }
            $url = $this->config->oidcLogoutUrl;

            return new SsoLogout(SsoLogout::REDIRECT, $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params));
        }

        if ($idToken !== null) {
            /** @var OidcClient $client */
            $client = ($this->clientFactory)();
            try {
                $client->signOut($idToken, $this->config->baseUrl);
            } catch (RedirectRequired $redirect) {
                return new SsoLogout(SsoLogout::REDIRECT, $redirect->url);
            } catch (OpenIDConnectClientException) {
                // Fall back to Synology's logout endpoint below.
            }
        }

        if (str_ends_with($this->config->oidcIssuer, '/webman/sso')) {
            return new SsoLogout(SsoLogout::IFRAME, $this->config->oidcIssuer . '/SSOOauth.cgi?' . http_build_query([
                'method' => 'logout',
                'app_id' => $this->config->oidcClientId,
                'scope' => 'user_id',
                'redirect_uri' => $this->config->baseUrl . 'auth.php',
            ]));
        }

        $this->logger->warning('sso logout unavailable: provider has no end_session_endpoint');

        return null;
    }

    private function isAllowed(string $username): bool
    {
        if ($this->config->allowedUsers === []) {
            return true;
        }
        foreach ($this->config->allowedUsers as $allowed) {
            if (strcasecmp($allowed, $username) === 0) {
                return true;
            }
        }

        return false;
    }

    private function createClient(): OidcClient
    {
        $client = new OidcClient(
            $this->config->oidcIssuer,
            $this->config->oidcClientId,
            $this->config->oidcClientSecret,
            $this->config->oidcIssuer,
        );
        $client->setRedirectURL($this->config->baseUrl . 'auth.php');
        if ($this->config->oidcScopes !== []) {
            $client->addScope($this->config->oidcScopes);
        }
        $client->setCodeChallengeMethod('S256');
        $client->setVerifyHost(true);
        $client->setVerifyPeer(true);
        $client->setTimeout(15);
        if ($this->config->oidcCaFile !== null) {
            $client->setCertPath($this->config->oidcCaFile);
        }

        return $client;
    }
}
