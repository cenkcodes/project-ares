class XurvexaVideoAdAdapter {
    constructor(
        client,
        root = document
    ) {
        this.client = client;
        this.root = root;

        this.slotSelector =
            '[data-xurvexa-ad-slot]';

        this.supportedFormats = [
            'banner',
        ];

        this.pendingSlots =
            new WeakMap();

        this.activeSlots =
            new WeakMap();
    }

    bind() {
        const slots =
            this.root.querySelectorAll(
                this.slotSelector
            );

        for (const slot of slots) {
            this.prepareSlot(
                slot
            ).catch(
                (error) => {
                    this.markSlotError(
                        slot,
                        error
                    );
                }
            );
        }
    }

    async prepareSlot(
        slot
    ) {
        this.assertSlotElement(
            slot
        );

        /*
         * Renderer selection is always rebuilt from the
         * trusted server-created decision. Never keep a
         * stale or markup-provided renderer selection.
         */
        this.clearRendererSelection(
            slot
        );

        if (!this.isSlotEnabled(slot)) {
            this.setSlotState(
                slot,
                'disabled'
            );

            return null;
        }

        const format =
            this.slotFormat(
                slot
            );

        if (
            !this.supportedFormats.includes(
                format
            )
        ) {
            this.setSlotState(
                slot,
                'unsupported'
            );

            return null;
        }

        const context =
            this.slotContext(
                slot
            );

        this.setSlotState(
            slot,
            'requesting'
        );

        const decision =
            await this.client
                .prefetchDecision(
                    format,
                    context
                );

        if (
            !decision ||
            decision.show !== true
        ) {
            this.setSlotState(
                slot,
                'skipped'
            );

            this.dispatchSlotEvent(
                'xurvexa:ad-slot-skipped',
                slot,
                {
                    format,
                    decision,
                    context,
                }
            );

            return decision;
        }

        let delivery;

        try {
            delivery =
                this.trustedDelivery(
                    decision
                );
        } catch (error) {
            /*
             * The server opportunity may still exist, but
             * a malformed browser delivery contract must
             * never reach a renderer. Drop the local
             * prefetched decision and fail closed.
             */
            this.client
                .discardPendingDecision(
                    format,
                    context
                );

            this.setSlotState(
                slot,
                'error'
            );

            this.dispatchSlotEvent(
                'xurvexa:ad-slot-invalid-delivery',
                slot,
                {
                    format,
                    decision,
                    context,
                    error,
                }
            );

            throw error;
        }

        this.applyRendererSelection(
            slot,
            delivery.adDriver
        );

        this.pendingSlots.set(
            slot,
            {
                format,
                context,
                decision,
                delivery,
            }
        );

        this.setSlotState(
            slot,
            'ready'
        );

        this.dispatchSlotEvent(
            'xurvexa:ad-slot-ready',
            slot,
            {
                format,
                decision,
                context,
                delivery,
            }
        );

        return decision;
    }

    pendingDecision(
        slot
    ) {
        const pending =
            this.pendingSlots.get(
                slot
            );

        return pending?.decision
            ?? null;
    }

    activeDecision(
        slot
    ) {
        const active =
            this.activeSlots.get(
                slot
            );

        return active?.decision
            ?? null;
    }

    pendingDelivery(
        slot
    ) {
        const pending =
            this.pendingSlots.get(
                slot
            );

        return pending?.delivery
            ?? null;
    }

    activeDelivery(
        slot
    ) {
        const active =
            this.activeSlots.get(
                slot
            );

        return active?.delivery
            ?? null;
    }

    async confirmRendered(
        slot
    ) {
        this.assertSlotElement(
            slot
        );

        const pending =
            this.pendingSlots.get(
                slot
            );

        if (!pending) {
            throw new Error(
                'No pending monetization decision exists for this ad slot.'
            );
        }

        const decision =
            this.client
                .consumePendingDecision(
                    pending.format,
                    pending.context
                );

        if (!decision) {
            this.pendingSlots.delete(
                slot
            );

            this.clearRendererSelection(
                slot
            );

            this.setSlotState(
                slot,
                'expired'
            );

            throw new Error(
                'The pending monetization decision has expired.'
            );
        }

        /*
         * The renderer must call this method only
         * after the actual ad has successfully
         * appeared in the slot.
         */
        await this.client
            .recordImpression(
                decision,
                pending.context
            );

        this.pendingSlots.delete(
            slot
        );

        this.activeSlots.set(
            slot,
            {
                format:
                    pending.format,

                context:
                    pending.context,

                decision,

                delivery:
                    pending.delivery,
            }
        );

        this.setSlotState(
            slot,
            'rendered'
        );

        this.dispatchSlotEvent(
            'xurvexa:ad-slot-impression',
            slot,
            {
                format:
                    pending.format,

                decision,

                context:
                    pending.context,

                delivery:
                    pending.delivery,
            }
        );

        return decision;
    }

    async recordClick(
        slot
    ) {
        this.assertSlotElement(
            slot
        );

        const active =
            this.activeSlots.get(
                slot
            );

        if (!active) {
            throw new Error(
                'An ad impression is required before recording a click.'
            );
        }

        const response =
            await this.client
                .recordClick(
                    active.decision,
                    active.context
                );

        this.dispatchSlotEvent(
            'xurvexa:ad-slot-click',
            slot,
            {
                format:
                    active.format,

                decision:
                    active.decision,

                context:
                    active.context,

                delivery:
                    active.delivery,
            }
        );

        return response;
    }

    async recordError(
        slot,
        errorReason
    ) {
        this.assertSlotElement(
            slot
        );

        if (
            typeof errorReason !== 'string' ||
            errorReason.trim() === ''
        ) {
            throw new Error(
                'A non-empty ad error reason is required.'
            );
        }

        const active =
            this.activeSlots.get(
                slot
            );

        if (active) {
            const response =
                await this.client
                    .recordError(
                        active.decision,
                        errorReason,
                        active.context
                    );

            this.activeSlots.delete(
                slot
            );

            this.clearRendererSelection(
                slot
            );

            this.setSlotState(
                slot,
                'error'
            );

            return response;
        }

        const pending =
            this.pendingSlots.get(
                slot
            );

        if (!pending) {
            throw new Error(
                'No monetization decision exists for this ad slot.'
            );
        }

        const decision =
            this.client
                .consumePendingDecision(
                    pending.format,
                    pending.context
                );

        this.pendingSlots.delete(
            slot
        );

        this.clearRendererSelection(
            slot
        );

        if (!decision) {
            this.setSlotState(
                slot,
                'expired'
            );

            throw new Error(
                'The pending monetization decision has expired.'
            );
        }

        const response =
            await this.client
                .recordError(
                    decision,
                    errorReason,
                    pending.context
                );

        this.setSlotState(
            slot,
            'error'
        );

        return response;
    }

    discardSlot(
        slot
    ) {
        this.assertSlotElement(
            slot
        );

        const pending =
            this.pendingSlots.get(
                slot
            );

        if (pending) {
            this.client
                .discardPendingDecision(
                    pending.format,
                    pending.context
                );

            this.pendingSlots.delete(
                slot
            );
        }

        this.activeSlots.delete(
            slot
        );

        this.clearRendererSelection(
            slot
        );

        this.setSlotState(
            slot,
            'discarded'
        );
    }

    isSlotEnabled(
        slot
    ) {
        return (
            slot.dataset.enabled
            === 'true'
        );
    }

    slotFormat(
        slot
    ) {
        return (
            slot.dataset.format
            ?? ''
        ).trim();
    }

    slotContext(
        slot
    ) {
        const placementKey =
            (
                slot.dataset.placementKey
                ?? 'video_banner'
            ).trim();

        return {
            placementKey:
                placementKey
                || 'video_banner',
        };
    }

    trustedDelivery(
        decision
    ) {
        const delivery =
            decision?.delivery;

        if (
            !delivery ||
            typeof delivery !== 'object' ||
            Array.isArray(delivery)
        ) {
            throw new Error(
                'Trusted monetization delivery is missing.'
            );
        }

        const adNetwork =
            this.normalizeDeliveryIdentifier(
                delivery.ad_network,
                'ad network'
            );

        const adDriver =
            this.normalizeDeliveryIdentifier(
                delivery.ad_driver,
                'ad driver'
            );

        const adPlacementId =
            delivery.ad_placement_id;

        if (
            !Number.isInteger(
                adPlacementId
            ) ||
            adPlacementId < 1
        ) {
            throw new Error(
                'Trusted monetization ad placement ID is invalid.'
            );
        }

        return {
            adNetwork,
            adPlacementId,
            adDriver,

            publicPlacementId:
                delivery.public_placement_id
                ?? null,

            publicConfig:
                delivery.public_config
                ?? null,
        };
    }

    normalizeDeliveryIdentifier(
        value,
        label
    ) {
        if (
            typeof value !== 'string'
        ) {
            throw new Error(
                `Trusted monetization ${label} is invalid.`
            );
        }

        const normalized =
            value
                .trim()
                .toLowerCase();

        if (
            !/^[a-z0-9][a-z0-9_-]{0,63}$/
                .test(normalized)
        ) {
            throw new Error(
                `Trusted monetization ${label} is invalid.`
            );
        }

        return normalized;
    }

    applyRendererSelection(
        slot,
        driverName
    ) {
        slot.dataset.adRenderer =
            driverName;
    }

    clearRendererSelection(
        slot
    ) {
        delete slot.dataset.adRenderer;
    }

    setSlotState(
        slot,
        state
    ) {
        slot.dataset.adState =
            state;
    }

    markSlotError(
        slot,
        error
    ) {
        this.clearRendererSelection(
            slot
        );

        this.setSlotState(
            slot,
            'error'
        );

        this.dispatchSlotEvent(
            'xurvexa:ad-slot-error',
            slot,
            {
                error,
            }
        );
    }

    dispatchSlotEvent(
        eventName,
        slot,
        detail = {}
    ) {
        window.dispatchEvent(
            new CustomEvent(
                eventName,
                {
                    detail: {
                        slot,
                        ...detail,
                    },
                }
            )
        );
    }

    assertSlotElement(
        slot
    ) {
        if (
            !(slot instanceof HTMLElement)
        ) {
            throw new Error(
                'A valid monetization ad slot element is required.'
            );
        }
    }
}

function bootXurvexaVideoAdAdapter(
    client
) {
    if (!client) {
        return null;
    }

    const adapter =
        new XurvexaVideoAdAdapter(
            client
        );

    adapter.bind();

    window.XurvexaVideoAdAdapter =
        adapter;

    window.dispatchEvent(
        new CustomEvent(
            'xurvexa:video-adapter-ready',
            {
                detail: {
                    adapter,
                },
            }
        )
    );

    return adapter;
}

function waitForXurvexaMonetization() {
    if (
        window.XurvexaMonetization
    ) {
        bootXurvexaVideoAdAdapter(
            window.XurvexaMonetization
        );

        return;
    }

    window.addEventListener(
        'xurvexa:monetization-ready',
        (event) => {
            bootXurvexaVideoAdAdapter(
                event.detail?.client
                ?? null
            );
        },
        {
            once: true,
        }
    );
}

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        waitForXurvexaMonetization,
        {
            once: true,
        }
    );
} else {
    waitForXurvexaMonetization();
}

export {
    XurvexaVideoAdAdapter,
    bootXurvexaVideoAdAdapter,
};
