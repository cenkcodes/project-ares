const EXOCLICK_DRIVER_NAME =
    'exoclick';

const EXOCLICK_PROVIDER_SCRIPT_ID =
    'xurvexa-exoclick-ad-provider';

const EXOCLICK_PROVIDER_SCRIPT_URL =
    'https://a.magsrv.com/ad-provider.js';

const EXOCLICK_BANNER_CLASS =
    'eas6a97888e2';


class XurvexaExoClickBannerDriver {
    async render(
        slot,
        {
            decision,
        }
    ) {
        if (
            !(slot instanceof HTMLElement)
        ) {
            throw new Error(
                'A valid ExoClick banner slot is required.'
            );
        }

        const delivery =
            decision?.delivery
            ?? null;

        if (
            !delivery ||
            typeof delivery !== 'object' ||
            Array.isArray(delivery)
        ) {
            throw new Error(
                'ExoClick delivery configuration is missing.'
            );
        }

        const driverName =
            String(
                delivery.ad_driver
                ?? ''
            )
                .trim()
                .toLowerCase();

        if (
            driverName !==
            EXOCLICK_DRIVER_NAME
        ) {
            throw new Error(
                'The monetization decision is not configured for ExoClick.'
            );
        }

        const zoneId =
            String(
                delivery.public_placement_id
                ?? ''
            ).trim();

        if (
            !/^[1-9][0-9]*$/.test(
                zoneId
            )
        ) {
            throw new Error(
                'A valid ExoClick zone ID is required.'
            );
        }

        const publicConfig =
            (
                delivery.public_config &&
                typeof delivery.public_config ===
                    'object' &&
                !Array.isArray(
                    delivery.public_config
                )
            )
                ? delivery.public_config
                : {};

        const width =
            this.positiveInteger(
                publicConfig.width
            );

        const height =
            this.positiveInteger(
                publicConfig.height
            );

        const wrapper =
            document.createElement(
                'div'
            );

        wrapper.setAttribute(
            'data-xurvexa-exoclick-banner',
            'true'
        );

        wrapper.style.width =
            '100%';

        wrapper.style.display =
            'flex';

        wrapper.style.justifyContent =
            'center';

        wrapper.style.alignItems =
            'center';

        wrapper.style.overflow =
            'hidden';

        if (width !== null) {
            wrapper.style.maxWidth =
                `${width}px`;
        }

        if (height !== null) {
            wrapper.style.minHeight =
                `${height}px`;
        }

        const placement =
            document.createElement(
                'ins'
            );

        placement.className =
            EXOCLICK_BANNER_CLASS;

        placement.setAttribute(
            'data-zoneid',
            zoneId
        );

        placement.style.display =
            'block';

        placement.style.maxWidth =
            '100%';

        wrapper.appendChild(
            placement
        );

        slot.replaceChildren(
            wrapper
        );

        this.ensureProviderQueue();

        this.ensureProviderScript();

        window.AdProvider.push({
            serve: {},
        });

        return true;
    }


    positiveInteger(
        value
    ) {
        const parsed =
            Number.parseInt(
                String(
                    value
                    ?? ''
                ),
                10
            );

        if (
            !Number.isInteger(parsed) ||
            parsed < 1 ||
            parsed > 4096
        ) {
            return null;
        }

        return parsed;
    }


    ensureProviderQueue() {
        const provider =
            window.AdProvider;

        if (
            provider &&
            typeof provider.push ===
                'function'
        ) {
            return;
        }

        window.AdProvider = [];
    }


    ensureProviderScript() {
        const existing =
            document.getElementById(
                EXOCLICK_PROVIDER_SCRIPT_ID
            );

        if (existing) {
            return existing;
        }

        const script =
            document.createElement(
                'script'
            );

        script.id =
            EXOCLICK_PROVIDER_SCRIPT_ID;

        script.type =
            'application/javascript';

        script.async =
            true;

        script.src =
            EXOCLICK_PROVIDER_SCRIPT_URL;

        document.head.appendChild(
            script
        );

        return script;
    }
}


function registerXurvexaExoClickBannerDriver(
    renderer
) {
    if (!renderer) {
        return null;
    }

    if (
        typeof renderer.registerDriver !==
        'function'
    ) {
        return null;
    }

    if (
        typeof renderer.hasDriver ===
            'function' &&
        renderer.hasDriver(
            EXOCLICK_DRIVER_NAME
        )
    ) {
        return null;
    }

    const driver =
        new XurvexaExoClickBannerDriver();

    renderer.registerDriver(
        EXOCLICK_DRIVER_NAME,
        driver
    );

    window.XurvexaExoClickBannerDriver =
        driver;

    return driver;
}


function bootXurvexaExoClickBannerDriver() {
    const renderer =
        window.XurvexaBannerRenderer
        ?? null;

    if (renderer) {
        registerXurvexaExoClickBannerDriver(
            renderer
        );

        return;
    }

    window.addEventListener(
        'xurvexa:banner-renderer-ready',
        (event) => {
            registerXurvexaExoClickBannerDriver(
                event.detail?.renderer
                ?? null
            );
        },
        {
            once: true,
        }
    );
}


bootXurvexaExoClickBannerDriver();


export {
    XurvexaExoClickBannerDriver,
    registerXurvexaExoClickBannerDriver,
};
