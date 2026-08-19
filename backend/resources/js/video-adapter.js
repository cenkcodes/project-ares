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

        this.pendingSlots.set(
            slot,
            {
                format,
                context,
                decision,
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
