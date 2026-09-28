import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Octane (roadmap step 5): the Storefront demo site (UiDemoSeeder) runs Octane on FrankenPHP — proxied on app-1,
 * still starting on app-2. Checks the canvas badge, the panel header badge and Settings → Laravel (server select,
 * port, per-server routing state), in both themes. Changes nothing.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `octane-${name}.png`) });
}

for (const theme of ['dark', 'light'] as const) {
    test(`octane walkthrough (${theme})`, async ({ browser, baseURL }) => {
        const origin = new URL(baseURL ?? '').origin;
        const context = await browser.newContext({
            baseURL,
            ignoreHTTPSErrors: true,
            viewport: { width: 1440, height: 900 },
            storageState: existsSync(storageStatePath) ? storageStatePath : undefined,
            colorScheme: theme,
            reducedMotion: 'reduce',
        });
        await context.addCookies([{ name: 'appearance', value: theme, url: origin }]);
        const page = await context.newPage();
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(`Uncaught: ${error.message}`));
        page.on('console', (message) => {
            if (
                message.type() === 'error' &&
                !/Failed to load resource/.test(message.text()) &&
                !globalAllowedConsole.some((pattern) => pattern.test(message.text()))
            ) {
                errors.push(message.text());
            }
        });

        // Canvas: the Storefront card carries the Octane badge.
        await page.goto('/projects', { waitUntil: 'networkidle' });
        const project = await page.getByRole('link', { name: 'Default', exact: true }).getAttribute('href');
        expect(project).toBeTruthy();
        await page.goto(project!, { waitUntil: 'networkidle' });
        const card = page.getByText('Storefront', { exact: true }).first();
        await expect(card).toBeVisible();
        await expect(page.getByText('Octane', { exact: true }).first()).toBeVisible();
        await shot(page, theme, 'canvas');

        // Panel → Settings → Laravel: server select, port and per-server routing state.
        await card.click();
        await page.waitForURL(/\/service\/site\//);
        await page.goto(page.url().replace(/\/service\/site\/([^/]+).*$/, '/service/site/$1/settings'), { waitUntil: 'networkidle' });
        const octane = page.locator('#settings-laravel section', { has: page.getByRole('heading', { name: 'Octane', exact: true }) });
        await octane.scrollIntoViewIfNeeded();
        await expect(page.getByRole('combobox', { name: 'Octane server' })).toContainText('FrankenPHP');
        await expect(octane.getByText('127.0.0.1:8412')).toBeVisible();
        await expect(octane.getByText('127.0.0.1:18412')).toBeVisible();
        await expect(octane.getByText('Listening')).toBeVisible();
        await expect(octane.getByText('Starting')).toBeVisible();
        await shot(page, theme, 'settings-laravel');

        await page.getByRole('combobox', { name: 'Octane server' }).click();
        await expect(page.getByRole('option', { name: /Swoole/ })).toBeVisible();
        await shot(page, theme, 'settings-server-select');
        await page.keyboard.press('Escape');

        expect(errors).toEqual([]);
        await context.close();
    });
}
