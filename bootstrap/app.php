<?php

declare(strict_types=1);

use App\Exception\ExceptionHandler;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackUserActivityMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            if (app()->environment(['local', 'testing'])) {
                Route::middleware('web')->group(__DIR__.'/../routes/debug.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TLS is terminated by the reverse proxy in front of the application (deploy/), so every
         * request reaches PHP as plain HTTP. Without this, the client address is the proxy's, and
         * the framework believes the connection is insecure — which downgrades generated URLs and
         * makes it set secure session cookies on a connection it thinks cannot carry them.
         *
         * Which addresses to believe lives in config/trustedproxy.php, so a deployment can override
         * it with TRUSTED_PROXIES. The headers stay here — they describe the proxy protocol this
         * application speaks, not the topology it runs in.
         */
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'layout', 'sort_preferences']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackUserActivityMiddleware::class,
        ]);
    })
    ->withExceptions(new ExceptionHandler)->create();
