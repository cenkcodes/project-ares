<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionEnvironmentTemplateTest extends TestCase
{
    public function test_environment_example_has_safe_production_defaults(): void
    {
        $content =
            file_get_contents(
                base_path('.env.example')
            );

        $this->assertIsString(
            $content
        );

        $this->assertStringContainsString(
            'APP_NAME=Xurvexa',
            $content
        );

        $this->assertStringContainsString(
            'APP_ENV=production',
            $content
        );

        $this->assertStringContainsString(
            'APP_DEBUG=false',
            $content
        );

        $this->assertStringContainsString(
            'APP_URL=https://xurvexa.com',
            $content
        );

        $this->assertStringContainsString(
            'DB_CONNECTION=pgsql',
            $content
        );

        $this->assertStringContainsString(
            'SESSION_DRIVER=database',
            $content
        );

        $this->assertStringContainsString(
            'SESSION_ENCRYPT=true',
            $content
        );

        $this->assertStringContainsString(
            'SESSION_SECURE_COOKIE=true',
            $content
        );

        $this->assertStringContainsString(
            'SESSION_HTTP_ONLY=true',
            $content
        );

        $this->assertStringContainsString(
            'SESSION_SAME_SITE=lax',
            $content
        );

        $this->assertStringContainsString(
            'QUEUE_CONNECTION=database',
            $content
        );

        $this->assertStringContainsString(
            'CACHE_STORE=database',
            $content
        );

        $this->assertStringContainsString(
            'LOG_LEVEL=warning',
            $content
        );
    }

    public function test_environment_example_does_not_contain_production_secrets(): void
    {
        $content =
            file_get_contents(
                base_path('.env.example')
            );

        $this->assertIsString(
            $content
        );

        $this->assertMatchesRegularExpression(
            '/^APP_KEY=\s*$/m',
            $content
        );

        $this->assertMatchesRegularExpression(
            '/^DB_PASSWORD=\s*$/m',
            $content
        );

        $this->assertMatchesRegularExpression(
            '/^AWS_ACCESS_KEY_ID=\s*$/m',
            $content
        );

        $this->assertMatchesRegularExpression(
            '/^AWS_SECRET_ACCESS_KEY=\s*$/m',
            $content
        );
    }
}
