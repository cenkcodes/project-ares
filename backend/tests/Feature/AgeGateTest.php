<?php

use App\Http\Middleware\RequireAdultConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test(
    'first adult content visit redirects to age gate',
    function () {
        $response =
            $this->get(
                route('home')
            );

        $response
            ->assertRedirect(
                route('age-gate.show')
            );
    }
);

test(
    'age gate is accessible without login or adult consent',
    function () {
        $response =
            $this->get(
                route('age-gate.show')
            );

        $response
            ->assertOk()
            ->assertSee(
                'Age Verification'
            )
            ->assertSee(
                'I am 18 or older',
                false
            )
            ->assertSee(
                'I am under 18',
                false
            );
    }
);

test(
    'accepting age gate creates adult consent cookie',
    function () {
        $response =
            $this->post(
                route('age-gate.accept')
            );

        $response
            ->assertRedirect('/')
            ->assertCookie(
                RequireAdultConsent::COOKIE_NAME,
                RequireAdultConsent::COOKIE_VALUE
            );
    }
);

test(
    'denying age gate redirects to restricted page',
    function () {
        $response =
            $this->post(
                route('age-gate.deny')
            );

        $response
            ->assertRedirect(
                route('age-gate.denied')
            );

        $this->get(
            route('age-gate.denied')
        )
            ->assertOk()
            ->assertSee(
                'Access Restricted'
            );
    }
);

test(
    'denying age gate removes existing adult consent cookie',
    function () {
        $response =
            $this
                ->withCookie(
                    RequireAdultConsent::COOKIE_NAME,
                    RequireAdultConsent::COOKIE_VALUE
                )
                ->post(
                    route('age-gate.deny')
                );

        $response
            ->assertRedirect(
                route('age-gate.denied')
            )
            ->assertCookieExpired(
                RequireAdultConsent::COOKIE_NAME
            );
    }
);

test(
    'legal information pages remain accessible without adult consent',
    function () {
        $routes = [
            'pages.about',
            'pages.contact',
            'pages.privacy',
            'pages.terms',
            'pages.content-removal',
        ];

        foreach ($routes as $routeName) {
            $this->get(
                route($routeName)
            )->assertOk();
        }
    }
);

test(
    'monetization runtime fails closed without adult consent',
    function () {
        $response =
            $this->postJson(
                route(
                    'monetization.decision'
                ),
                [
                    'format' =>
                        'banner',

                    'placement_key' =>
                        'video_banner',
                ]
            );

        $response
            ->assertForbidden()
            ->assertJson([
                'message' =>
                    'Adult consent is required.',
            ]);
    }
);

test(
    'video routes are protected before controller content is rendered',
    function () {
        $response =
            $this->get(
                '/videos/example-video'
            );

        $response
            ->assertRedirect(
                route('age-gate.show')
            )
            ->assertDontSee(
                '<iframe',
                false
            );
    }
);

test(
    'accepting age gate returns visitor to originally requested local url',
    function () {
        $this->get(
            '/videos?search=test'
        )
            ->assertRedirect(
                route('age-gate.show')
            );

        $this->post(
            route('age-gate.accept')
        )
            ->assertRedirect(
                '/videos?search=test'
            );
    }
);

test(
    'adult consent cookie allows access without showing gate again',
    function () {
        $response =
            $this
                ->withCookie(
                    RequireAdultConsent::COOKIE_NAME,
                    RequireAdultConsent::COOKIE_VALUE
                )
                ->get(
                    route('videos.index')
                );

        $response
            ->assertOk()
            ->assertDontSee(
                'Age Verification'
            );
    }
);

test(
    'age gate rejects unsafe external redirect destinations',
    function () {
        $response =
            $this
                ->withSession([
                    RequireAdultConsent::INTENDED_SESSION_KEY =>
                        '//example.com/unsafe',
                ])
                ->post(
                    route('age-gate.accept')
                );

        $response
            ->assertRedirect('/');
    }
);
