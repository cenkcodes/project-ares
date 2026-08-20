<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdultConsent
{
    public const COOKIE_NAME =
        'xurvexa_adult_verified';

    public const COOKIE_VALUE =
        '1';

    public const INTENDED_SESSION_KEY =
        'xurvexa.age_gate.intended_url';

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        if (
            (string) $request->cookie(
                self::COOKIE_NAME
            ) === self::COOKIE_VALUE
        ) {
            return $next($request);
        }

        /*
         * Monetization/runtime requests must fail
         * closed rather than redirecting to HTML.
         */
        if (
            $request->expectsJson() ||
            $request->is('monetization/*')
        ) {
            return response()->json(
                [
                    'message' =>
                        'Adult consent is required.',
                ],
                403
            );
        }

        /*
         * Preserve only the local request URI.
         * This avoids accepting an external
         * redirect destination from the client.
         */
        if ($request->isMethod('GET')) {
            $request->session()->put(
                self::INTENDED_SESSION_KEY,
                $request->getRequestUri()
            );
        }

        return redirect()->route(
            'age-gate.show'
        );
    }
}
