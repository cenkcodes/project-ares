class XurvexaBannerTestDriver {
    async render(
        slot,
        {
            renderer,
        }
    ) {
        if (
            !(slot instanceof HTMLElement)
        ) {
            throw new Error(
                'A valid banner slot is required.'
            );
        }

        if (!renderer) {
            throw new Error(
                'A banner renderer instance is required.'
            );
        }

        const creative =
            document.createElement(
                'button'
            );

        creative.type =
            'button';

        creative.textContent =
            'Xurvexa Test Banner';

        creative.setAttribute(
            'data-xurvexa-test-banner',
            'true'
        );

        creative.style.width =
            '100%';

        creative.style.minHeight =
            '90px';

        creative.style.border =
            '1px solid #333';

        creative.style.borderRadius =
            '8px';

        creative.style.background =
            '#171717';

        creative.style.color =
            '#fff';

        creative.style.fontSize =
            '14px';

        creative.style.fontWeight =
            '700';

        creative.style.cursor =
            'pointer';

        creative.addEventListener(
            'click',
            () => {
                renderer
                    .recordClick(
                        slot
                    )
                    .catch(
                        () => {
                            /*
                             * Click tracking failure must
                             * never block the browser UI.
                             */
                        }
                    );
            }
        );

        slot.replaceChildren(
            creative
        );

        return true;
    }
}

function registerXurvexaBannerTestDriver(
    renderer
) {
    if (!renderer) {
        return null;
    }

    const driver =
        new XurvexaBannerTestDriver();

    renderer.registerDriver(
        'test',
        driver
    );

    return driver;
}

export {
    XurvexaBannerTestDriver,
    registerXurvexaBannerTestDriver,
};
