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

        $mailMailer =
            strtolower(
                trim(
                    (string) config(
                        'mail.default'
                    )
                )
            );

        $unsafeMailers = [
            '',
            'log',
            'array',
        ];

        $mailTransportIsProductionReady =
            ! in_array(
                $mailMailer,
                $unsafeMailers,
                true
            );

        $mailTransportConfiguration =
            $this->inspectMailerConfiguration(
                $mailMailer
            );

        $mailFromAddress =
            strtolower(
                trim(
                    (string) config(
                        'mail.from.address'
                    )
                )
            );

        $mailFromAddressIsProductionReady =
            filter_var(
                $mailFromAddress,
                FILTER_VALIDATE_EMAIL
            ) !== false &&
            $mailFromAddress ===
                'no-reply@xurvexa.com';

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
                    'Mail transport',

                'expected' =>
                    'production mail transport',

                'actual' =>
                    $mailMailer !== ''
                        ? $mailMailer
                        : 'missing',

                'passes' =>
                    $mailTransportIsProductionReady,
            ],

            [
                'name' =>
                    'Mail transport configuration',

                'expected' =>
                    'safe production delivery configuration',

                'actual' =>
                    $mailTransportConfiguration[
                        'actual'
                    ],

                'passes' =>
                    $mailTransportConfiguration[
                        'passes'
                    ],
            ],

            [
                'name' =>
                    'Mail from address',

                'expected' =>
                    'no-reply@xurvexa.com',

                'actual' =>
                    $mailFromAddress !== ''
                        ? $mailFromAddress
                        : 'missing',

                'passes' =>
                    $mailFromAddressIsProductionReady,
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

    /**
     * Inspect the selected mailer and reject configurations that
     * cannot represent a safe production delivery path.
     *
     * @param array<int, string> $visitedMailers
     * @return array{passes: bool, actual: string}
     */
    private function inspectMailerConfiguration(
        string $mailer,
        array $visitedMailers = []
    ): array {
        $mailer =
            strtolower(
                trim($mailer)
            );

        if ($mailer === '') {
            return [
                'passes' => false,
                'actual' => 'missing mailer',
            ];
        }

        if (
            in_array(
                $mailer,
                [
                    'log',
                    'array',
                ],
                true
            )
        ) {
            return [
                'passes' => false,
                'actual' =>
                    "{$mailer} is not a delivery transport",
            ];
        }

        if (
            in_array(
                $mailer,
                $visitedMailers,
                true
            )
        ) {
            return [
                'passes' => false,
                'actual' =>
                    "circular mailer reference: {$mailer}",
            ];
        }

        $mailerConfig =
            config(
                "mail.mailers.{$mailer}"
            );

        if (! is_array($mailerConfig)) {
            return [
                'passes' => false,
                'actual' =>
                    "mailer configuration missing: {$mailer}",
            ];
        }

        $transport =
            strtolower(
                trim(
                    (string) (
                        $mailerConfig[
                            'transport'
                        ] ?? ''
                    )
                )
            );

        if ($transport === '') {
            return [
                'passes' => false,
                'actual' =>
                    "transport missing: {$mailer}",
            ];
        }

        if (
            in_array(
                $transport,
                [
                    'log',
                    'array',
                ],
                true
            )
        ) {
            return [
                'passes' => false,
                'actual' =>
                    "{$transport} is not a delivery transport",
            ];
        }

        $visitedMailers[] =
            $mailer;

        if ($transport === 'smtp') {
            return $this
                ->inspectSmtpConfiguration(
                    $mailerConfig
                );
        }

        if (
            in_array(
                $transport,
                [
                    'failover',
                    'roundrobin',
                ],
                true
            )
        ) {
            $memberMailers =
                $mailerConfig[
                    'mailers'
                ] ?? [];

            if (
                ! is_array(
                    $memberMailers
                ) ||
                $memberMailers === []
            ) {
                return [
                    'passes' => false,
                    'actual' =>
                        "{$transport} has no mailers",
                ];
            }

            $memberNames = [];

            foreach (
                $memberMailers
                as $memberMailer
            ) {
                $memberMailer =
                    strtolower(
                        trim(
                            (string)
                            $memberMailer
                        )
                    );

                if ($memberMailer === '') {
                    return [
                        'passes' => false,
                        'actual' =>
                            "{$transport} contains an empty mailer",
                    ];
                }

                $memberNames[] =
                    $memberMailer;

                $memberResult =
                    $this
                        ->inspectMailerConfiguration(
                            $memberMailer,
                            $visitedMailers
                        );

                if (
                    ! $memberResult[
                        'passes'
                    ]
                ) {
                    return [
                        'passes' => false,
                        'actual' =>
                            "{$transport} contains {$memberMailer}: " .
                            $memberResult[
                                'actual'
                            ],
                    ];
                }
            }

            return [
                'passes' => true,
                'actual' =>
                    "{$transport}: " .
                    implode(
                        ', ',
                        $memberNames
                    ),
            ];
        }

        if ($transport === 'sendmail') {
            $path =
                trim(
                    (string) (
                        $mailerConfig[
                            'path'
                        ] ?? ''
                    )
                );

            if ($path === '') {
                return [
                    'passes' => false,
                    'actual' =>
                        'sendmail path missing',
                ];
            }

            return [
                'passes' => true,
                'actual' =>
                    'sendmail configured',
            ];
        }

        $supportedApiTransports = [
            'mailgun',
            'ses',
            'ses-v2',
            'postmark',
            'resend',
        ];

        if (
            ! in_array(
                $transport,
                $supportedApiTransports,
                true
            )
        ) {
            return [
                'passes' => false,
                'actual' =>
                    "unsupported mail transport: {$transport}",
            ];
        }

        return [
            'passes' => true,
            'actual' =>
                "{$mailer} ({$transport})",
        ];
    }

    /**
     * Inspect SMTP without exposing credentials in command output.
     *
     * @param array<string, mixed> $mailerConfig
     * @return array{passes: bool, actual: string}
     */
    private function inspectSmtpConfiguration(
        array $mailerConfig
    ): array {
        $url =
            trim(
                (string) (
                    $mailerConfig[
                        'url'
                    ] ?? ''
                )
            );

        $host = null;

        $port =
            $mailerConfig[
                'port'
            ] ?? null;

        if ($url !== '') {
            $urlHost =
                parse_url(
                    $url,
                    PHP_URL_HOST
                );

            if (
                ! is_string(
                    $urlHost
                ) ||
                trim($urlHost) === ''
            ) {
                return [
                    'passes' => false,
                    'actual' =>
                        'SMTP URL has no valid host',
                ];
            }

            $host =
                $urlHost;

            $urlPort =
                parse_url(
                    $url,
                    PHP_URL_PORT
                );

            if ($urlPort !== null) {
                $port =
                    $urlPort;
            }
        } else {
            $host =
                $mailerConfig[
                    'host'
                ] ?? null;
        }

        $host =
            strtolower(
                trim(
                    (string) $host,
                    " \t\n\r\0\x0B[]"
                )
            );

        if ($host === '') {
            return [
                'passes' => false,
                'actual' =>
                    'SMTP host missing',
            ];
        }

        $unsafeHosts = [
            'localhost',
            'localhost.localdomain',
            '127.0.0.1',
            '0.0.0.0',
            '::1',
            'mailhog',
            'mailpit',
        ];

        if (
            in_array(
                $host,
                $unsafeHosts,
                true
            )
        ) {
            return [
                'passes' => false,
                'actual' =>
                    "unsafe SMTP host: {$host}",
            ];
        }

        $validatedPort =
            filter_var(
                $port,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => 65535,
                    ],
                ]
            );

        if ($validatedPort === false) {
            return [
                'passes' => false,
                'actual' =>
                    'SMTP port missing or invalid',
            ];
        }

        return [
            'passes' => true,
            'actual' =>
                "smtp {$host}:{$validatedPort}",
        ];
    }
}
