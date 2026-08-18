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

        this.requestTimeoutMs = 8000;

        this.lastInteractionAt = 0;

        this.interactionThrottleMs = 750;
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

    async recordMeaningfulInteraction() {
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
            {}
        );
    }

    async decide(
        format,
        options = {}
    ) {
        const payload = {
            format,
            video_id:
                options.videoId ??
                this.videoId,
            is_mobile:
                options.isMobile ??
                this.isMobile(),
            placement_key:
                options.placementKey ??
                this.defaultPlacementKey,
        };

        const response = await this.postJson(
            this.decisionUrl,
            payload
        );

        return response?.decision ?? null;
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

        const payload = {
            event_type: eventType,
            format: decision.format,
            opportunity_uuid:
                decision.opportunity_uuid,
            video_id:
                options.videoId ??
                this.videoId,
            is_mobile:
                options.isMobile ??
                this.isMobile(),
            placement_key:
                options.placementKey ??
                this.defaultPlacementKey,
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
        payload
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

        const controller =
            new AbortController();

        const timeoutId =
            window.setTimeout(
                () => {
                    controller.abort();
                },
                this.requestTimeoutMs
            );

        try {
            const response = await fetch(
                url,
                {
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

                    signal:
                        controller.signal,
                }
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
            window.clearTimeout(
                timeoutId
            );
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
