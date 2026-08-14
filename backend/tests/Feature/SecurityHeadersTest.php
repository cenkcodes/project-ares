<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_public_responses_include_security_headers(): void
    {
        $response =
            $this->get('/robots.txt');

        $response->assertOk();

        $response->assertHeader(
            'X-Content-Type-Options',
            'nosniff'
        );

        $response->assertHeader(
            'X-Frame-Options',
            'SAMEORIGIN'
        );

        $response->assertHeader(
            'Referrer-Policy',
            'strict-origin-when-cross-origin'
        );

        $response->assertHeader(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );
    }

    public function test_hsts_is_not_sent_on_local_http_responses(): void
    {
        $response =
            $this->get('/robots.txt');

        $response->assertOk();

        $response->assertHeaderMissing(
            'Strict-Transport-Security'
        );
    }

    public function test_hsts_is_sent_on_secure_production_responses(): void
    {
        config([
            'app.env' =>
                'production',
        ]);

        $response =
            $this->get(
                'https://xurvexa.com/robots.txt'
            );

        $response->assertOk();

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000'
        );
    }

    public function test_trusted_cloudflare_proxy_can_forward_client_ip_and_https_scheme(): void
    {
        config([
            'app.env' =>
                'production',
        ]);

        Route::get(
            '/_tests/trusted-proxy',
            function (Request $request) {
                return response()->json([
                    'ip' =>
                        $request->ip(),

                    'secure' =>
                        $request->isSecure(),
                ]);
            }
        );

        $response =
            $this
                ->withServerVariables([
                    'REMOTE_ADDR' =>
                        '173.245.48.10',
                ])
                ->withHeaders([
                    'X-Forwarded-For' =>
                        '203.0.113.10',

                    'X-Forwarded-Proto' =>
                        'https',
                ])
                ->get(
                    'http://xurvexa.com/_tests/trusted-proxy'
                );

        $response
            ->assertOk()
            ->assertJson([
                'ip' =>
                    '203.0.113.10',

                'secure' =>
                    true,
            ]);

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000'
        );
    }

    public function test_untrusted_proxy_cannot_spoof_client_ip_or_https_scheme(): void
    {
        config([
            'app.env' =>
                'production',
        ]);

        Route::get(
            '/_tests/untrusted-proxy',
            function (Request $request) {
                return response()->json([
                    'ip' =>
                        $request->ip(),

                    'secure' =>
                        $request->isSecure(),
                ]);
            }
        );

        $response =
            $this
                ->withServerVariables([
                    'REMOTE_ADDR' =>
                        '127.0.0.1',
                ])
                ->withHeaders([
                    'X-Forwarded-For' =>
                        '198.51.100.10',

                    'X-Forwarded-Proto' =>
                        'https',
                ])
                ->get(
                    'http://xurvexa.com/_tests/untrusted-proxy'
                );

        $response
            ->assertOk()
            ->assertJson([
                'ip' =>
                    '127.0.0.1',

                'secure' =>
                    false,
            ]);

        $response->assertHeaderMissing(
            'Strict-Transport-Security'
        );
    }
}
