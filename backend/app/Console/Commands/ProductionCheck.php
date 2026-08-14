<?php

namespace App\Console\Commands;

use App\Support\CloudflareNetworks;
use Illuminate\Console\Command;

class ProductionCheck extends Command
{
    protected $signature =
        'app:production-check';

    protected $description =
        'Check critical Xurvexa production configuration before deployment';

    public function handle(): int
    {
        $cloudflareProxies =
            CloudflareNetworks::trustedProxies();

        $unsafeProxyEntries = [
            '*',
            '0.0.0.0/0',
            '::/0',
        ];

        $cloudflareProxyAllowlistIsSafe =
            count($cloudflareProxies) === 22 &&
            empty(
                array_intersect(
                    $unsafeProxyEntries,
                    $cloudflareProxies
                )
            );

        $checks = [
            [
                'name' =>
                    'Application environment',

                'expected' =>
                    'production',

                'actual' =>
                    (string) config(
                        'app.env'
                    ),

                'passes' =>
                    config('app.env') ===
                    'production',
            ],

            [
                'name' =>
                    'Debug mode',

                'expected' =>
                    'false',

                'actual' =>
                    config('app.debug')
                        ? 'true'
                        : 'false',

                'passes' =>
                    config('app.debug') ===
                    false,
            ],

            [
                'name' =>
                    'Application URL',

                'expected' =>
                    'https://xurvexa.com',

                'actual' =>
                    (string) config(
                        'app.url'
                    ),

                'passes' =>
                    config('app.url') ===
                    'https://xurvexa.com',
            ],

            [
                'name' =>
                    'Cloudflare proxy allowlist',

                'expected' =>
                    '22 restricted CIDR ranges',

                'actual' =>
                    count($cloudflareProxies) .
                    ' CIDR ranges',

                'passes' =>
                    $cloudflareProxyAllowlistIsSafe,
            ],

            [
                'name' =>
                    'Application key',

                'expected' =>
                    'configured',

                'actual' =>
                    filled(
                        config('app.key')
                    )
                        ? 'configured'
                        : 'missing',

                'passes' =>
                    filled(
                        config('app.key')
                    ),
            ],

            [
                'name' =>
                    'Database connection',

                'expected' =>
                    'pgsql',

                'actual' =>
                    (string) config(
                        'database.default'
                    ),

                'passes' =>
                    config(
                        'database.default'
                    ) === 'pgsql',
            ],

            [
                'name' =>
                    'Queue connection',

                'expected' =>
                    'database',

                'actual' =>
                    (string) config(
                        'queue.default'
                    ),

                'passes' =>
                    config(
                        'queue.default'
                    ) === 'database',
            ],

            [
                'name' =>
                    'Cache store',

                'expected' =>
                    'database',

                'actual' =>
                    (string) config(
                        'cache.default'
                    ),

                'passes' =>
                    config(
                        'cache.default'
                    ) === 'database',
            ],

            [
                'name' =>
                    'Session driver',

                'expected' =>
                    'database',

                'actual' =>
                    (string) config(
                        'session.driver'
                    ),

                'passes' =>
                    config(
                        'session.driver'
                    ) === 'database',
            ],

            [
                'name' =>
                    'Session encryption',

                'expected' =>
                    'true',

                'actual' =>
                    config(
                        'session.encrypt'
                    )
                        ? 'true'
                        : 'false',

                'passes' =>
                    config(
                        'session.encrypt'
                    ) === true,
            ],

            [
                'name' =>
                    'Secure session cookie',

                'expected' =>
                    'true',

                'actual' =>
                    config(
                        'session.secure'
                    )
                        ? 'true'
                        : 'false',

                'passes' =>
                    config(
                        'session.secure'
                    ) === true,
            ],

            [
                'name' =>
                    'HTTP-only session cookie',

                'expected' =>
                    'true',

                'actual' =>
                    config(
                        'session.http_only'
                    )
                        ? 'true'
                        : 'false',

                'passes' =>
                    config(
                        'session.http_only'
                    ) === true,
            ],

            [
                'name' =>
                    'SameSite session cookie',

                'expected' =>
                    'lax',

                'actual' =>
                    (string) config(
                        'session.same_site'
                    ),

                'passes' =>
                    config(
                        'session.same_site'
                    ) === 'lax',
            ],

            [
                'name' =>
                    'Session cookie name',

                'expected' =>
                    'xurvexa-session',

                'actual' =>
                    (string) config(
                        'session.cookie'
                    ),

                'passes' =>
                    config(
                        'session.cookie'
                    ) ===
                    'xurvexa-session',
            ],
        ];

        $rows = [];

        foreach ($checks as $check) {
            $rows[] = [
                $check['passes']
                    ? 'PASS'
                    : 'FAIL',

                $check['name'],

                $check['expected'],

                $check['actual'],
            ];
        }

        $this->newLine();

        $this->info(
            'Xurvexa Production Readiness Check'
        );

        $this->newLine();

        $this->table(
            [
                'Status',
                'Check',
                'Expected',
                'Actual',
            ],
            $rows
        );

        $failedChecks =
            collect($checks)
                ->where(
                    'passes',
                    false
                );

        $this->newLine();

        if ($failedChecks->isEmpty()) {
            $this->info(
                'Production configuration is ready.'
            );

            return self::SUCCESS;
        }

        $this->error(
            $failedChecks->count() .
            ' production configuration check(s) failed.'
        );

        $this->warn(
            'Do not deploy Xurvexa publicly until all checks pass.'
        );

        return self::FAILURE;
    }
}
