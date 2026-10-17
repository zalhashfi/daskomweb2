<?php

use App\Exceptions\RenderApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable as ThrowableContract;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: [
            'auth',
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'api' => [
                'throttle:api',
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ],
            'check.permission' => \App\Http\Middleware\CheckPermission::class,
            'check.auth' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'auth.broadcasting' => \App\Http\Middleware\AuthenticateBroadcasting::class,
            'audit.assistant' => \App\Http\Middleware\LogAssistantAction::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $apiExceptions = new RenderApiException;

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, ThrowableContract $e): bool => $apiExceptions->isApiRequest($request) || $request->expectsJson()
        );

        $exceptions->renderable(function (ThrowableContract $e, Request $request) use ($apiExceptions) {
            return $apiExceptions->render($e, $request);
        });

        $exceptions->report(function (ThrowableContract $e) use ($apiExceptions) {
            $request = request();

            if (! $apiExceptions->isApiRequest($request)) {
                return null;
            }

            $apiExceptions->report($e, $request);

            return false;
        })->stop();

        $exceptions->respond(function ($response, ThrowableContract $e, Request $request) use ($apiExceptions) {
            if (! $response instanceof Response) {
                return $response;
            }

            $apiExceptions->prepareRequest($request);

            if (! $apiExceptions->isApiRequest($request)) {
                return $response;
            }

            if ($response->getStatusCode() < 500) {
                return $response->headers->has('X-Error-Id')
                    ? $response
                    : tap($response, fn (Response $response) => $response->headers->set('X-Error-Id', $apiExceptions->errorIdFor($e)));
            }

            return response()->json([
                'status' => 'error',
                'code' => RenderApiException::CODE_INTERNAL,
                'error_id' => $apiExceptions->errorIdFor($e),
            ], 500);
        });
    })->create();
