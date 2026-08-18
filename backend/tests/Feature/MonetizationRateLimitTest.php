<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Cache::flush();

    app('session')->flush();
});

test(
    'monetization runtime routes use their dedicated rate limiters',
    function () {
        $expectedMiddleware = [
            'monetization.interaction' =>
                'throttle:monetization-interaction',

            'monetization.decision' =>
                'throttle:monetization-decision',

            'monetization.event' =>
                'throttle:monetization-event',
        ];

        foreach (
            $expectedMiddleware
            as $routeName => $middleware
        ) {
            $route = Route::getRoutes()
                ->getByName(
                    $routeName
                );

            expect($route)
                ->not->toBeNull()
                ->and(
                    $route->gatherMiddleware()
                )
                ->toContain('web')
                ->toContain(
                    $middleware
                );
        }
    }
);

test(
    'interaction endpoint is limited to twelve requests per minute per session',
    function () {
        $url = route(
            'monetization.interaction'
        );

        for (
            $requestNumber = 1;
            $requestNumber <= 12;
            $requestNumber++
        ) {
            $response =
                $this->postJson(
                    $url
                );

            $response
                ->assertOk()
                ->assertJsonPath(
                    'ok',
                    true
                )
                ->assertJsonPath(
                    'meaningful_interaction_count',
                    $requestNumber
                );
        }

        $limitedResponse =
            $this->postJson(
                $url
            );

        $limitedResponse
            ->assertStatus(429)
            ->assertSessionHas(
                'monetization.meaningful_interactions',
                12
            );
    }
);

test(
    'decision endpoint is limited to thirty requests per minute per session',
    function () {
        $url = route(
            'monetization.decision'
        );

        for (
            $requestNumber = 1;
            $requestNumber <= 30;
            $requestNumber++
        ) {
            /*
             * Invalid payload is intentional.
             *
             * Before the limit is exhausted,
             * the request must reach controller
             * validation and return 422.
             */
            $response =
                $this->postJson(
                    $url,
                    []
                );

            $response
                ->assertUnprocessable();
        }

        $limitedResponse =
            $this->postJson(
                $url,
                []
            );

        $limitedResponse
            ->assertStatus(429);
    }
);

test(
    'event endpoint is limited to sixty requests per minute per session',
    function () {
        $url = route(
            'monetization.event'
        );

        for (
            $requestNumber = 1;
            $requestNumber <= 60;
            $requestNumber++
        ) {
            /*
             * Invalid payload is intentional.
             *
             * A 422 response proves that the
             * request reached controller
             * validation before the rate limit
             * was exhausted.
             */
            $response =
                $this->postJson(
                    $url,
                    []
                );

            $response
                ->assertUnprocessable();
        }

        $limitedResponse =
            $this->postJson(
                $url,
                []
            );

        $limitedResponse
            ->assertStatus(429);
    }
);
