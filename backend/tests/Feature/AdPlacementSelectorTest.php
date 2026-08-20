<?php

use App\Models\AdNetwork;
use App\Models\AdPlacement;
use App\Services\Monetization\AdPlacementSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSelectorNetwork(
    array $overrides = []
): AdNetwork {
    return AdNetwork::create(
        array_merge(
            [
                'name' =>
                    'Selector Test Network',

                'slug' =>
                    'selector-test-network-' .
                    uniqid(),

                'driver' =>
                    'selector-test-driver',

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

function createSelectorPlacement(
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
                    'selector-test-zone',

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

function makeAdPlacementSelector(): AdPlacementSelector
{
    return app(
        AdPlacementSelector::class
    );
}

test(
    'selects eligible placement',
    function () {
        $network =
            createSelectorNetwork();

        $placement =
            createSelectorPlacement(
                $network
            );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->not->toBeNull()
            ->and($selected->id)
            ->toBe($placement->id)
            ->and($selected->network)
            ->not->toBeNull()
            ->and($selected->network->id)
            ->toBe($network->id);
    }
);

test(
    'inactive placement is not selected',
    function () {
        $network =
            createSelectorNetwork();

        createSelectorPlacement(
            $network,
            [
                'is_active' =>
                    false,
            ]
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'inactive network is not selected',
    function () {
        $network =
            createSelectorNetwork([
                'is_active' =>
                    false,
            ]);

        createSelectorPlacement(
            $network
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'network without format support is not selected',
    function () {
        $network =
            createSelectorNetwork([
                'supports_banner' =>
                    false,
            ]);

        createSelectorPlacement(
            $network
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'desktop disabled placement is not selected for desktop',
    function () {
        $network =
            createSelectorNetwork();

        createSelectorPlacement(
            $network,
            [
                'desktop_enabled' =>
                    false,
                'mobile_enabled' =>
                    true,
            ]
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'mobile disabled placement is not selected for mobile',
    function () {
        $network =
            createSelectorNetwork();

        createSelectorPlacement(
            $network,
            [
                'desktop_enabled' =>
                    true,
                'mobile_enabled' =>
                    false,
            ]
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        true
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'lower placement priority wins before network priority',
    function () {
        $firstNetwork =
            createSelectorNetwork([
                'name' =>
                    'Selector Network One',
                'priority' =>
                    1,
            ]);

        $secondNetwork =
            createSelectorNetwork([
                'name' =>
                    'Selector Network Two',
                'priority' =>
                    500,
            ]);

        $firstPlacement =
            createSelectorPlacement(
                $firstNetwork,
                [
                    'priority' =>
                        200,
                ]
            );

        $secondPlacement =
            createSelectorPlacement(
                $secondNetwork,
                [
                    'priority' =>
                        10,
                ]
            );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->not->toBeNull()
            ->and($selected->id)
            ->toBe($secondPlacement->id)
            ->and($selected->id)
            ->not->toBe($firstPlacement->id);
    }
);

test(
    'network priority breaks placement priority tie',
    function () {
        $firstNetwork =
            createSelectorNetwork([
                'name' =>
                    'Selector Priority Network One',
                'priority' =>
                    50,
            ]);

        $secondNetwork =
            createSelectorNetwork([
                'name' =>
                    'Selector Priority Network Two',
                'priority' =>
                    10,
            ]);

        $firstPlacement =
            createSelectorPlacement(
                $firstNetwork,
                [
                    'priority' =>
                        100,
                ]
            );

        $secondPlacement =
            createSelectorPlacement(
                $secondNetwork,
                [
                    'priority' =>
                        100,
                ]
            );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->not->toBeNull()
            ->and($selected->id)
            ->toBe($secondPlacement->id)
            ->and($selected->id)
            ->not->toBe($firstPlacement->id);
    }
);

test(
    'placement id breaks complete priority tie',
    function () {
        $firstNetwork =
            createSelectorNetwork([
                'name' =>
                    'Selector Tie Network One',
                'priority' =>
                    100,
            ]);

        $secondNetwork =
            createSelectorNetwork([
                'name' =>
                    'Selector Tie Network Two',
                'priority' =>
                    100,
            ]);

        $firstPlacement =
            createSelectorPlacement(
                $firstNetwork,
                [
                    'priority' =>
                        100,
                ]
            );

        $secondPlacement =
            createSelectorPlacement(
                $secondNetwork,
                [
                    'priority' =>
                        100,
                ]
            );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->not->toBeNull()
            ->and($selected->id)
            ->toBe($firstPlacement->id)
            ->and($selected->id)
            ->not->toBe($secondPlacement->id);
    }
);

test(
    'wrong placement key returns no selection',
    function () {
        $network =
            createSelectorNetwork();

        createSelectorPlacement(
            $network
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        'other-placement',
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'wrong placement format returns no selection',
    function () {
        $network =
            createSelectorNetwork();

        createSelectorPlacement(
            $network
        );

        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        AdPlacement::PLACEMENT_VIDEO_BANNER,
                    format:
                        AdNetwork::FORMAT_PREROLL,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'blank placement key returns no selection',
    function () {
        $selected =
            makeAdPlacementSelector()
                ->select(
                    placementKey:
                        '   ',
                    format:
                        AdNetwork::FORMAT_BANNER,
                    isMobile:
                        false
                );

        expect($selected)
            ->toBeNull();
    }
);

test(
    'unknown format fails closed',
    function () {
        expect(
            fn () =>
                makeAdPlacementSelector()
                    ->select(
                        placementKey:
                            AdPlacement::PLACEMENT_VIDEO_BANNER,
                        format:
                            'unknown-format',
                        isMobile:
                            false
                    )
        )->toThrow(
            InvalidArgumentException::class,
            'Unsupported ad placement format.'
        );
    }
);
