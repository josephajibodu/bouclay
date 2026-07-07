<?php

use App\Exceptions\Api\InvalidPaginationCursor;
use App\Exceptions\Subscriptions\IllegalStateTransition;
use App\Http\Middleware\AssignApiRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        $middleware->api(prepend: [
            AssignApiRequestId::class,
            AuthenticateApiKey::class,
            EnsureIdempotency::class,
        ]);

        $middleware->preventRequestForgery(except: [
            'ingres/*',
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (IllegalStateTransition $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'conflict',
                $exception->getMessage(),
                409,
                request: $request,
            );
        });

        $exceptions->render(function (InvalidPaginationCursor $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'invalid_cursor',
                $exception->getMessage(),
                400,
                request: $request,
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = collect($exception->errors())
                ->flatMap(fn (array $messages, string $field) => collect($messages)->map(fn (string $message) => [
                    'field' => $field,
                    'message' => $message,
                ]))
                ->values()
                ->all();

            return ApiResponse::error(
                'invalid_field',
                'Request does not pass validation.',
                422,
                errors: $errors,
                request: $request,
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'not_found',
                'The requested resource was not found.',
                404,
                request: $request,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();

            if ($status === 404) {
                return null;
            }

            return ApiResponse::error(
                match ($status) {
                    403 => 'permission_denied',
                    422 => 'invalid_field',
                    default => 'request_error',
                },
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $status,
                request: $request,
            );
        });
    })->create();
