const ADSTERRA_DRIVER_NAME =
    'adsterra';

const ADSTERRA_SCRIPT_HOST =
    'https://www.highrevenueformat.com';

const ADSTERRA_FRAME_LOAD_TIMEOUT_MS =
    10000;



class XurvexaAdsterraBannerDriver {
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
                'A valid Adsterra banner slot is required.'
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
                'Adsterra delivery configuration is missing.'
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
            ADSTERRA_DRIVER_NAME
        ) {
            throw new Error(
                'The monetization decision is not configured for Adsterra.'
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

        const tagKey =
            String(
                publicConfig.tag_key
                ?? ''
            )
                .trim()
                .toLowerCase();

        if (
            !/^[a-f0-9]{32}$/.test(
                tagKey
            )
        ) {
            throw new Error(
                'A valid Adsterra public tag key is required.'
            );
        }

        const width =
            this.positiveInteger(
                publicConfig.width
            )
            ?? 300;

        const height =
            this.positiveInteger(
                publicConfig.height
            )
            ?? 250;

        const wrapper =
            document.createElement(
                'div'
            );

        wrapper.setAttribute(
            'data-xurvexa-adsterra-banner',
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

        wrapper.style.maxWidth =
            `${width}px`;

        wrapper.style.minHeight =
            `${height}px`;

        const frame =
            document.createElement(
                'iframe'
            );

        frame.setAttribute(
            'title',
            'Advertisement'
        );

        frame.setAttribute(
            'width',
            String(width)
        );

        frame.setAttribute(
            'height',
            String(height)
        );

        frame.setAttribute(
            'scrolling',
            'no'
        );

        frame.setAttribute(
            'frameborder',
            '0'
        );

        frame.setAttribute(
            'aria-label',
            'Advertisement'
        );

        /*
         * The provider tag is isolated from the Xurvexa
         * parent document. It may execute scripts and open
         * ad-click popups, but it does not receive a
         * same-origin relationship with the parent page.
         */
        frame.setAttribute(
            'sandbox',
            [
                'allow-scripts',
                'allow-forms',
                'allow-popups',
                'allow-popups-to-escape-sandbox',
            ].join(' ')
        );

        frame.referrerPolicy =
            'strict-origin-when-cross-origin';

        frame.style.display =
            'block';

        frame.style.border =
            '0';

        frame.style.width =
            `${width}px`;

        frame.style.height =
            `${height}px`;

        frame.style.maxWidth =
            '100%';

        const scriptUrl =
            `${ADSTERRA_SCRIPT_HOST}/${tagKey}/invoke.js`;

        frame.srcdoc =
            this.frameDocument(
                tagKey,
                scriptUrl,
                width,
                height
            );

        wrapper.appendChild(
            frame
        );

        slot.replaceChildren(
            wrapper
        );

        await this.waitForFrameLoad(
            frame
        );

        return true;
    }



    frameDocument(
        tagKey,
        scriptUrl,
        width,
        height
    ) {
        const safeKey =
            JSON.stringify(
                tagKey
            );

        const safeUrl =
            this.escapeHtmlAttribute(
                scriptUrl
            );

        return [
            '<!doctype html>',
            '<html>',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<style>',
            'html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;background:transparent;}',
            'body{display:flex;align-items:center;justify-content:center;}',
            '</style>',
            '</head>',
            '<body>',
            '<script>',
            'window.atOptions = {',
            `key: ${safeKey},`,
            "format: 'iframe',",
            `height: ${height},`,
            `width: ${width},`,
            'params: {}',
            '};',
            '</script>',
            `<script src="${safeUrl}"></script>`,
            '</body>',
            '</html>',
        ].join('');
    }



    waitForFrameLoad(
        frame
    ) {
        return new Promise(
            (
                resolve,
                reject
            ) => {
                let settled =
                    false;

                const finish =
                    (callback) => {
                        if (settled) {
                            return;
                        }

                        settled =
                            true;

                        window.clearTimeout(
                            timeoutId
                        );

                        callback();
                    };

                const timeoutId =
                    window.setTimeout(
                        () => {
                            finish(
                                () => {
                                    reject(
                                        new Error(
                                            'Adsterra banner frame load timed out.'
                                        )
                                    );
                                }
                            );
                        },
                        ADSTERRA_FRAME_LOAD_TIMEOUT_MS
                    );

                frame.addEventListener(
                    'load',
                    () => {
                        finish(
                            resolve
                        );
                    },
                    {
                        once: true,
                    }
                );

                frame.addEventListener(
                    'error',
                    () => {
                        finish(
                            () => {
                                reject(
                                    new Error(
                                        'Adsterra banner frame failed to load.'
                                    )
                                );
                            }
                        );
                    },
                    {
                        once: true,
                    }
                );
            }
        );
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



    escapeHtmlAttribute(
        value
    ) {
        return String(value)
            .replaceAll(
                '&',
                '&amp;'
            )
            .replaceAll(
                '"',
                '&quot;'
            )
            .replaceAll(
                '<',
                '&lt;'
            )
            .replaceAll(
                '>',
                '&gt;'
            );
    }
}



function registerXurvexaAdsterraBannerDriver(
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
            ADSTERRA_DRIVER_NAME
        )
    ) {
        return null;
    }

    const driver =
        new XurvexaAdsterraBannerDriver();

    renderer.registerDriver(
        ADSTERRA_DRIVER_NAME,
        driver
    );

    window.XurvexaAdsterraBannerDriver =
        driver;

    return driver;
}



function bootXurvexaAdsterraBannerDriver() {
    const renderer =
        window.XurvexaBannerRenderer
        ?? null;

    if (renderer) {
        registerXurvexaAdsterraBannerDriver(
            renderer
        );

        return;
    }

    window.addEventListener(
        'xurvexa:banner-renderer-ready',
        (event) => {
            registerXurvexaAdsterraBannerDriver(
                event.detail?.renderer
                ?? null
            );
        },
        {
            once: true,
        }
    );
}



bootXurvexaAdsterraBannerDriver();



export {
    XurvexaAdsterraBannerDriver,
    registerXurvexaAdsterraBannerDriver,
};
