import test from 'node:test';
import assert from 'node:assert/strict';

class FakeHTMLElement {
    constructor() {
        this.children = [];
    }

    replaceChildren(
        ...children
    ) {
        this.children = [
            ...children,
        ];
    }
}

class FakeButtonElement extends FakeHTMLElement {
    constructor() {
        super();

        this.type = '';
        this.textContent = '';

        this.attributes = {};
        this.style = {};

        this.listeners =
            new Map();
    }

    setAttribute(
        name,
        value
    ) {
        this.attributes[name] =
            String(value);
    }

    addEventListener(
        type,
        listener
    ) {
        this.listeners.set(
            type,
            listener
        );
    }

    click() {
        const listener =
            this.listeners.get(
                'click'
            );

        if (listener) {
            listener();
        }
    }
}

globalThis.HTMLElement =
    FakeHTMLElement;

globalThis.document = {
    createElement(
        tagName
    ) {
        if (
            tagName === 'button'
        ) {
            return new FakeButtonElement();
        }

        throw new Error(
            `Unsupported test element: ${tagName}`
        );
    },
};

const {
    XurvexaBannerTestDriver,
    registerXurvexaBannerTestDriver,
} = await import(
    '../../resources/js/video-banner-test-driver.js'
);

test(
    'test driver module does not automatically register itself',
    () => {
        assert.equal(
            typeof XurvexaBannerTestDriver,
            'function'
        );

        assert.equal(
            typeof registerXurvexaBannerTestDriver,
            'function'
        );
    }
);

test(
    'register helper registers test driver with renderer',
    () => {
        const calls = [];

        const renderer = {
            registerDriver(
                name,
                driver
            ) {
                calls.push({
                    name,
                    driver,
                });
            },
        };

        const driver =
            registerXurvexaBannerTestDriver(
                renderer
            );

        assert.ok(
            driver
            instanceof XurvexaBannerTestDriver
        );

        assert.equal(
            calls.length,
            1
        );

        assert.equal(
            calls[0].name,
            'test'
        );

        assert.equal(
            calls[0].driver,
            driver
        );
    }
);

test(
    'register helper returns null without renderer',
    () => {
        const result =
            registerXurvexaBannerTestDriver(
                null
            );

        assert.equal(
            result,
            null
        );
    }
);

test(
    'test driver renders local banner creative',
    async () => {
        const slot =
            new FakeHTMLElement();

        const renderer = {
            async recordClick() {
                return {
                    ok: true,
                };
            },
        };

        const driver =
            new XurvexaBannerTestDriver();

        const result =
            await driver.render(
                slot,
                {
                    renderer,
                }
            );

        assert.equal(
            result,
            true
        );

        assert.equal(
            slot.children.length,
            1
        );

        const creative =
            slot.children[0];

        assert.ok(
            creative
            instanceof FakeButtonElement
        );

        assert.equal(
            creative.type,
            'button'
        );

        assert.equal(
            creative.textContent,
            'Xurvexa Test Banner'
        );

        assert.equal(
            creative.attributes[
                'data-xurvexa-test-banner'
            ],
            'true'
        );
    }
);

test(
    'test banner click delegates tracking to renderer',
    async () => {
        const slot =
            new FakeHTMLElement();

        const calls = [];

        const renderer = {
            async recordClick(
                suppliedSlot
            ) {
                calls.push(
                    suppliedSlot
                );

                return {
                    ok: true,
                };
            },
        };

        const driver =
            new XurvexaBannerTestDriver();

        await driver.render(
            slot,
            {
                renderer,
            }
        );

        const creative =
            slot.children[0];

        creative.click();

        await new Promise(
            (resolve) => {
                setImmediate(
                    resolve
                );
            }
        );

        assert.equal(
            calls.length,
            1
        );

        assert.equal(
            calls[0],
            slot
        );
    }
);

test(
    'test driver rejects invalid slot',
    async () => {
        const driver =
            new XurvexaBannerTestDriver();

        await assert.rejects(
            driver.render(
                null,
                {
                    renderer: {},
                }
            ),
            {
                message:
                    'A valid banner slot is required.',
            }
        );
    }
);

test(
    'test driver rejects missing renderer',
    async () => {
        const driver =
            new XurvexaBannerTestDriver();

        const slot =
            new FakeHTMLElement();

        await assert.rejects(
            driver.render(
                slot,
                {
                    renderer: null,
                }
            ),
            {
                message:
                    'A banner renderer instance is required.',
            }
        );
    }
);

test(
    'click tracking failure does not throw into browser interaction',
    async () => {
        const slot =
            new FakeHTMLElement();

        const renderer = {
            async recordClick() {
                throw new Error(
                    'tracking unavailable'
                );
            },
        };

        const driver =
            new XurvexaBannerTestDriver();

        await driver.render(
            slot,
            {
                renderer,
            }
        );

        const creative =
            slot.children[0];

        assert.doesNotThrow(
            () => {
                creative.click();
            }
        );

        await new Promise(
            (resolve) => {
                setImmediate(
                    resolve
                );
            }
        );
    }
);
