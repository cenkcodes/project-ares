<?php

use App\Models\AdNetwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function createTestAdNetwork(
    array $overrides = []
): AdNetwork {
    return AdNetwork::create(
        array_merge(
            [
                'name' =>
                    'Test Ad Network',

                'slug' =>
                    'test-ad-network',

                'driver' =>
                    'test-network',

                'is_active' =>
                    true,

                'priority' =>
                    100,

                'supports_native' =>
                    false,

                'supports_banner' =>
                    true,

                'supports_preroll' =>
                    false,

                'supports_midroll' =>
                    false,

                'supports_popunder' =>
                    false,

                'supports_interstitial' =>
                    false,
            ],
            $overrides
        )
    );
}

test(
    'ad networks table has required schema',
    function () {
        expect(
            Schema::hasTable(
                'ad_networks'
            )
        )->toBeTrue();

        expect(
            Schema::hasColumns(
                'ad_networks',
                [
                    'id',
                    'name',
                    'slug',
                    'driver',
                    'is_active',
                    'priority',
                    'supports_native',
                    'supports_banner',
                    'supports_preroll',
                    'supports_midroll',
                    'supports_popunder',
                    'supports_interstitial',
                    'notes',
                    'created_at',
                    'updated_at',
                ]
            )
        )->toBeTrue();
    }
);

test(
    'ad network boolean and priority fields use expected casts',
    function () {
        $network =
            createTestAdNetwork();

        expect(
            $network->is_active
        )->toBeTrue()
            ->and(
                $network->priority
            )
            ->toBeInt()
            ->toBe(100)
            ->and(
                $network->supports_banner
            )
            ->toBeTrue()
            ->and(
                $network->supports_native
            )
            ->toBeFalse();
    }
);

test(
    'active network can serve supported format',
    function () {
        $network =
            createTestAdNetwork();

        expect(
            $network->supportsFormat(
                AdNetwork::FORMAT_BANNER
            )
        )->toBeTrue();

        expect(
            $network->canServeFormat(
                AdNetwork::FORMAT_BANNER
            )
        )->toBeTrue();
    }
);

test(
    'inactive network cannot serve supported format',
    function () {
        $network =
            createTestAdNetwork([
                'is_active' =>
                    false,
            ]);

        expect(
            $network->supportsFormat(
                AdNetwork::FORMAT_BANNER
            )
        )->toBeTrue();

        expect(
            $network->canServeFormat(
                AdNetwork::FORMAT_BANNER
            )
        )->toBeFalse();
    }
);

test(
    'active network cannot serve unsupported format',
    function () {
        $network =
            createTestAdNetwork();

        expect(
            $network->supportsFormat(
                AdNetwork::FORMAT_PREROLL
            )
        )->toBeFalse();

        expect(
            $network->canServeFormat(
                AdNetwork::FORMAT_PREROLL
            )
        )->toBeFalse();
    }
);

test(
    'unknown ad format fails closed',
    function () {
        $network =
            createTestAdNetwork();

        expect(
            $network->supportsFormat(
                'unknown-format'
            )
        )->toBeFalse();

        expect(
            $network->canServeFormat(
                'unknown-format'
            )
        )->toBeFalse();
    }
);

test(
    'ad network exposes supported format inventory',
    function () {
        expect(
            AdNetwork::formats()
        )->toBe([
            AdNetwork::FORMAT_NATIVE,
            AdNetwork::FORMAT_BANNER,
            AdNetwork::FORMAT_PREROLL,
            AdNetwork::FORMAT_MIDROLL,
            AdNetwork::FORMAT_POPUNDER,
            AdNetwork::FORMAT_INTERSTITIAL,
        ]);
    }
);

test(
    'ad network slug must be unique',
    function () {
        createTestAdNetwork([
            'slug' =>
                'unique-network',
        ]);

        expect(
            fn () =>
                createTestAdNetwork([
                    'slug' =>
                        'unique-network',
                ])
        )->toThrow(
            \Illuminate\Database\QueryException::class
        );
    }
);
