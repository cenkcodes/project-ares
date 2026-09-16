<?php

use App\Http\Middleware\SecurityHeaders;
use App\Support\CloudflareNetworks;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(
    basePath: dirname(__DIR__)
)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(
        function (Middleware $middleware): void {
            $middleware->trustHosts(
                at: fn (): array =>
                    app()->environment('production')
                        ? [
                            '^xurvexa\.com$',
                            '^www\.xurvexa\.com$',
                        ]
                        : [
                            '.*',
                        ],
                subdomains: false,
            );

            $middleware->trustProxies(
                at:
                    CloudflareNetworks::trustedProxies(),

                headers:
                    Request::HEADER_X_FORWARDED_FOR |
                    Request::HEADER_X_FORWARDED_PROTO
            );

            /*
             * These consent-state cookies are written
             * directly by browser JavaScript and must
             * remain readable by Laravel as plain
             * values ("0" / "1" / version string).
             *
             * Analytics visitor/session identifiers
             * are intentionally NOT excluded here;
             * those Laravel-managed cookies remain
             * encrypted.
             */
            $middleware->encryptCookies(
                except: [
                    'xurvexa_consent_version',
                    'xurvexa_consent_analytics',
                    'xurvexa_consent_advertising',
                ]
            );

            $middleware->append(
                SecurityHeaders::class
            );
        }
    )
    ->withExceptions(
        function (Exceptions $exceptions): void {
            $exceptions->shouldRenderJsonWhen(
                fn (Request $request): bool =>
                    $request->expectsJson() ||
                    $request->is('api/*'),
            );
        }
    )
    ->create();
