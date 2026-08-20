import test from 'node:test';
import assert from 'node:assert/strict';

class FakeHTMLElement {
    constructor(dataset = {}) {
        this.dataset = {
            ...dataset,
        };
    }
}

class FakeCustomEvent {
    constructor(
        type,
        options = {}
    ) {
        this.type = type;
        this.detail =
            options.detail ?? null;
    }
}

globalThis.HTMLElement =
    FakeHTMLElement;

globalThis.CustomEvent =
    FakeCustomEvent;

globalThis.window = {
    XurvexaMonetization: null,
    XurvexaVideoAdAdapter: null,

    dispatchedEvents: [],

    dispatchEvent(event) {
        this.dispatchedEvents.push(
            event
        );

        return true;
    },

    addEventListener() {
        //
    },
};

globalThis.document = {
    readyState: 'loading',

    addEventListener() {
        /*
         * Prevent automatic adapter boot
         * during the Node test import.
         */
    },

    querySelectorAll() {
        return [];
    },
};

const {
    XurvexaVideoAdAdapter,
} = await import(
    '../../resources/js/video-adapter.js'
);

function createClient(
    overrides = {}
) {
    const {
        decision:
            decisionOverrides = {},
        ...clientOverrides
    } = overrides;

    const calls = {
        prefetchDecision: [],
        consumePendingDecision: [],
        discardPendingDecision: [],
        recordImpression: [],
        recordClick: [],
        recordError: [],
    };

    const decision = {
        show: true,
        format: 'banner',
        reason: 'eligible',
        opportunity_uuid:
            '11111111-1111-4111-8111-111111111111',

        delivery: {
            ad_network:
                'exoclick',

            ad_placement_id:
                101,

            ad_driver:
                'exoclick',

            public_placement_id:
                'zone-101',

            public_config: {
                width: 300,
                height: 250,
            },
        },

        ...decisionOverrides,
    };

    const client = {
        calls,

        async prefetchDecision(
            format,
            context
        ) {
            calls.prefetchDecision.push({
                format,
                context,
            });

            return decision;
        },

        consumePendingDecision(
            format,
            context
        ) {
            calls.consumePendingDecision.push({
                format,
                context,
            });

            return decision;
        },

        discardPendingDecision(
            format,
            context
        ) {
            calls.discardPendingDecision.push({
                format,
                context,
            });

            return true;
        },

        async recordImpression(
            suppliedDecision,
            context
        ) {
            calls.recordImpression.push({
                decision:
                    suppliedDecision,
                context,
            });

            return {
                ok: true,
            };
        },

        async recordClick(
            suppliedDecision,
            context
        ) {
            calls.recordClick.push({
                decision:
                    suppliedDecision,
                context,
            });

            return {
                ok: true,
            };
        },

        async recordError(
            suppliedDecision,
            errorReason,
            context
        ) {
            calls.recordError.push({
                decision:
                    suppliedDecision,
                errorReason,
                context,
            });

            return {
                ok: true,
            };
        },

        ...clientOverrides,
    };

    return {
        client,
        calls,
        decision,
    };
}

function createSlot(
    overrides = {}
) {
    return new FakeHTMLElement({
        enabled: 'false',
        format: 'banner',
        placementKey:
            'video_banner',
        adState: 'idle',
        ...overrides,
    });
}

test(
    'disabled banner slot does not request a monetization decision',
    async () => {
        const {
            client,
            calls,
        } = createClient();

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'false',
                adRenderer:
                    'stale-driver',
            });

        const result =
            await adapter.prepareSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            slot.dataset.adState,
            'disabled'
        );

        assert.equal(
            slot.dataset.adRenderer,
            undefined
        );

        assert.equal(
            calls.prefetchDecision.length,
            0
        );

        assert.equal(
            calls.recordImpression.length,
            0
        );
    }
);

test(
    'enabled banner slot becomes ready with trusted backend-selected driver without recording impression',
    async () => {
        const {
            client,
            calls,
            decision,
        } = createClient();

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        const result =
            await adapter.prepareSlot(
                slot
            );

        assert.equal(
            result,
            decision
        );

        assert.equal(
            slot.dataset.adState,
            'ready'
        );

        assert.equal(
            slot.dataset.adRenderer,
            'exoclick'
        );

        assert.equal(
            calls.prefetchDecision.length,
            1
        );

        assert.deepEqual(
            calls.prefetchDecision[0],
            {
                format: 'banner',
                context: {
                    placementKey:
                        'video_banner',
                },
            }
        );

        assert.equal(
            calls.recordImpression.length,
            0
        );

        assert.equal(
            adapter.pendingDecision(
                slot
            ),
            decision
        );

        assert.deepEqual(
            adapter.pendingDelivery(
                slot
            ),
            {
                adNetwork:
                    'exoclick',

                adPlacementId:
                    101,

                adDriver:
                    'exoclick',

                publicPlacementId:
                    'zone-101',

                publicConfig: {
                    width: 300,
                    height: 250,
                },
            }
        );
    }
);

test(
    'backend-selected TrafficStars driver replaces stale markup renderer selection',
    async () => {
        const {
            client,
        } = createClient({
            decision: {
                delivery: {
                    ad_network:
                        'trafficstars',

                    ad_placement_id:
                        202,

                    ad_driver:
                        'trafficstars',

                    public_placement_id:
                        'spot-202',

                    public_config: {
                        width: 300,
                        height: 250,
                    },
                },
            },
        });

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
                adRenderer:
                    'exoclick',
            });

        await adapter.prepareSlot(
            slot
        );

        assert.equal(
            slot.dataset.adRenderer,
            'trafficstars'
        );

        assert.equal(
            adapter.pendingDelivery(
                slot
            )?.adNetwork,
            'trafficstars'
        );

        assert.equal(
            adapter.pendingDelivery(
                slot
            )?.adPlacementId,
            202
        );
    }
);

