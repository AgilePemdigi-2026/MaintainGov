<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureGitHubMockEnabled;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'github.mock' => EnsureGitHubMockEnabled::class,
        ]);

        $middleware->api(append: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
        ]);

        // GitHub signs webhook payloads with HMAC; it cannot send a browser
        // CSRF token. Signature verification is enforced in the controller.
        $middleware->validateCsrfTokens(except: [
            'api/v1/webhooks/github',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || str_contains((string) $request->header('Accept', ''), 'application/json'),
        );

        // SRS 3.4.5: envelope JSON Bahasa Indonesia hanya untuk client yang
        // berkomunikasi JSON; pesan teknis (SQL, stack) tidak pernah bocor.
        $wantsJsonEnvelope = fn (Request $request): bool => str_contains((string) $request->header('Accept', ''), 'application/json')
            || (bool) $request->expectsJson();

        // UC04/UC05 domain errors use a stable error.code envelope.
        $exceptions->render(function (ApiException $e, Request $request) use ($wantsJsonEnvelope) {
            if (! $wantsJsonEnvelope($request)) {
                return null;
            }

            $error = [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ];

            if ($e->retryAfterSeconds !== null) {
                $error['retry_after_seconds'] = $e->retryAfterSeconds;
            }

            $response = response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $error,
                'data' => $e->data,
            ], $e->status);

            if ($e->retryAfterSeconds !== null) {
                $response->header('Retry-After', (string) $e->retryAfterSeconds);
            }

            return $response;
        });

        // Route tidak ditemukan (mis. typo path /maintenance-backlogs dengan
        // tambahan 's'). Laravel 13 router melempar NotFoundHttpException
        // dengan pola pesan "The route ... could not be found." — diganti
        // envelope Bahasa Indonesia. Pesan kustom dari kode (mis.
        // abort_unless(..., 404, '...')) dipertahankan apa adanya.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJsonEnvelope) {
            if (! $wantsJsonEnvelope($request)) {
                return null; // serahkan ke renderer bawaan
            }

            $message = $e->getMessage();
            $fromRouter = $message === ''
                || (str_starts_with($message, 'The route ') && str_ends_with($message, ' could not be found.'));
            if (! $fromRouter) {
                return null; // pesan kustom: biarkan renderer default (translated via shouldRenderJsonWhen)
            }

            return response()->json([
                'success' => false, 'message' => 'Endpoint tidak ditemukan.', 'data' => null,
            ], 404);
        });

        // Kesalahan database (SQLSTATE) yang lolos dari handler controller:
        // pesan teknis tidak pernah dikirim ke client.
        $exceptions->render(function (QueryException $e, Request $request) use ($wantsJsonEnvelope) {
            if (! $wantsJsonEnvelope($request)) {
                return null; // serahkan ke renderer bawaan
            }

            return response()->json([
                'success' => false, 'message' => 'Terjadi kesalahan pada database.', 'data' => null,
            ], 500);
        });
    })->create();
