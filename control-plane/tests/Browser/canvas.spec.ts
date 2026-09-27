import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Interactive canvas walkthrough (docs/UI_DESIGN.md §4–§5): Create picker, service panel Deployments tab, Deploy view
 * and the database panel, in both themes. Needs demo data (UiDemoSeeder); skips when the canvas has no services.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250); // let panel / picker animations settle
    await page.screenshot({ path: join(SCREENSHOTS, theme, `canvas-${name}.png`) });
}

for (const theme of ['dark', 'light'] as const) {
    test(`canvas walkthrough (${theme})`, async ({ browser, baseURL }) => {
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
            if (message.type() === 'error' && !/Failed to load resource/.test(message.text()) && !globalAllowedConsole.some((pattern) => pattern.test(message.text()))) {
                errors.push(message.text());
            }
        });

        await page.goto('/projects', { waitUntil: 'networkidle' });
        await page.locator('a[href$="/production"]').first().click();
        await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
        await page.waitForLoadState('networkidle');
        const cards = page.locator('.react-flow__node');
        test.skip((await cards.count()) === 0, 'no demo services on the canvas');
        await shot(page, theme, 'board');

        // Create picker
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(page.getByRole('dialog', { name: 'Create' })).toBeVisible();
        await shot(page, theme, 'create-picker');
        await page.getByRole('option', { name: /Database/ }).click();
        await page.getByRole('button', { name: /PostgreSQL/ }).click();
        await expect(page.getByRole('button', { name: 'Create database' })).toBeVisible();
        await shot(page, theme, 'create-database');
        await page.getByRole('button', { name: 'Back' }).click();
        await page.getByRole('option', { name: /Git repository/ }).click();
        await page.waitForLoadState('networkidle');
        await shot(page, theme, 'create-git');
        await page.keyboard.press('Escape');

        // Site panel: Deployments tab
        await page.getByRole('group', { name: /^Storefront:/ }).click();
        await page.waitForURL(/\/service\/site\//);
        await expect(page.getByText('In progress')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await shot(page, theme, 'panel-deployments');

        // Deploy view of the failed deployment
        await page.getByRole('button', { name: /#1/ }).first().click();
        await expect(page.getByTestId('deploy-view')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await shot(page, theme, 'deploy-view');
        await page.getByRole('tab', { name: /Deploy logs/ }).click();
        await shot(page, theme, 'deploy-view-logs');

        // A pending (placeholder) tab
        await page.getByRole('tab', { name: 'Variables' }).click();
        await expect(page.getByTestId('pending-tab-variables')).toBeVisible();
        await shot(page, theme, 'panel-pending');

        await page.keyboard.press('Escape');
        await page.waitForURL(/\/production$/);

        // Database panel
        await page.getByRole('group', { name: /^storefront_db:/ }).click();
        await expect(page.getByTestId('connection-url')).toBeVisible();
        await shot(page, theme, 'db-overview');
        for (const [tab, name, ready] of [
            ['Databases & users', 'db-users', 'Users'],
            ['Backups', 'db-backups', 'Schedules'],
            ['Settings', 'db-settings', 'Danger zone'],
        ]) {
            await page.getByRole('tab', { name: tab }).click();
            await expect(page.getByRole('heading', { name: ready })).toBeVisible();
            await shot(page, theme, name);
        }

        expect.soft(errors, 'console errors').toEqual([]);
        await context.close();
    });
}
