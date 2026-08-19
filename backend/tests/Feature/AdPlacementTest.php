<?php

use App\Models\AdNetwork;
use App\Models\AdPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function createPlacementTestNetwork(
    array $overrides = []
): AdNetwork {
    return AdNetwork::create(
        array_merge(
            [
                'name' =>
                    'Placement Test Network',

                'slug' =>
                    'placement-test-network',

                'driver' =>
                    'placement-test',

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

function createTestAdPlacement(
    AdNetwork $network,
    array $overrides = []
): AdPlacement {
    return AdPlacement::create(
        array_merge(
            [
                'ad_network_id' =>
                    $network->id,

                'placement_key' =>
                    AdPlacement::PLACEMENT_VIDEO_BANNER,

                'format' =>
                    AdNetwork::FORMAT_BANNER,

                'is_active' =>
                    true,

                'priority' =>
                    100,

                'desktop_enabled' =>
                    true,

                'mobile_enabled' =>
                    true,

                'public_placement_id' =>
                    'test-zone-123',

                'public_config' =>
                    [
                        'width' => 728,
                        'height' => 90,
                    ],
            ],
            $overrides
        )
    );
}

test(
    'ad placements table has required schema',
    function () {
        expect(
            Schema::hasTable(
                'ad_placements'
            )
        )->toBeTrue();

        expect(
            Schema::hasColumns(
                'ad_placements',
                [
                    'id',
                    'ad_network_id',
                    'placement_key',
                    'format',
                    'is_active',
                    'priority',
                    'desktop_enabled',
                    'mobile_enabled',
                    'public_placement_id',
                    'public_config',
                    'notes',
                    'created_at',
                    'updated_at',
                ]
            )
        )->toBeTrue();
    }
);

test(
    'placement belongs to its advertising network',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network
            );

        expect(
            $placement->network
        )->toBeInstanceOf(
            AdNetwork::class
        );

        expect(
            $placement->network->id
        )->toBe(
            $network->id
        );
    }
);

test(
    'placement fields use expected casts',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network
            );

        expect(
            $placement->ad_network_id
        )->toBeInt()
            ->and(
                $placement->priority
            )
            ->toBeInt()
            ->toBe(100)
            ->and(
                $placement->is_active
            )
            ->toBeTrue()
            ->and(
                $placement->desktop_enabled
            )
            ->toBeTrue()
            ->and(
                $placement->mobile_enabled
            )
            ->toBeTrue()
            ->and(
                $placement->public_config
            )
            ->toBeArray()
            ->toBe([
                'width' => 728,
                'height' => 90,
            ]);
    }
);

test(
    'active placement can serve supported banner format',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network
            );

        expect(
            $placement->canServe(
                false
            )
        )->toBeTrue();

        expect(
            $placement->canServe(
                true
            )
        )->toBeTrue();
    }
);

test(
    'inactive placement fails closed',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network,
                [
                    'is_active' =>
                        false,
                ]
            );

        expect(
            $placement->canServe(
                false
            )
        )->toBeFalse();

        expect(
            $placement->canServe(
                true
            )
        )->toBeFalse();
    }
);

test(
    'placement respects device controls',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network,
                [
                    'desktop_enabled' =>
                        true,

                    'mobile_enabled' =>
                        false,
                ]
            );

        expect(
            $placement->canServe(
                false
            )
        )->toBeTrue();

        expect(
            $placement->canServe(
                true
            )
        )->toBeFalse();
    }
);

test(
    'placement fails closed when network is inactive',
    function () {
        $network =
            createPlacementTestNetwork([
                'is_active' =>
                    false,
            ]);

        $placement =
            createTestAdPlacement(
                $network
            );

        expect(
            $placement->hasAvailableNetwork()
        )->toBeFalse();

        expect(
            $placement->canServe(
                false
            )
        )->toBeFalse();
    }
);

test(
    'placement fails closed when network does not support format',
    function () {
        $network =
            createPlacementTestNetwork([
                'supports_banner' =>
                    false,
            ]);

        $placement =
            createTestAdPlacement(
                $network
            );

        expect(
            $placement->networkSupportsFormat()
        )->toBeFalse();

        expect(
            $placement->canServe(
                false
            )
        )->toBeFalse();
    }
);

test(
    'unknown placement format fails closed',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network,
                [
                    'format' =>
                        'unknown-format',
                ]
            );

        expect(
            $placement->networkSupportsFormat()
        )->toBeFalse();

        expect(
            $placement->canServe(
                false
            )
        )->toBeFalse();
    }
);

test(
    'public runtime configuration contains browser safe placement data',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network
            );

        expect(
            $placement->publicRuntimeConfiguration()
        )->toBe([
            'placement_key' =>
                AdPlacement::PLACEMENT_VIDEO_BANNER,

            'format' =>
                AdNetwork::FORMAT_BANNER,

            'public_placement_id' =>
                'test-zone-123',

            'public_config' =>
                [
                    'width' => 728,
                    'height' => 90,
                ],
        ]);
    }
);

test(
    'same network cannot define duplicate placement key',
    function () {
        $network =
            createPlacementTestNetwork();

        createTestAdPlacement(
            $network
        );

        expect(
            fn () =>
                createTestAdPlacement(
                    $network
                )
        )->toThrow(
            \Illuminate\Database\QueryException::class
        );
    }
);

test(
    'different networks may use same placement key',
    function () {
        $firstNetwork =
            createPlacementTestNetwork([
                'slug' =>
                    'placement-network-one',
            ]);

        $secondNetwork =
            createPlacementTestNetwork([
                'name' =>
                    'Placement Test Network Two',

                'slug' =>
                    'placement-network-two',
            ]);

        $firstPlacement =
            createTestAdPlacement(
                $firstNetwork
            );

        $secondPlacement =
            createTestAdPlacement(
                $secondNetwork
            );

        expect(
            $firstPlacement->placement_key
        )->toBe(
            $secondPlacement->placement_key
        );

        expect(
            $firstPlacement->ad_network_id
        )->not->toBe(
            $secondPlacement->ad_network_id
        );
    }
);

test(
    'deleting network cascades to its placements',
    function () {
        $network =
            createPlacementTestNetwork();

        $placement =
            createTestAdPlacement(
                $network
            );

        $placementId =
            $placement->id;

        $network->delete();

        expect(
            AdPlacement::query()
                ->find(
                    $placementId
                )
        )->toBeNull();
    }
);

test(
    'placement exposes known placement inventory',
    function () {
        expect(
            AdPlacement::placementKeys()
        )->toBe([
            AdPlacement::PLACEMENT_VIDEO_BANNER,
            AdPlacement::PLACEMENT_VIDEO_PREROLL,
            AdPlacement::PLACEMENT_VIDEO_POPUNDER,
            AdPlacement::PLACEMENT_HOME_NATIVE,
        ]);
    }
);
