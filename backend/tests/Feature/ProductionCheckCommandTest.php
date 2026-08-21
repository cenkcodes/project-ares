<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionCheckCommandTest extends TestCase
{
    public function test_production_check_passes_with_secure_configuration(): void
    {
        config([
            'app.env' =>
                'production',

            'app.debug' =>
                false,

            'app.url' =>
                'https://xurvexa.com',

            'app.key' =>
                'base64:test-production-key',

            'mail.default' =>
                'smtp',

            'mail.from.address' =>
                'no-reply@xurvexa.com',

            'database.default' =>
                'pgsql',

            'queue.default' =>
                'database',

            'cache.default' =>
                'database',

            'session.driver' =>
                'database',

            'session.encrypt' =>
                true,

            'session.secure' =>
                true,

            'session.http_only' =>
                true,

            'session.same_site' =>
                'lax',

            'session.cookie' =>
                'xurvexa-session',
        ]);

        $exitCode =
            Artisan::call(
                'app:production-check'
            );

        $output =
            Artisan::output();

        $this->assertSame(
            0,
            $exitCode
        );

        $this->assertStringContainsString(
            'Production configuration is ready.',
            $output
        );

        $this->assertStringContainsString(
            'Mail transport',
            $output
        );

        $this->assertStringContainsString(
            'Mail from address',
            $output
        );

        $this->assertStringNotContainsString(
            'FAIL',
            $output
        );
    }

    public function test_production_check_fails_with_unsafe_configuration(): void
    {
        config([
            'app.env' =>
                'local',

            'app.debug' =>
                true,

            'app.url' =>
                'http://localhost',

            'app.key' =>
                null,

            'mail.default' =>
                'log',

            'mail.from.address' =>
                'hello@example.com',

            'database.default' =>
                'sqlite',

            'queue.default' =>
                'sync',

            'cache.default' =>
                'array',

            'session.driver' =>
                'file',

            'session.encrypt' =>
                false,

            'session.secure' =>
                false,

            'session.http_only' =>
                false,

            'session.same_site' =>
                null,

            'session.cookie' =>
                'laravel-session',
        ]);

        $exitCode =
            Artisan::call(
                'app:production-check'
            );

        $output =
            Artisan::output();

        $this->assertSame(
            1,
            $exitCode
        );

        $this->assertStringContainsString(
            'FAIL',
            $output
        );

        $this->assertStringContainsString(
            'Mail transport',
            $output
        );

        $this->assertStringContainsString(
            'Mail from address',
            $output
        );

        $this->assertStringContainsString(
            'Do not deploy Xurvexa publicly until all checks pass.',
            $output
        );
    }
}
