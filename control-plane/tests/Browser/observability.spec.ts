import { expect, test } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';

/**
 * Isolated render test for the service-panel tab components (SiteMetrics, SiteLogs, SiteObservability) via the
 * local-only gallery /dev/observability-panels. Skipped where the gallery isn't registered (non-local envs).
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

        const response = await page.goto('/dev/observability-panels', { waitUntil: 'networkidle' });
        test.skip(response?.status() === 404, 'gallery is local-only');

        for (const name of ['Metrics', 'Logs', 'Observability']) {
            await expect(page.getByRole('region', { name, exact: true })).toBeVisible();
        }
        // Each tab settles into content or a teaching state (no skeletons left, no error boundary).
        await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);

        mkdirSync(join(import.meta.dirname, 'screenshots', theme), { recursive: true });
        await page.screenshot({ path: join(import.meta.dirname, 'screenshots', theme, 'dev-observability-panels.png'), fullPage: true });
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
