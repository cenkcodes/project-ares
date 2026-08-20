class XurvexaBannerRenderer {
    constructor(
        adapter,
        root = document
    ) {
        this.adapter = adapter;
        this.root = root;

        this.slotSelector =
            '[data-xurvexa-ad-slot][data-format="banner"]';

        this.drivers =
            new Map();

        this.renderingSlots =
            new WeakSet();

        this.bound = false;

        this.handleSlotReady =
            this.handleSlotReady.bind(
                this
            );
    }

    bind() {
        if (this.bound) {
            return;
        }

        window.addEventListener(
            'xurvexa:ad-slot-ready',
            this.handleSlotReady
        );

        this.bound = true;

        this.renderReadySlots()
            .catch(
                (error) => {
                    this.dispatchRendererEvent(
                        'xurvexa:banner-renderer-error',
                        null,
                        {
                            error,
                        }
                    );
                }
            );
    }

    registerDriver(
        name,
        driver
    ) {
        const driverName =
            this.normalizeDriverName(
                name
            );

        if (!driverName) {
            throw new Error(
                'A banner renderer driver name is required.'
            );
        }

        if (
            !driver ||
            typeof driver.render
                !== 'function'
        ) {
            throw new Error(
                'A banner renderer driver must provide a render function.'
            );
        }

        this.drivers.set(
            driverName,
            driver
        );

        this.renderReadySlots(
            driverName
        ).catch(
            (error) => {
                this.dispatchRendererEvent(
                    'xurvexa:banner-renderer-error',
                    null,
                    {
                        driverName,
                        error,
                    }
                );
            }
        );

        return this;
    }

    unregisterDriver(
        name
    ) {
        const driverName =
            this.normalizeDriverName(
                name
            );

        if (!driverName) {
            return false;
        }

        return this.drivers.delete(
            driverName
        );
    }

    hasDriver(
        name
    ) {
        const driverName =
            this.normalizeDriverName(
                name
            );

        return (
            driverName !== '' &&
            this.drivers.has(
                driverName
            )
        );
    }

    handleSlotReady(
        event
    ) {
        const slot =
            event.detail?.slot
            ?? null;

        if (
            !(slot instanceof HTMLElement)
        ) {
            return;
        }

        if (
            this.slotFormat(slot)
            !== 'banner'
        ) {
            return;
        }

        this.renderSlot(
            slot
        ).catch(
            (error) => {
                this.handleUnexpectedError(
                    slot,
                    error
                );
            }
        );
    }

    async renderReadySlots(
        driverName = null
    ) {
        const slots =
            this.root.querySelectorAll(
                this.slotSelector
            );

        const normalizedDriverName =
            driverName === null
                ? null
                : this.normalizeDriverName(
                    driverName
                );

        for (const slot of slots) {
            if (
                slot.dataset.adState
                !== 'ready'
            ) {
                continue;
            }

            if (
                normalizedDriverName !== null &&
                this.slotDriverName(
                    slot
                ) !== normalizedDriverName
            ) {
                continue;
            }

            try {
                await this.renderSlot(
                    slot
                );
            } catch (error) {
                this.handleUnexpectedError(
                    slot,
                    error
                );
            }
        }
    }

    async renderSlot(
        slot
    ) {
        this.assertSlotElement(
            slot
        );

        if (
            slot.dataset.enabled
            !== 'true'
        ) {
            return null;
        }

        if (
            this.slotFormat(slot)
            !== 'banner'
        ) {
            return null;
        }

        if (
            slot.dataset.adState
            !== 'ready'
        ) {
            return null;
        }

        if (
            this.renderingSlots.has(
                slot
            )
        ) {
            return null;
        }

        const driverName =
            this.slotDriverName(
                slot
            );

        if (!driverName) {
            this.dispatchRendererEvent(
                'xurvexa:banner-renderer-unconfigured',
                slot
            );

            return null;
        }

        const driver =
            this.drivers.get(
                driverName
            );

        if (!driver) {
            this.dispatchRendererEvent(
                'xurvexa:banner-renderer-missing-driver',
                slot,
                {
                    driverName,
                }
            );

            return null;
        }

        const decision =
            this.adapter
                .pendingDecision(
                    slot
                );

        if (!decision) {
            slot.dataset.adState =
                'expired';

            this.dispatchRendererEvent(
                'xurvexa:banner-renderer-expired',
                slot,
                {
                    driverName,
                }
            );

            return null;
        }

        const context =
            this.adapter
                .slotContext(
                    slot
                );

        this.renderingSlots.add(
            slot
        );

        let rendered;

        try {
            rendered =
                await driver.render(
                    slot,
                    {
                        decision,
                        context,
                        renderer:
                            this,
                    }
                );
        } catch (error) {
            await this.failPendingRender(
                slot,
                driverName,
                error
            );

            return null;
        }

        if (rendered !== true) {
            await this.failPendingRender(
                slot,
                driverName,
                new Error(
                    'Banner driver did not confirm successful rendering.'
                )
            );

            return null;
        }

        /*
         * The driver has inserted and completed
         * the creative. Move the slot out of
         * the hidden "ready" state before
         * recording the impression.
         */
        slot.dataset.adState =
            'rendering';

        slot.setAttribute(
            'aria-hidden',
            'false'
        );

        try {
            const confirmedDecision =
                await this.adapter
                    .confirmRendered(
                        slot
                    );

            this.dispatchRendererEvent(
                'xurvexa:banner-rendered',
                slot,
                {
                    driverName,
                    decision:
                        confirmedDecision,
                    context,
                }
            );

            return confirmedDecision;
        } catch (error) {
            this.clearSlot(
                slot
            );

            this.adapter.markSlotError(
                slot,
                error
            );

            this.dispatchRendererEvent(
                'xurvexa:banner-impression-error',
                slot,
                {
                    driverName,
                    error,
                }
            );

            return null;
        } finally {
            this.renderingSlots.delete(
                slot
            );
        }
    }

    async failPendingRender(
        slot,
        driverName,
        error
    ) {
        this.clearSlot(
            slot
        );

        try {
            await this.adapter
                .recordError(
                    slot,
                    'banner_render_failed'
                );
        } catch (recordError) {
            this.adapter.markSlotError(
                slot,
                recordError
            );
        }

        this.dispatchRendererEvent(
            'xurvexa:banner-render-failed',
            slot,
            {
                driverName,
                error,
            }
        );

        this.renderingSlots.delete(
            slot
        );
    }

    async recordClick(
        slot
    ) {
        this.assertSlotElement(
            slot
        );

        return this.adapter
            .recordClick(
                slot
            );
    }

    clearSlot(
        slot
    ) {
        if (
            typeof slot.replaceChildren
            === 'function'
        ) {
            slot.replaceChildren();
        } else {
            slot.innerHTML = '';
        }

        slot.setAttribute(
            'aria-hidden',
            'true'
        );
    }

    slotDriverName(
        slot
    ) {
        return this.normalizeDriverName(
            slot.dataset.adRenderer
            ?? ''
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

    normalizeDriverName(
        name
    ) {
        if (
            typeof name
            !== 'string'
        ) {
            return '';
        }

        return name
            .trim()
            .toLowerCase();
    }

    handleUnexpectedError(
        slot,
        error
    ) {
        if (
            slot instanceof HTMLElement
        ) {
            this.clearSlot(
                slot
            );

            this.adapter.markSlotError(
                slot,
                error
            );
        }

        this.dispatchRendererEvent(
            'xurvexa:banner-renderer-error',
            slot,
            {
                error,
            }
        );
    }

    dispatchRendererEvent(
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
                'A valid banner ad slot element is required.'
            );
        }
    }
}

function bootXurvexaBannerRenderer(
    adapter
) {
    if (!adapter) {
        return null;
    }

    if (
        window.XurvexaBannerRenderer
        instanceof XurvexaBannerRenderer
    ) {
        return window
            .XurvexaBannerRenderer;
    }

    const renderer =
        new XurvexaBannerRenderer(
            adapter
        );

    renderer.bind();

    window.XurvexaBannerRenderer =
        renderer;

    window.dispatchEvent(
        new CustomEvent(
            'xurvexa:banner-renderer-ready',
            {
                detail: {
                    renderer,
                },
            }
        )
    );

    return renderer;
}

function waitForXurvexaVideoAdapter() {
    if (
        window.XurvexaVideoAdAdapter
    ) {
        bootXurvexaBannerRenderer(
            window.XurvexaVideoAdAdapter
        );

        return;
    }

    window.addEventListener(
        'xurvexa:video-adapter-ready',
        (event) => {
            bootXurvexaBannerRenderer(
                event.detail?.adapter
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
        waitForXurvexaVideoAdapter,
        {
            once: true,
        }
    );
} else {
    waitForXurvexaVideoAdapter();
}

export {
    XurvexaBannerRenderer,
    bootXurvexaBannerRenderer,
};
