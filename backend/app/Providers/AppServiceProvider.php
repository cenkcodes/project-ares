<?php

namespace App\Providers;

use App\Services\Monetization\MonetizationSessionState;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureMonetizationRateLimiters();
    }

    private function configureMonetizationRateLimiters(): void
    {
        RateLimiter::for(
            'monetization-interaction',
            function (Request $request): array {
                return [
                    Limit::perMinute(12)
                        ->by(
                            $this->monetizationSessionKey(
                                'interaction'
                            )
                        ),

                    Limit::perMinute(60)
                        ->by(
                            $this->monetizationIpKey(
                                $request,
                                'interaction'
                            )
                        ),
                ];
            }
        );

        RateLimiter::for(
            'monetization-decision',
            function (Request $request): array {
                return [
                    Limit::perMinute(30)
                        ->by(
                            $this->monetizationSessionKey(
                                'decision'
                            )
                        ),

                    Limit::perMinute(120)
                        ->by(
                            $this->monetizationIpKey(
                                $request,
                                'decision'
                            )
                        ),
                ];
            }
        );

        RateLimiter::for(
            'monetization-event',
            function (Request $request): array {
                return [
                    Limit::perMinute(60)
                        ->by(
                            $this->monetizationSessionKey(
                                'event'
                            )
                        ),

                    Limit::perMinute(240)
                        ->by(
                            $this->monetizationIpKey(
                                $request,
                                'event'
                            )
                        ),
                ];
            }
        );
    }

    private function monetizationSessionKey(
        string $endpoint
    ): string {
        $sessionKey =
            app(
                MonetizationSessionState::class
            )->sessionKey();

        return sprintf(
            'monetization:%s:session:%s',
            $endpoint,
            hash(
                'sha256',
                $sessionKey
            )
        );
    }

    private function monetizationIpKey(
        Request $request,
        string $endpoint
    ): string {
        $ipAddress =
            $request->ip()
            ?? 'unknown';

        return sprintf(
            'monetization:%s:ip:%s',
            $endpoint,
            hash(
                'sha256',
                $ipAddress
            )
        );
    }
}
