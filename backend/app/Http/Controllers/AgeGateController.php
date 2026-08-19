<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireAdultConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgeGateController extends Controller
{
    private const COOKIE_MINUTES =
        60 * 24 * 365;

    public function show(): View
    {
        return view(
            'age-gate.show'
        );
    }

    public function accept(
        Request $request
    ): RedirectResponse {
        $intendedUrl =
            $request->session()->pull(
                RequireAdultConsent::INTENDED_SESSION_KEY
            );

        $destination =
            $this->safeDestination(
                $intendedUrl
            );

        $response =
            redirect(
                $destination
            );

        $response->withCookie(
            cookie(
                RequireAdultConsent::COOKIE_NAME,
                RequireAdultConsent::COOKIE_VALUE,
                self::COOKIE_MINUTES,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'Lax'
            )
        );

        return $response;
    }

    public function deny(
        Request $request
    ): RedirectResponse {
        $request->session()->forget(
            RequireAdultConsent::INTENDED_SESSION_KEY
        );

        $response =
            redirect()->route(
                'age-gate.denied'
            );

        $response->withCookie(
            cookie()->forget(
                RequireAdultConsent::COOKIE_NAME
            )
        );

        return $response;
    }

    private function safeDestination(
        mixed $intendedUrl
    ): string {
        if (
            !is_string(
                $intendedUrl
            ) ||
            $intendedUrl === '' ||
            !str_starts_with(
                $intendedUrl,
                '/'
            ) ||
            str_starts_with(
                $intendedUrl,
                '//'
            )
        ) {
            return route(
                'home',
                absolute: false
            );
        }

        return $intendedUrl;
    }
}