test(
    'show decision without trusted delivery fails closed and discards local pending decision',
    async () => {
        const {
            client,
            calls,
        } = createClient({
            decision: {
                delivery: null,
            },
        });

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await assert.rejects(
            adapter.prepareSlot(
                slot
            ),
            {
                message:
                    'Trusted monetization delivery is missing.',
            }
        );

        assert.equal(
            slot.dataset.adState,
            'error'
        );

        assert.equal(
            slot.dataset.adRenderer,
            undefined
        );

        assert.equal(
            calls.discardPendingDecision.length,
            1
        );

        assert.deepEqual(
            calls.discardPendingDecision[0],
            {
                format: 'banner',
                context: {
                    placementKey:
                        'video_banner',
                },
            }
        );

        assert.equal(
            adapter.pendingDecision(
                slot
            ),
            null
        );

        assert.equal(
            calls.recordImpression.length,
            0
        );
    }
);

test(
    'show decision with invalid trusted driver fails closed',
    async () => {
        const {
            client,
            calls,
        } = createClient({
            decision: {
                delivery: {
                    ad_network:
                        'exoclick',

                    ad_placement_id:
                        101,

                    ad_driver:
                        'javascript:alert(1)',

                    public_placement_id:
                        'zone-101',

                    public_config: null,
                },
            },
        });

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await assert.rejects(
            adapter.prepareSlot(
                slot
            ),
            {
                message:
                    'Trusted monetization ad driver is invalid.',
            }
        );

        assert.equal(
            slot.dataset.adState,
            'error'
        );

        assert.equal(
            slot.dataset.adRenderer,
            undefined
        );

        assert.equal(
            calls.discardPendingDecision.length,
            1
        );

        assert.equal(
            calls.recordImpression.length,
            0
        );
    }
);

test(
    'show decision with invalid trusted placement id fails closed',
    async () => {
        const {
            client,
            calls,
        } = createClient({
            decision: {
                delivery: {
                    ad_network:
                        'exoclick',

                    ad_placement_id:
                        0,

                    ad_driver:
                        'exoclick',

                    public_placement_id:
                        'zone-101',

                    public_config: null,
                },
            },
        });

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await assert.rejects(
            adapter.prepareSlot(
                slot
            ),
            {
                message:
                    'Trusted monetization ad placement ID is invalid.',
            }
        );

        assert.equal(
            slot.dataset.adState,
            'error'
        );

        assert.equal(
            calls.discardPendingDecision.length,
            1
        );

        assert.equal(
            calls.recordImpression.length,
            0
        );
    }
);

test(
    'confirm rendered consumes pending decision and records impression',
    async () => {
        const {
            client,
            calls,
            decision,
        } = createClient();

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await adapter.prepareSlot(
            slot
        );

        const result =
            await adapter.confirmRendered(
                slot
            );

        assert.equal(
            result,
            decision
        );

        assert.equal(
            calls.consumePendingDecision.length,
            1
        );

        assert.equal(
            calls.recordImpression.length,
            1
        );

        assert.equal(
            calls.recordImpression[0]
                .decision,
            decision
        );

        assert.deepEqual(
            calls.recordImpression[0]
                .context,
            {
                placementKey:
                    'video_banner',
            }
        );

        assert.equal(
            slot.dataset.adState,
            'rendered'
        );

        assert.equal(
            slot.dataset.adRenderer,
            'exoclick'
        );

        assert.equal(
            adapter.pendingDecision(
                slot
            ),
            null
        );

        assert.equal(
            adapter.activeDecision(
                slot
            ),
            decision
        );

        assert.equal(
            adapter.activeDelivery(
                slot
            )?.adDriver,
            'exoclick'
        );
    }
);

test(
    'click is rejected before impression',
    async () => {
        const {
            client,
            calls,
        } = createClient();

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await adapter.prepareSlot(
            slot
        );

        await assert.rejects(
            adapter.recordClick(
                slot
            ),
            {
                message:
                    'An ad impression is required before recording a click.',
            }
        );

        assert.equal(
            calls.recordClick.length,
            0
        );
    }
);

test(
    'click is recorded after impression',
    async () => {
        const {
            client,
            calls,
            decision,
        } = createClient();

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await adapter.prepareSlot(
            slot
        );

        await adapter.confirmRendered(
            slot
        );

        await adapter.recordClick(
            slot
        );

        assert.equal(
            calls.recordClick.length,
            1
        );

        assert.equal(
            calls.recordClick[0]
                .decision,
            decision
        );

        assert.deepEqual(
            calls.recordClick[0]
                .context,
            {
                placementKey:
                    'video_banner',
            }
        );
    }
);

test(
    'expired pending decision does not record impression and clears renderer selection',
    async () => {
        const {
            client,
            calls,
        } = createClient({
            consumePendingDecision() {
                calls.consumePendingDecision.push({
                    format: 'banner',
                    context: {
                        placementKey:
                            'video_banner',
                    },
                });

                return null;
            },
        });

        const adapter =
            new XurvexaVideoAdAdapter(
                client
            );

        const slot =
            createSlot({
                enabled: 'true',
            });

        await adapter.prepareSlot(
            slot
        );

        await assert.rejects(
            adapter.confirmRendered(
                slot
            ),
            {
                message:
                    'The pending monetization decision has expired.',
            }
        );

        assert.equal(
            slot.dataset.adState,
            'expired'
        );

        assert.equal(
            slot.dataset.adRenderer,
            undefined
        );

        assert.equal(
            calls.recordImpression.length,
            0
        );

        assert.equal(
            adapter.pendingDecision(
                slot
            ),
            null
        );
    }
);
