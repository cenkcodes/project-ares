<?php

namespace App\Providers;

use App\Http\Middleware\TrackTrafficAnalytics;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TrafficAnalyticsServiceProvider extends ServiceProvider
{
    public function boot(
        Router $router
    ): void {
        /*
         * Append after the standard web middleware so
         * cookies queued by the analytics identity are
         * added to the final response normally.
         */
        $router->pushMiddlewareToGroup(
            'web',
            TrackTrafficAnalytics::class
        );
    }
}
