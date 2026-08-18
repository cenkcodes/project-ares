<?php

use App\Services\Monetization\AnonymousVisitorIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

beforeEach(function () {
    Cookie::flushQueuedCookies();
});

function makeAnonymousVisitorIdentityForTest(): AnonymousVisitorIdentity
{
    return new AnonymousVisitorIdentity();
}

test(
    'existing valid visitor uuid is reused',
    function () {
        $service =
            makeAnonymousVisitorIdentityForTest();

        $visitorKey =
            (string) Str::uuid();

        $request = Request::create(
            '/',
            'GET',
            [],
            [
                AnonymousVisitorIdentity::COOKIE_NAME =>
                    $visitorKey,
            ]
        );

        $resolved = $service->resolve(
            $request
        );

        expect($resolved)
            ->toBe($visitorKey)
            ->and(
                Cookie::getQueuedCookies()
            )
            ->toHaveCount(0);
    }
);

test(
    'missing visitor cookie generates and queues a uuid',
    function () {
        $service =
            makeAnonymousVisitorIdentityForTest();

        $request = Request::create(
            '/',
            'GET'
        );

        $resolved = $service->resolve(
            $request
        );

        expect(Str::isUuid($resolved))
            ->toBeTrue();

        $cookies =
            Cookie::getQueuedCookies();

        expect($cookies)
            ->toHaveCount(1);

        $cookie = $cookies[0];

        expect($cookie->getName())
            ->toBe(
                AnonymousVisitorIdentity::COOKIE_NAME
            )
            ->and($cookie->getValue())
            ->toBe($resolved);
    }
);

test(
    'invalid visitor cookie is replaced with a new uuid',
    function () {
        $service =
            makeAnonymousVisitorIdentityForTest();

        $request = Request::create(
            '/',
            'GET',
            [],
            [
                AnonymousVisitorIdentity::COOKIE_NAME =>
                    'invalid-value',
            ]
        );

        $resolved = $service->resolve(
            $request
        );

        expect(Str::isUuid($resolved))
            ->toBeTrue()
            ->and($resolved)
            ->not->toBe('invalid-value');

        $cookies =
            Cookie::getQueuedCookies();

        expect($cookies)
            ->toHaveCount(1)
            ->and($cookies[0]->getValue())
            ->toBe($resolved);
    }
);

test(
    'visitor cookie uses expected privacy settings',
    function () {
        $service =
            makeAnonymousVisitorIdentityForTest();

        $request = Request::create(
            '/',
            'GET'
        );

        $service->resolve(
            $request
        );

        $cookies =
            Cookie::getQueuedCookies();

        expect($cookies)
            ->toHaveCount(1);

        $cookie = $cookies[0];

        expect($cookie->isHttpOnly())
            ->toBeTrue()
            ->and($cookie->getSameSite())
            ->toBe('lax')
            ->and($cookie->getPath())
            ->toBe('/');
    }
);

test(
    'queue rotation creates a different visitor uuid',
    function () {
        $service =
            makeAnonymousVisitorIdentityForTest();

        $first =
            $service->queueRotation();

        $second =
            $service->queueRotation();

        expect(Str::isUuid($first))
            ->toBeTrue()
            ->and(Str::isUuid($second))
            ->toBeTrue()
            ->and($second)
            ->not->toBe($first);

        $cookies =
            Cookie::getQueuedCookies();

        expect($cookies)
            ->toHaveCount(1)
            ->and($cookies[0]->getValue())
            ->toBe($second);
    }
);

test(
    'queue forget schedules visitor cookie deletion',
    function () {
        $service =
            makeAnonymousVisitorIdentityForTest();

        $service->queueForget();

        $cookies =
            Cookie::getQueuedCookies();

        expect($cookies)
            ->toHaveCount(1);

        $cookie = $cookies[0];

        expect($cookie->getName())
            ->toBe(
                AnonymousVisitorIdentity::COOKIE_NAME
            )
            ->and($cookie->getExpiresTime())
            ->toBeLessThan(
                time()
            );
    }
);
