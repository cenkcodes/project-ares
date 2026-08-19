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

        ...overrides,
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
    'enabled banner slot becomes ready without recording impression',
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
    'expired pending decision does not record impression',
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
