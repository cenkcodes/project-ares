<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class TrafficAnalyticsIdentity
{
    public const CONSENT_COOKIE =
        'xurvexa_consent_analytics';

    public const VISITOR_COOKIE =
        'xurvexa_analytics_visitor';

    public const SESSION_COOKIE =
        'xurvexa_analytics_session';

    private const VISITOR_MINUTES =
        400 * 24 * 60;

    private const SESSION_MINUTES =
        30;

    public function hasConsent(
        Request $request
    ): bool {
        return (
            (string) $request->cookie(
                self::CONSENT_COOKIE,
                '0'
            )
            === '1'
        );
    }

    public function resolveVisitor(
        Request $request
    ): string {
        $visitorUuid =
            $this->validUuidFromCookie(
                $request,
                self::VISITOR_COOKIE
            )
            ?? (string) Str::uuid();

        Cookie::queue(
            $this->makeCookie(
                name:
                    self::VISITOR_COOKIE,

                value:
                    $visitorUuid,

                minutes:
                    self::VISITOR_MINUTES
            )
        );

        return $visitorUuid;
    }

    public function resolveSession(
        Request $request
    ): string {
        $sessionUuid =
            $this->validUuidFromCookie(
                $request,
                self::SESSION_COOKIE
            )
            ?? (string) Str::uuid();

        /*
         * Re-queue on every tracked page request so
         * the 30-minute analytics session window is
         * sliding rather than absolute.
         */
        Cookie::queue(
            $this->makeCookie(
                name:
                    self::SESSION_COOKIE,

                value:
                    $sessionUuid,

                minutes:
                    self::SESSION_MINUTES
            )
        );

        return $sessionUuid;
    }

    public function queueForget(): void
    {
        Cookie::queue(
            Cookie::forget(
                self::VISITOR_COOKIE,
                '/',
                null
            )
        );

        Cookie::queue(
            Cookie::forget(
                self::SESSION_COOKIE,
                '/',
                null
            )
        );
    }

    private function validUuidFromCookie(
        Request $request,
        string $cookieName
    ): ?string {
        $value =
            $request->cookie(
                $cookieName
            );

        if (
            ! is_string($value)
            || ! Str::isUuid($value)
        ) {
            return null;
        }

        return $value;
    }

    private function makeCookie(
        string $name,
        string $value,
        int $minutes
    ): SymfonyCookie {
        return Cookie::make(
            name:
                $name,

            value:
                $value,

            minutes:
                $minutes,

            path:
                '/',

            domain:
                null,

            secure:
                null,

            httpOnly:
                true,

            raw:
                false,

            sameSite:
                'lax'
        );
    }
}
