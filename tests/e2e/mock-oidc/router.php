<?php

/**
 * Minimal mock OIDC provider for local end-to-end tests. NEVER deploy it.
 * It supports discovery, authorize (auto-approves a fixed user), token (RS256 ID token,
 * PKCE S256) and JWKS. Run it with: php -S 127.0.0.1:18081 tests/e2e/mock-oidc/router.php
 */

declare(strict_types=1);

$issuer = getenv('MOCK_OIDC_ISSUER') ?: 'http://127.0.0.1:18081';
$user = getenv('MOCK_OIDC_USER') ?: 'alice';
$stateDir = sys_get_temp_dir() . '/mock-oidc';
@mkdir($stateDir, 0700, true);

$keyFile = $stateDir . '/key.pem';
if (!is_file($keyFile)) {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyFile, $pem);
}
$privateKey = openssl_pkey_get_private((string) file_get_contents($keyFile));
$details = openssl_pkey_get_details($privateKey);

$b64u = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
$json = static function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
};
$redirect = static function (mixed $value) use ($json): string {
    if (!is_string($value)) {
        $json(['error' => 'invalid_request', 'error_description' => 'Missing redirect URI'], 400);
        exit;
    }

    $parts = parse_url($value);
    if (($parts['scheme'] ?? null) !== 'http' || ($parts['host'] ?? null) !== '127.0.0.1' || ($parts['port'] ?? null) !== 18080) {
        $json(['error' => 'invalid_request', 'error_description' => 'Unregistered redirect URI'], 400);
        exit;
    }

    return $value;
};

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/.well-known/openid-configuration':
        $json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/authorize',
            'token_endpoint' => $issuer . '/token',
            'jwks_uri' => $issuer . '/jwks',
            'end_session_endpoint' => $issuer . '/logout',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
        break;

    case '/jwks':
        $json(['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1',
            'n' => $b64u($details['rsa']['n']), 'e' => $b64u($details['rsa']['e']),
        ]]]);
        break;

    case '/authorize':
        $code = bin2hex(random_bytes(16));
        file_put_contents("$stateDir/$code.json", json_encode([
            'nonce' => $_GET['nonce'] ?? null,
            'client_id' => $_GET['client_id'] ?? '',
            'challenge' => $_GET['code_challenge'] ?? null,
        ]));
        $target = $redirect($_GET['redirect_uri'] ?? null) . '?' . http_build_query(['code' => $code, 'state' => $_GET['state'] ?? '']);
        header('Location: ' . $target, true, 302);
        break;

    case '/logout':
        // Record the request so the e2e test can check the parameters, then go back.
        file_put_contents("$stateDir/last-logout.json", json_encode($_GET));
        header('Location: ' . $redirect($_GET['post_logout_redirect_uri'] ?? null), true, 302);
        break;

    case '/last-logout':
        $json(json_decode((string) @file_get_contents("$stateDir/last-logout.json"), true) ?? []);
        break;

    case '/token':
        $code = (string) ($_POST['code'] ?? '');
        $file = "$stateDir/" . basename($code) . '.json';
        if ($code === '' || !is_file($file)) {
            $json(['error' => 'invalid_grant'], 400);
            break;
        }
        $grant = json_decode((string) file_get_contents($file), true);
        unlink($file);
        if ($grant['challenge'] !== null) {
            $verifier = (string) ($_POST['code_verifier'] ?? '');
            if ($b64u(hash('sha256', $verifier, true)) !== $grant['challenge']) {
                $json(['error' => 'invalid_grant', 'error_description' => 'PKCE failed'], 400);
                break;
            }
        }
        $now = time();
        $header = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1']));
        $payload = $b64u(json_encode([
            'iss' => $issuer, 'sub' => $user, 'aud' => $grant['client_id'], 'iat' => $now,
            'exp' => $now + 300, 'nonce' => $grant['nonce'], 'email' => "$user@example.com",
        ]));
        openssl_sign("$header.$payload", $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $json([
            'access_token' => bin2hex(random_bytes(16)),
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => "$header.$payload." . $b64u($signature),
        ]);
        break;

    default:
        $json(['error' => 'not_found'], 404);
}
