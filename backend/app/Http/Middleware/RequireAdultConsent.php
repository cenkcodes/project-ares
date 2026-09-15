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
            $this->isVerifiedGoogleSearchCrawler(
                $request
            )
        ) {
            return $next($request);
        }

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

    /*
     * Google explicitly recommends allowing its
     * verified Search crawlers to access content
     * behind an adult age gate.
     *
     * User-Agent alone is not trusted. A request
     * is exempted only when:
     * - it is GET/HEAD,
     * - its User-Agent is Googlebot or the
     *   Google Search inspection tool,
     * - the client IP reverse-resolves to an
     *   approved Google hostname, and
     * - that hostname forward-resolves back to
     *   the same client IP.
     *
     * Normal visitors keep the existing age-gate
     * flow unchanged.
     */
    private function isVerifiedGoogleSearchCrawler(
        Request $request
    ): bool {
        if (
            ! $request->isMethod('GET') &&
            ! $request->isMethod('HEAD')
        ) {
            return false;
        }

        $userAgent =
            strtolower(
                (string) $request->userAgent()
            );

        if (
            $userAgent === '' ||
            (
                ! str_contains(
                    $userAgent,
                    'googlebot'
                ) &&
                ! str_contains(
                    $userAgent,
                    'google-inspectiontool'
                )
            )
        ) {
            return false;
        }

        $clientIp =
            $request->ip();

        if (
            ! is_string(
                $clientIp
            ) ||
            filter_var(
                $clientIp,
                FILTER_VALIDATE_IP
            ) === false
        ) {
            return false;
        }

        $hostname =
            @gethostbyaddr(
                $clientIp
            );

        if (
            ! is_string(
                $hostname
            ) ||
            $hostname === '' ||
            $hostname === $clientIp
        ) {
            return false;
        }

        $hostname =
            strtolower(
                rtrim(
                    $hostname,
                    '.'
                )
            );

        if (
            ! $this->isApprovedGoogleHostname(
                $hostname
            )
        ) {
            return false;
        }

        $records =
            @dns_get_record(
                $hostname,
                DNS_A | DNS_AAAA
            );

        if (! is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            $resolvedIp =
                $record['ip']
                ?? $record['ipv6']
                ?? null;

            if (
                is_string(
                    $resolvedIp
                ) &&
                $this->ipAddressesMatch(
                    $clientIp,
                    $resolvedIp
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function isApprovedGoogleHostname(
        string $hostname
    ): bool {
        return
            $hostname === 'googlebot.com' ||
            str_ends_with(
                $hostname,
                '.googlebot.com'
            ) ||
            $hostname === 'google.com' ||
            str_ends_with(
                $hostname,
                '.google.com'
            );
    }

    private function ipAddressesMatch(
        string $left,
        string $right
    ): bool {
        $leftBinary =
            @inet_pton(
                $left
            );

        $rightBinary =
            @inet_pton(
                $right
            );

        return
            $leftBinary !== false &&
            $rightBinary !== false &&
            $leftBinary === $rightBinary;
    }
}
