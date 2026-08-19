import test from 'node:test';
import assert from 'node:assert/strict';

class FakeHTMLElement {
    constructor(dataset = {}) {
        this.dataset = {
            ...dataset,
        };

        this.attributes = {};
        this.children = [];
        this.innerHTML = '';
    }

    setAttribute(
        name,
        value
    ) {
        this.attributes[name] =
            String(value);
    }

    replaceChildren(
        ...children
    ) {
        this.children = [
            ...children,
        ];

        this.innerHTML = '';
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
    XurvexaVideoAdAdapter: null,
    XurvexaBannerRenderer: null,

    dispatchedEvents: [],

    listeners:
        new Map(),

    dispatchEvent(event) {
        this.dispatchedEvents.push(
            event
        );

        const listeners =
            this.listeners.get(
                event.type
            )
            ?? [];

        for (const listener of listeners) {
            listener(
                event
            );
        }

        return true;
    },

    addEventListener(
        type,
        listener
    ) {
        const listeners =
            this.listeners.get(
                type
            )
            ?? [];

        listeners.push(
            listener
        );

        this.listeners.set(
            type,
            listeners
        );
    },
};

globalThis.document = {
    readyState: 'loading',

    addEventListener() {
        /*
         * Prevent automatic boot
         * during module import.
         */
    },

    querySelectorAll() {
        return [];
    },
};

const {
    XurvexaBannerRenderer,
} = await import(
    '../../resources/js/video-banner-renderer.js'
);

function createAdapter(
    overrides = {}
) {
    const calls = {
        pendingDecision: [],
        slotContext: [],
        confirmRendered: [],
        recordError: [],
        recordClick: [],
        markSlotError: [],
    };

    const decision = {
        show: true,
        format: 'banner',
        opportunity_uuid:
            '11111111-1111-4111-8111-111111111111',
    };

    const adapter = {
        calls,

        pendingDecision(
            slot
        ) {
            calls.pendingDecision.push(
                slot
            );

            return decision;
        },

        slotContext(
            slot
        ) {
            calls.slotContext.push(
                slot
            );

            return {
                placementKey:
                    'video_banner',
            };
        },

        async confirmRendered(
            slot
        ) {
            calls.confirmRendered.push(
                slot
            );

            slot.dataset.adState =
                'rendered';

            return decision;
        },

        async recordError(
            slot,
            reason
        ) {
            calls.recordError.push({
                slot,
                reason,
            });

            slot.dataset.adState =
                'error';

            return {
                ok: true,
            };
        },

        async recordClick(
            slot
        ) {
            calls.recordClick.push(
                slot
            );

            return {
                ok: true,
            };
        },

        markSlotError(
            slot,
            error
        ) {
            calls.markSlotError.push({
                slot,
                error,
            });

            slot.dataset.adState =
                'error';
        },

        ...overrides,
    };

    return {
        adapter,
        calls,
        decision,
    };
}

function createSlot(
    overrides = {}
) {
    return new FakeHTMLElement({
        enabled: 'true',
        format: 'banner',
        placementKey:
            'video_banner',
        adRenderer:
            'test',
        adState:
            'ready',
        ...overrides,
    });
}

test(
    'renderer does nothing when slot is disabled',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        renderer.registerDriver(
            'test',
            {
                async render() {
                    throw new Error(
                        'Driver should not run.'
                    );
                },
            }
        );

        const slot =
            createSlot({
                enabled: 'false',
            });

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            calls.confirmRendered.length,
            0
        );
    }
);

test(
    'renderer does nothing when slot is not ready',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        renderer.registerDriver(
            'test',
            {
                async render() {
                    throw new Error(
                        'Driver should not run.'
                    );
                },
            }
        );

        const slot =
            createSlot({
                adState: 'idle',
            });

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            calls.confirmRendered.length,
            0
        );
    }
);

test(
    'renderer requires a registered driver',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        const slot =
            createSlot({
                adRenderer:
                    'missing',
            });

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            calls.confirmRendered.length,
            0
        );
    }
);

test(
    'successful driver render records impression only after render success',
    async () => {
        const {
            adapter,
            calls,
            decision,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        const order = [];

        renderer.registerDriver(
            'test',
            {
                async render(
                    slot,
                    context
                ) {
                    order.push(
                        'render'
                    );

                    assert.equal(
                        context.decision,
                        decision
                    );

                    assert.deepEqual(
                        context.context,
                        {
                            placementKey:
                                'video_banner',
                        }
                    );

                    slot.innerHTML =
                        '<div>Test Ad</div>';

                    return true;
                },
            }
        );

        adapter.confirmRendered =
            async (
                slot
            ) => {
                order.push(
                    'confirm'
                );

                calls.confirmRendered.push(
                    slot
                );

                slot.dataset.adState =
                    'rendered';

                return decision;
            };

        const slot =
            createSlot();

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            decision
        );

        assert.deepEqual(
            order,
            [
                'render',
                'confirm',
            ]
        );

        assert.equal(
            calls.confirmRendered.length,
            1
        );

        assert.equal(
            slot.dataset.adState,
            'rendered'
        );

        assert.equal(
            slot.attributes[
                'aria-hidden'
            ],
            'false'
        );
    }
);

test(
    'driver returning false is treated as render failure',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        renderer.registerDriver(
            'test',
            {
                async render() {
                    return false;
                },
            }
        );

        const slot =
            createSlot();

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            calls.confirmRendered.length,
            0
        );

        assert.equal(
            calls.recordError.length,
            1
        );

        assert.equal(
            calls.recordError[0]
                .reason,
            'banner_render_failed'
        );

        assert.equal(
            slot.attributes[
                'aria-hidden'
            ],
            'true'
        );
    }
);

test(
    'driver exception is tracked as render failure',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        renderer.registerDriver(
            'test',
            {
                async render() {
                    throw new Error(
                        'creative failed'
                    );
                },
            }
        );

        const slot =
            createSlot();

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            calls.confirmRendered.length,
            0
        );

        assert.equal(
            calls.recordError.length,
            1
        );

        assert.equal(
            slot.dataset.adState,
            'error'
        );
    }
);

test(
    'missing pending decision prevents rendering',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter({
            pendingDecision() {
                calls.pendingDecision.push(
                    'called'
                );

                return null;
            },
        });

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        let renderCalls = 0;

        renderer.registerDriver(
            'test',
            {
                async render() {
                    renderCalls++;

                    return true;
                },
            }
        );

        const slot =
            createSlot();

        const result =
            await renderer.renderSlot(
                slot
            );

        assert.equal(
            result,
            null
        );

        assert.equal(
            renderCalls,
            0
        );

        assert.equal(
            calls.confirmRendered.length,
            0
        );

        assert.equal(
            slot.dataset.adState,
            'expired'
        );
    }
);

test(
    'banner click delegates to adapter',
    async () => {
        const {
            adapter,
            calls,
        } = createAdapter();

        const renderer =
            new XurvexaBannerRenderer(
                adapter
            );

        const slot =
            createSlot();

        const result =
            await renderer.recordClick(
                slot
            );

        assert.deepEqual(
            result,
            {
                ok: true,
            }
        );

        assert.equal(
            calls.recordClick.length,
            1
        );

        assert.equal(
            calls.recordClick[0],
            slot
        );
    }
);
