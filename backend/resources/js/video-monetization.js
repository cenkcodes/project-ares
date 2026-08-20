class XurvexaMonetizationClient {
    constructor(configElement) {
        this.configElement = configElement;

        this.interactionUrl =
            configElement.dataset.interactionUrl ?? '';

        this.decisionUrl =
            configElement.dataset.decisionUrl ?? '';

        this.eventUrl =
            configElement.dataset.eventUrl ?? '';

        this.csrfToken =
            configElement.dataset.csrfToken ?? '';

        this.videoId =
            this.parseVideoId(
                configElement.dataset.videoId
            );

        this.defaultPlacementKey =
            configElement.dataset.placementKey
            ?? 'video_player';

        this.configuredPrefetchFormats =
            this.parsePrefetchFormats(
                configElement.dataset.prefetchFormats
                ?? ''
            );

        this.requestTimeoutMs = 8000;

        /*
         * Pending decisions intentionally live only
         * for a short period in this page instance.
         *
         * A stale decision must never remain available
         * indefinitely for a later ad trigger.
         */
        this.pendingDecisionTtlMs =
            60 * 1000;

        this.pendingDecisions =
            new Map();

        this.decisionRequests =
            new Map();

        this.lastInteractionAt = 0;

        this.interactionThrottleMs = 750;

        this.meaningfulInteractionSelector =
            '[data-xurvexa-meaningful-interaction]';

        this.boundInteractionHandler =
            this.handleMeaningfulInteraction.bind(
                this
            );
    }

    isConfigured() {
        return Boolean(
            this.interactionUrl &&
            this.decisionUrl &&
            this.eventUrl &&
            this.csrfToken
        );
    }

    isMobile() {
        if (
            navigator.userAgentData &&
            typeof navigator.userAgentData.mobile
                === 'boolean'
        ) {
            return navigator.userAgentData.mobile;
        }

        return window.matchMedia(
            '(max-width: 767px)'
        ).matches;
    }

    bindMeaningfulInteractions() {
        document.addEventListener(
            'click',
            this.boundInteractionHandler,
            {
                capture: true,
            }
        );
    }

    unbindMeaningfulInteractions() {
        document.removeEventListener(
            'click',
            this.boundInteractionHandler,
            {
                capture: true,
            }
        );
    }

    handleMeaningfulInteraction(event) {
        if (
            event.defaultPrevented ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        if (
            typeof event.button === 'number' &&
            event.button !== 0
        ) {
            return;
        }

        const target =
            event.target instanceof Element
                ? event.target
                : null;

        if (!target) {
            return;
        }

        const interactionElement =
            target.closest(
                this.meaningfulInteractionSelector
            );

        if (!interactionElement) {
            return;
        }

        if (
            interactionElement instanceof
            HTMLAnchorElement
        ) {
            if (
                interactionElement.target === '_blank' ||
                interactionElement.hasAttribute(
                    'download'
                )
            ) {
                return;
            }

            let destination;

            try {
                destination =
                    new URL(
                        interactionElement.href,
                        window.location.href
                    );
            } catch {
                return;
            }

            if (
                destination.origin !==
                window.location.origin
            ) {
                return;
            }
        }

        this.recordMeaningfulInteraction({
            keepalive: true,
        }).catch(() => {
            /*
             * Interaction tracking must never
             * interfere with normal navigation.
             */
        });
    }

    async recordMeaningfulInteraction(
        options = {}
    ) {
        const now = Date.now();

        if (
            now - this.lastInteractionAt <
            this.interactionThrottleMs
        ) {
            return null;
        }

        this.lastInteractionAt = now;

        return this.postJson(
            this.interactionUrl,
            {},
            {
                keepalive:
                    options.keepalive === true,
            }
        );
    }

    async decide(
        format,
        options = {}
    ) {
        const context =
            this.resolveDecisionContext(
                options
            );

        const payload = {
            format,
            video_id:
                context.videoId,
            is_mobile:
                context.isMobile,
            placement_key:
                context.placementKey,
        };

        const response = await this.postJson(
            this.decisionUrl,
            payload
        );

        return response?.decision ?? null;
    }

    async prefetchDecision(
        format,
        options = {}
    ) {
        this.assertFormat(
            format
        );

        this.clearExpiredPendingDecisions();

        const context =
            this.resolveDecisionContext(
                options
            );

        const key =
            this.decisionKey(
                format,
                context
            );

        const existing =
            this.pendingDecisions.get(
                key
            );

        if (
            existing &&
            !this.isPendingDecisionExpired(
                existing
            )
        ) {
            return existing.decision;
        }

        const existingRequest =
            this.decisionRequests.get(
                key
            );

        if (existingRequest) {
            return existingRequest;
        }

        const request =
            this.fetchAndStoreDecision(
                format,
                context,
                key
            );

        this.decisionRequests.set(
            key,
            request
        );

        try {
            return await request;
        } finally {
            this.decisionRequests.delete(
                key
            );
        }
    }

    async fetchAndStoreDecision(
        format,
        context,
        key
    ) {
        const decision =
            await this.decide(
                format,
                {
                    videoId:
                        context.videoId,

                    isMobile:
                        context.isMobile,

                    placementKey:
                        context.placementKey,
                }
            );

        this.pendingDecisions.delete(
            key
        );

        if (
            !decision ||
            decision.show !== true
        ) {
            this.dispatchDecisionEvent(
                'xurvexa:monetization-decision-skipped',
                {
                    format,
                    decision,
                    context,
                }
            );

            return decision;
        }

        this.assertDecision(
            decision
        );

        const storedAt =
            Date.now();

        const pending = {
            decision,
            context,
            storedAt,
            expiresAt:
                storedAt +
                this.pendingDecisionTtlMs,
        };

        this.pendingDecisions.set(
            key,
            pending
        );

        this.dispatchDecisionEvent(
            'xurvexa:monetization-decision-ready',
            {
                format,
                decision,
                context,
                expiresAt:
                    pending.expiresAt,
            }
        );

        return decision;
    }

    async prefetchConfiguredDecisions() {
        if (
            this.configuredPrefetchFormats.length
            === 0
        ) {
            return [];
        }

        const results =
            await Promise.allSettled(
                this.configuredPrefetchFormats.map(
                    (format) =>
                        this.prefetchDecision(
                            format
                        )
                )
            );

        return results;
    }

    peekPendingDecision(
        format,
        options = {}
    ) {
        this.assertFormat(
            format
        );

        this.clearExpiredPendingDecisions();

        const context =
            this.resolveDecisionContext(
                options
            );

        const key =
            this.decisionKey(
                format,
                context
            );

        const pending =
            this.pendingDecisions.get(
                key
            );

        if (!pending) {
            return null;
        }

        return pending.decision;
    }

    consumePendingDecision(
        format,
        options = {}
    ) {
        this.assertFormat(
            format
        );

        this.clearExpiredPendingDecisions();

        const context =
            this.resolveDecisionContext(
                options
            );

        const key =
            this.decisionKey(
                format,
                context
            );

        const pending =
            this.pendingDecisions.get(
                key
            );

        if (!pending) {
            return null;
        }

        this.pendingDecisions.delete(
            key
        );

        this.dispatchDecisionEvent(
            'xurvexa:monetization-decision-consumed',
            {
                format,
                decision:
                    pending.decision,
                context:
                    pending.context,
            }
        );

        return pending.decision;
    }

    discardPendingDecision(
        format,
        options = {}
    ) {
        this.assertFormat(
            format
        );

        const context =
            this.resolveDecisionContext(
                options
            );

        const key =
            this.decisionKey(
                format,
                context
            );

        return this.pendingDecisions.delete(
            key
        );
    }

    clearExpiredPendingDecisions() {
        const now =
            Date.now();

        for (
            const [
                key,
                pending,
            ]
            of this.pendingDecisions.entries()
        ) {
            if (
                pending.expiresAt <= now
            ) {
                this.pendingDecisions.delete(
                    key
                );

                this.dispatchDecisionEvent(
                    'xurvexa:monetization-decision-expired',
                    {
                        decision:
                            pending.decision,
                        context:
                            pending.context,
                    }
                );
            }
        }
    }

    hasPendingDecision(
        format,
        options = {}
    ) {
        return (
            this.peekPendingDecision(
                format,
                options
            ) !== null
        );
    }

    pendingDecisionCount() {
        this.clearExpiredPendingDecisions();

        return this.pendingDecisions.size;
    }

    async recordImpression(
        decision,
        options = {}
    ) {
        return this.recordEvent(
            'impression',
            decision,
            options
        );
    }

    async recordClick(
        decision,
        options = {}
    ) {
        return this.recordEvent(
            'click',
            decision,
            options
        );
    }

    async recordSkip(
        decision,
        options = {}
    ) {
        return this.recordEvent(
            'skip',
            decision,
            options
        );
    }

    async recordClose(
        decision,
        options = {}
    ) {
        return this.recordEvent(
            'close',
            decision,
            options
        );
    }

    async recordError(
        decision,
        errorReason,
        options = {}
    ) {
        if (
            typeof errorReason !== 'string' ||
            errorReason.trim() === ''
        ) {
            throw new Error(
                'A non-empty error reason is required.'
            );
        }

        return this.recordEvent(
            'error',
            decision,
            {
                ...options,
                errorReason:
                    errorReason.trim(),
            }
        );
    }

    async recordEvent(
        eventType,
        decision,
        options = {}
    ) {
        this.assertDecision(
            decision
        );

        const context =
            this.resolveDecisionContext(
                options
            );

        const payload = {
            event_type:
                eventType,

            format:
                decision.format,

            opportunity_uuid:
                decision.opportunity_uuid,

            video_id:
                context.videoId,

            is_mobile:
                context.isMobile,

            placement_key:
                context.placementKey,
        };

        if (
            eventType === 'error'
        ) {
            payload.error_reason =
                options.errorReason;
        }

        return this.postJson(
            this.eventUrl,
            payload
        );
    }

    async postJson(
        url,
        payload,
        options = {}
    ) {
        if (!this.isConfigured()) {
            throw new Error(
                'Monetization client is not configured.'
            );
        }

        if (
            typeof url !== 'string' ||
            url === ''
        ) {
            throw new Error(
                'Monetization endpoint is missing.'
            );
        }

        const useKeepalive =
            options.keepalive === true;

        const controller =
            useKeepalive
                ? null
                : new AbortController();

        const timeoutId =
            controller
                ? window.setTimeout(
                    () => {
                        controller.abort();
                    },
                    this.requestTimeoutMs
                )
                : null;

        try {
            const fetchOptions = {
                method: 'POST',

                credentials:
                    'same-origin',

                headers: {
                    Accept:
                        'application/json',

                    'Content-Type':
                        'application/json',

                    'X-CSRF-TOKEN':
                        this.csrfToken,

                    'X-Requested-With':
                        'XMLHttpRequest',
                },

                body:
                    JSON.stringify(
                        payload
                    ),

                keepalive:
                    useKeepalive,
            };

            if (controller) {
                fetchOptions.signal =
                    controller.signal;
            }

            const response = await fetch(
                url,
                fetchOptions
            );

            const data =
                await this.readJsonResponse(
                    response
                );

            if (!response.ok) {
                throw this.createRequestError(
                    response,
                    data
                );
            }

            return data;
        } catch (error) {
            if (
                error instanceof DOMException &&
                error.name === 'AbortError'
            ) {
                throw new Error(
                    'Monetization request timed out.'
                );
            }

            throw error;
        } finally {
            if (timeoutId !== null) {
                window.clearTimeout(
                    timeoutId
                );
            }
        }
    }

    async readJsonResponse(
        response
    ) {
        const contentType =
            response.headers.get(
                'content-type'
            ) ?? '';

        if (
            !contentType.includes(
                'application/json'
            )
        ) {
            return null;
        }

        return response.json();
    }

    createRequestError(
        response,
        data
    ) {
        const message =
            data?.message ??
            `Monetization request failed with status ${response.status}.`;

        const error =
            new Error(message);

        error.status =
            response.status;

        error.response =
            data;

        return error;
    }

    resolveDecisionContext(
        options = {}
    ) {
        return {
            videoId:
                options.videoId ??
                this.videoId,

            isMobile:
                options.isMobile ??
                this.isMobile(),

            placementKey:
                options.placementKey ??
                this.defaultPlacementKey,
        };
    }

    decisionKey(
        format,
        context
    ) {
        const videoPart =
            context.videoId === null ||
            context.videoId === undefined
                ? 'none'
                : String(
                    context.videoId
                );

        const devicePart =
            context.isMobile
                ? 'mobile'
                : 'desktop';

        const placementPart =
            context.placementKey ??
            'none';

        return [
            format,
            videoPart,
            devicePart,
            placementPart,
        ].join('|');
    }

    isPendingDecisionExpired(
        pending
    ) {
        return (
            !pending ||
            typeof pending.expiresAt
                !== 'number' ||
            pending.expiresAt <=
                Date.now()
        );
    }

    dispatchDecisionEvent(
        eventName,
        detail
    ) {
        window.dispatchEvent(
            new CustomEvent(
                eventName,
                {
                    detail,
                }
            )
        );
    }

    assertDecision(
        decision
    ) {
        if (
            !decision ||
            typeof decision !== 'object'
        ) {
            throw new Error(
                'A monetization decision is required.'
            );
        }

        if (
            typeof decision.format !== 'string' ||
            decision.format === ''
        ) {
            throw new Error(
                'Decision format is missing.'
            );
        }

        if (
            typeof decision.opportunity_uuid
                !== 'string' ||
            decision.opportunity_uuid === ''
        ) {
            throw new Error(
                'Decision opportunity UUID is missing.'
            );
        }
    }

    assertFormat(
        format
    ) {
        const supportedFormats = [
            'native',
            'banner',
            'preroll',
            'midroll',
            'popunder',
            'interstitial',
        ];

        if (
            typeof format !== 'string' ||
            !supportedFormats.includes(
                format
            )
        ) {
            throw new Error(
                'Unsupported monetization format.'
            );
        }
    }

    parsePrefetchFormats(
        value
    ) {
        if (
            typeof value !== 'string' ||
            value.trim() === ''
        ) {
            return [];
        }

        const supportedFormats = [
            'native',
            'banner',
            'preroll',
            'midroll',
            'popunder',
            'interstitial',
        ];

        return [
            ...new Set(
                value
                    .split(',')
                    .map(
                        (format) =>
                            format.trim()
                    )
                    .filter(
                        (format) =>
                            supportedFormats.includes(
                                format
                            )
                    )
            ),
        ];
    }

    parseVideoId(
        value
    ) {
        if (
            value === undefined ||
            value === null ||
            value === ''
        ) {
            return null;
        }

        const parsed =
            Number.parseInt(
                value,
                10
            );

        return Number.isInteger(parsed) &&
            parsed > 0
            ? parsed
            : null;
    }
}

function bootXurvexaMonetization() {
    const configElement =
        document.querySelector(
            '[data-xurvexa-monetization]'
        );

    if (!configElement) {
        return;
    }

    const client =
        new XurvexaMonetizationClient(
            configElement
        );

    if (!client.isConfigured()) {
        console.error(
            'Xurvexa monetization configuration is incomplete.'
        );

        return;
    }

    client.bindMeaningfulInteractions();

    window.XurvexaMonetization =
        client;

    window.dispatchEvent(
        new CustomEvent(
            'xurvexa:monetization-ready',
            {
                detail: {
                    client,
                },
            }
        )
    );

    client
        .prefetchConfiguredDecisions()
        .catch(() => {
            /*
             * Decision prefetch failures must
             * never affect page usability.
             */
        });
}

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        bootXurvexaMonetization,
        {
            once: true,
        }
    );
} else {
    bootXurvexaMonetization();
}

export {
    XurvexaMonetizationClient,
    bootXurvexaMonetization,
};
