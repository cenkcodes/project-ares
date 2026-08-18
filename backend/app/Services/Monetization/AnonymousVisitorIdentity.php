<?php

namespace App\Services\Monetization;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class AnonymousVisitorIdentity
{
    public const COOKIE_NAME =
        'xurvexa_visitor_id';

    /*
     * 400 days keeps the anonymous visitor identity
     * stable across sessions while still allowing
     * natural rotation over time.
     */
    private const COOKIE_MINUTES =
        400 * 24 * 60;

    public function resolve(
        Request $request
    ): string {
        $existing = $request->cookie(
            self::COOKIE_NAME
        );

        if (
            is_string($existing)
            && Str::isUuid($existing)
        ) {
            return $existing;
        }

        $visitorKey = (string) Str::uuid();

        Cookie::queue(
            $this->makeCookie(
                $visitorKey
            )
        );

        return $visitorKey;
    }

    public function queueRotation(): string
    {
        $visitorKey = (string) Str::uuid();

        Cookie::queue(
            $this->makeCookie(
                $visitorKey
            )
        );

        return $visitorKey;
    }

    public function queueForget(): void
    {
        Cookie::queue(
            Cookie::forget(
                self::COOKIE_NAME,
                '/',
                null
            )
        );
    }

    private function makeCookie(
        string $visitorKey
    ): SymfonyCookie {
        return Cookie::make(
            name: self::COOKIE_NAME,
            value: $visitorKey,
            minutes: self::COOKIE_MINUTES,
            path: '/',
            domain: null,
            secure: null,
            httpOnly: true,
            raw: false,
            sameSite: 'lax'
        );
    }
}
