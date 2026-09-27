import { expect, test } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';

/**
 * The service panel's observability tabs (Telemetry's Metrics / Logs, Insights' Observability) on the demo Storefront.
 * Backends that aren't running must render an explanatory state, never an uncaught error.
 */
for (const theme of ['dark', 'light'] as const) {
    test(`${theme} · service panel observability tabs`, async ({ browser, baseURL }) => {
        const context = await browser.newContext({
            baseURL,
            ignoreHTTPSErrors: true,
            viewport: { width: 1440, height: 900 },
            storageState: existsSync(storageStatePath) ? storageStatePath : undefined,
            colorScheme: theme,
            reducedMotion: 'reduce',
        });
        await context.addCookies([{ name: 'appearance', value: theme, url: new URL(baseURL ?? '').origin }]);
        const page = await context.newPage();
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));

        await page.goto('/projects', { waitUntil: 'networkidle' });
        await page.locator('a[href$="/production"]').first().click();
        await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
        await page.waitForLoadState('networkidle');
        await page.locator('.react-flow__node').first().waitFor({ timeout: 10_000 }).catch(() => undefined);
        const storefront = page.getByRole('group', { name: /^Storefront:/ });
        test.skip((await storefront.count()) === 0, 'no demo data');
        await storefront.click();
        await page.waitForURL(/\/service\/site\//);

        mkdirSync(join(import.meta.dirname, 'screenshots', theme), { recursive: true });
        for (const name of ['Metrics', 'Logs', 'Observability']) {
            await page.getByRole('tab', { name, exact: true }).click();
            await page.waitForURL(new RegExp(`/${name.toLowerCase()}$`));
            await page.waitForLoadState('networkidle');
            // Each tab settles into content or a teaching state (no skeletons left, no error boundary).
            const panel = page.getByRole('tabpanel');
            await expect(panel.locator('.animate-pulse')).toHaveCount(0, { timeout: 15_000 });
            await expect(panel.locator('[aria-busy="true"]')).toHaveCount(0);
            await page.screenshot({ path: join(import.meta.dirname, 'screenshots', theme, `panel-${name.toLowerCase()}.png`) });
        }
        expect(errors).toEqual([]);
        await context.close();
    });
}

for (const theme of ['dark', 'light'] as const) {
    test(`${theme} · notification bell popover`, async ({ browser, baseURL }) => {
        const context = await browser.newContext({
            baseURL,
            ignoreHTTPSErrors: true,
            viewport: { width: 1440, height: 900 },
            storageState: existsSync(storageStatePath) ? storageStatePath : undefined,
            colorScheme: theme,
            reducedMotion: 'reduce',
        });
        await context.addCookies([{ name: 'appearance', value: theme, url: new URL(baseURL ?? '').origin }]);
        const page = await context.newPage();
        await page.goto('/observability/alerts', { waitUntil: 'networkidle' });

        const bell = page.getByRole('button', { name: /^Notifications/ });
        await bell.click();
        await expect(page.getByRole('menu')).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'View all notifications' })).toBeVisible();

        mkdirSync(join(import.meta.dirname, 'screenshots', theme), { recursive: true });
        await page.screenshot({ path: join(import.meta.dirname, 'screenshots', theme, 'notification-bell.png') });
        await context.close();
    });
}
