<?php

declare(strict_types=1);

namespace SynoUploader;

use SynoUploader\Auth\LoginFailed;
use SynoUploader\Auth\OidcLogin;
use SynoUploader\Auth\SsoLogout;
use SynoUploader\Http\Request;
use SynoUploader\Http\Response;
use SynoUploader\Security\OriginCheck;
use SynoUploader\Security\SecurityHeaders;
use SynoUploader\Security\Session;
use SynoUploader\Storage\FilenameSanitizer;
use SynoUploader\Storage\Finalizer;
use SynoUploader\Support\Logger;
use SynoUploader\Tus\TusServer;
use SynoUploader\Tus\UploadRepository;
use Throwable;

final class App
{
    public readonly Logger $logger;
    public readonly Session $session;
    private readonly OriginCheck $originCheck;

    public function __construct(public readonly Config $config, private readonly string $appRoot)
    {
        $logDir = $config->varDir . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }
        $this->logger = new Logger($logDir . '/app.log');
        $this->session = new Session($config);
        $this->originCheck = new OriginCheck($config->origin);
    }

    public static function run(string $appRoot, string $endpoint): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        $app = null;
        try {
            $app = new self(Config::load($appRoot), $appRoot);
            $response = $app->dispatch($endpoint, Request::fromGlobals());
        } catch (Throwable $e) {
            $app?->logger->error('unhandled exception', [
                'error' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            if ($app === null) {
                error_log('synology-uploader: ' . $e->getMessage());
            }
            $response = Response::html(self::renderStatic('error', [
                'title' => 'Something went wrong',
                'message' => 'The uploader hit an internal error. The administrator can find details in the log.',
            ], $appRoot), 500);
        }

        SecurityHeaders::apply($response, $app?->config->logoutOrigins() ?? [], $app?->config->isHttps() ?? false)->send();
    }

    public function dispatch(string $endpoint, Request $request): Response
    {
        return match ($endpoint) {
            'index' => $this->page($request),
            'auth' => $this->auth($request),
            'upload' => $this->upload($request),
            default => new Response(404),
        };
    }

    public function tusServer(): TusServer
    {
        $sanitizer = new FilenameSanitizer($this->config->maxFilenameBytes);

        return new TusServer(
            $this->config,
            new UploadRepository($this->config->stagingDir),
            new Finalizer($this->config->destinationDir, $this->config->userSubdirectory, $sanitizer),
            $sanitizer,
            $this->logger,
            $this->config->basePath() . 'upload.php',
        );
    }

    private function page(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response(405, ['Allow' => 'GET, HEAD']);
        }

        // Anonymous visitors get the sign-in page without a session, so unauthenticated
        // traffic can't fill var/sessions with files.
        if (!$this->session->hasCookie()) {
            return Response::html($this->render('login', ['title' => 'Sign in']));
        }

        $this->session->start();
        $user = $this->session->user();

        if ($user === null) {
            return Response::html($this->render('login', [
                'title' => 'Sign in',
                'ssoLogoutUrl' => $this->session->pull('sso_logout'),
            ]));
        }

        $this->session->touch();
        $clientConfig = [
            'endpoint' => $this->config->basePath() . 'upload.php',
            'csrfToken' => $this->session->csrfToken(),
            'chunkSize' => $this->config->chunkSize,
            'maxFileSize' => $this->config->maxFileSize > 0 ? $this->config->maxFileSize : null,
            'parallelFiles' => $this->config->parallelFiles,
            'user' => $user,
        ];

        return Response::html($this->render('upload', [
            'title' => 'Upload',
            'user' => $user,
            'csrfToken' => $this->session->csrfToken(),
            'clientConfig' => $clientConfig,
            'assetVersion' => $this->assetVersion(),
        ]));
    }

    private function auth(Request $request): Response
    {
        $this->session->start();

        if ($request->method === 'POST' && $request->queryString('action') === 'logout') {
            $token = $_POST['csrf'] ?? null;
            if (!$this->originCheck->allows($request) || !$this->session->verifyCsrf(is_string($token) ? $token : null)) {
                return Response::html($this->render('error', [
                    'title' => 'Sign-out failed',
                    'message' => 'The request could not be verified. Reload the page and try again.',
                ]), 403);
            }
            $this->logger->info('logout', ['user' => $this->session->user()]);
            $idToken = $this->session->idToken();
            $this->session->logout();

            $sso = $this->config->oidcSingleLogout
                ? (new OidcLogin($this->config, $this->session, $this->logger))->ssoLogout($idToken)
                : null;
            if ($sso?->type === SsoLogout::REDIRECT) {
                return Response::redirect($sso->url);
            }
            if ($sso?->type === SsoLogout::IFRAME) {
                $this->session->flash('sso_logout', $sso->url);
            }

            return Response::redirect($this->config->baseUrl);
        }

        if ($request->method !== 'GET') {
            return new Response(405, ['Allow' => 'GET, POST']);
        }

        if ($this->session->user() !== null && $request->queryString('code') === null) {
            return Response::redirect($this->config->baseUrl);
        }

        try {
            return (new OidcLogin($this->config, $this->session, $this->logger))->handle($request);
        } catch (LoginFailed $e) {
            return Response::html($this->render('error', [
                'title' => 'Sign-in failed',
                'message' => $e->getMessage(),
                'retry' => true,
            ]), 403);
        }
    }

    private function upload(Request $request): Response
    {
        $tus = $this->tusServer();

        // OPTIONS carries no credentials and only describes the server's capabilities.
        if ($request->method === 'OPTIONS') {
            return $tus->options();
        }

        if (!$this->originCheck->allows($request)) {
            return $this->uploadError('forbidden_origin', 403);
        }

        if (!$this->session->hasCookie()) {
            return $this->uploadError('session_expired', 401);
        }
        $this->session->start();
        $user = $this->session->user();
        if ($user === null) {
            $this->session->close();

            return $this->uploadError('session_expired', 401);
        }
        if (!$this->session->verifyCsrf($request->header('X-CSRF-Token'))) {
            $this->session->close();

            return $this->uploadError('csrf', 403);
        }
        $this->session->touch();
        // Release the session lock before the (possibly long) body read.
        $this->session->close();

        return $tus->handle($request, $user);
    }

    private function uploadError(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status, ['Tus-Resumable' => TusServer::VERSION]);
    }

    private function assetVersion(): string
    {
        $mtime = @filemtime($this->appRoot . '/public/assets/app.js');

        return $mtime === false ? '0' : (string) $mtime;
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): string
    {
        return self::renderStatic($template, $vars + ['basePath' => $this->config->basePath()], $this->appRoot);
    }

    /** @param array<string, mixed> $vars */
    private static function renderStatic(string $template, array $vars, string $appRoot): string
    {
        $vars += ['basePath' => './'];
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        try {
            (static function (string $__file, array $__vars, callable $e): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($appRoot . '/templates/' . $template . '.php', $vars, $e);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
