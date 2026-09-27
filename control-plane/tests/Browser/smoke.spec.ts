import { expect, test, type Browser, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole, globalAllowedFailures, params, routes, slugFor, type BrowserRoute } from './routes';

const THEMES = ['dark', 'light'] as const;
const WIDTHS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
] as const;
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

/** Resolve `:param` placeholders once per worker by scraping the listing pages. */
const resolved = new Map<string, string | null>();

async function resolveParam(browser: Browser, name: string, baseURL: string): Promise<string | null> {
    if (resolved.has(name)) return resolved.get(name) ?? null;
    const source = params[name];
    if (!source) throw new Error(`Unknown route param :${name} — add it to params in tests/Browser/routes.ts`);

    // `from` may itself contain params (e.g. the site is found on a server page).
    const from = source.from.includes(':') ? await resolvePath(browser, source.from, baseURL) : source.from;
    if (from === null) {
        resolved.set(name, null);

        return null;
    }

    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, storageState: storageStatePath });
    const page = await context.newPage();
    await page.goto(from, { waitUntil: 'networkidle' });
    const anchors = await page
        .locator('a[href]')
        .evaluateAll((elements) => elements.map((a) => ({ path: new URL((a as HTMLAnchorElement).href).pathname, text: a.textContent ?? '' })));
    await context.close();

    const value =
        anchors
            .filter((anchor) => !source.text || source.text.test(anchor.text))
            .map((anchor) => source.match.exec(anchor.path)?.[1])
            .find(Boolean) ?? null;
    resolved.set(name, value);

    return value;
}

async function resolvePath(browser: Browser, path: string, baseURL: string): Promise<string | null> {
    let result = path;
    for (const [, name] of path.matchAll(/:([a-z]+)/gi)) {
        const value = await resolveParam(browser, name, baseURL);
        if (!value) return null;
        result = result.replace(`:${name}`, value);
    }

    return result;
}

interface Problems {
    console: string[];
    requests: string[];
}

function watch(page: Page, route: BrowserRoute, origin: string): Problems {
    const problems: Problems = { console: [], requests: [] };
    const allowedRequests = [...globalAllowedFailures, ...(route.allowedFailures ?? [])];
    const allowedConsole = [...globalAllowedConsole, ...(route.allowedConsole ?? [])];

    page.on('console', (message) => {
        if (message.type() !== 'error') return;
        const text = message.text();
        // Failed responses are reported (with their URL) below; the console echo carries no URL.
        if (/Failed to load resource/.test(text)) return;
        if (!allowedConsole.some((pattern) => pattern.test(text))) problems.console.push(text);
    });
    page.on('pageerror', (error) => problems.console.push(`Uncaught: ${error.message}`));
    page.on('response', (response) => {
        const url = response.url();
        if (response.status() >= 400 && url.startsWith(origin) && !allowedRequests.some((pattern) => pattern.test(url))) {
            problems.requests.push(`${response.status()} ${response.request().method()} ${url}`);
        }
    });
    page.on('requestfailed', (request) => {
        const failure = request.failure()?.errorText ?? '';
        const url = request.url();
        // Navigations/prefetches cancelled by the next navigation are not failures.
        if (/ERR_ABORTED|NS_BINDING_ABORTED|cancelled/i.test(failure)) return;
        if (url.startsWith(origin) && !allowedRequests.some((pattern) => pattern.test(url))) problems.requests.push(`${failure} ${url}`);
    });

    return problems;
}

for (const theme of THEMES) {
    test.describe(`${theme} theme`, () => {
        for (const route of routes) {
            test(`${route.path}`, async ({ browser, baseURL }) => {
                const base = baseURL ?? '';
                const origin = new URL(base).origin;
                const path = await resolvePath(browser, route.path, base);
                test.skip(path === null, `no data to resolve ${route.path}`);

                for (const viewport of WIDTHS) {
                    const context = await browser.newContext({
                        baseURL: base,
                        ignoreHTTPSErrors: true,
                        viewport: { width: viewport.width, height: viewport.height },
                        storageState: route.guest || !existsSync(storageStatePath) ? undefined : storageStatePath,
                        colorScheme: theme,
                        reducedMotion: 'reduce',
                    });
                    await context.addCookies([{ name: 'appearance', value: theme, url: origin }]);
                    const page = await context.newPage();
                    const problems = watch(page, route, origin);

                    const response = await page.goto(path!, { waitUntil: 'networkidle' });
                    expect(response?.status(), `HTTP status of ${path}`).toBeLessThan(400);
                    if (!route.guest) {
                        expect(new URL(page.url()).pathname, `${path} redirected to login`).not.toBe('/login');
                    }
                    await expect(page.locator('html')).toHaveClass(new RegExp(`\\b${theme}\\b`));
                    await page.evaluate(() => document.fonts.ready);

                    const overflow = await page.evaluate(() => {
                        const root = document.documentElement;
                        if (root.scrollWidth <= root.clientWidth + 1) return null;
                        const offenders = [...document.querySelectorAll('body *')]
                            .filter((element) => element.getBoundingClientRect().right > root.clientWidth + 1)
                            .slice(0, 5)
                            .map(
                                (element) =>
                                    `${element.tagName.toLowerCase()}.${String((element as HTMLElement).className)
                                        .split(' ')
                                        .slice(0, 4)
                                        .join('.')}`,
                            );

                        return { scrollWidth: root.scrollWidth, clientWidth: root.clientWidth, offenders };
                    });

                    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
                    const suffix = viewport.name === 'desktop' ? '' : `.${viewport.name}`;
                    await page.screenshot({ path: join(SCREENSHOTS, theme, `${slugFor(route.path)}${suffix}.png`), fullPage: true });

                    expect.soft(problems.console, `console errors on ${path} @${viewport.width}`).toEqual([]);
                    expect.soft(problems.requests, `failed requests on ${path} @${viewport.width}`).toEqual([]);
                    expect.soft(overflow, `horizontal overflow on ${path} @${viewport.width}`).toBeNull();

                    await context.close();
                }
            });
        }
    });
}
