import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Redis instances (docs/plans/REDIS.md): create one from the canvas picker on Platform's staging canvas (Database →
 * Redis, instance name, Advanced memory / eviction) on app-1, whose demo agent advertises db.redis; then the demo "sessions" instance's panel — the
 * Overview connect card (redis:// URL, .env, redis-cli on the server, REDIS_* references), Rotate password and the
 * Settings tab (memory, eviction, persistence with the "none" warning). Needs demo data (UiDemoSeeder). There is no real
 * agent behind the demo server: the new instance stays pending, the rotation and the settings are queued for app-1.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `redis-${name}.png`) });
}

for (const theme of ['dark', 'light'] as const) {
    test(`redis instance walkthrough (${theme})`, async ({ browser, baseURL }) => {
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

        await page.goto('/projects', { waitUntil: 'networkidle' });
        const projects = page.url();
        await page.getByRole('link', { name: 'Default', exact: true }).click();
        await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
        await page.waitForLoadState('networkidle');
        test.skip((await page.getByRole('group', { name: /^sessions:/ }).count()) === 0, 'no demo Redis instance on the canvas');
        const canvas = page.url();

        // Create picker, on Platform's empty staging canvas (new cards would cover the Default canvas the other specs
        // click, and the dashboard spec expects Platform's production to be empty): Database → Redis, an instance name,
        // Advanced memory / eviction, on app-1.
        await page.goto(projects, { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Platform', exact: true }).click();
        await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
        await page.goto(page.url().replace(/\/production$/, '/staging'), { waitUntil: 'networkidle' });
        const name = `cache-${theme}-${Date.now().toString(36)}`;
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await page.getByRole('option', { name: /Database/ }).click();
        const redis = page.getByRole('button', { name: /^Redis/ });
        await expect(redis).toContainText('Installed', { timeout: 15_000 }); // the engine list loads with the step
        await redis.click();
        await expect(redis).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByLabel('Instance name')).toHaveValue('cache');
        await page.getByLabel('Instance name').fill(name);
        await page.getByRole('combobox', { name: 'Server' }).click();
        await page.getByRole('option', { name: /^app-1/ }).click();
        await page.getByRole('button', { name: 'Advanced' }).click();
        await page.getByLabel('Memory limit (MB)').fill('64');
        await page.getByRole('combobox', { name: 'Eviction' }).click();
        await page.getByRole('option', { name: /^allkeys-lru/ }).click();
        await shot(page, theme, 'create');
        await page.getByRole('button', { name: 'Create Redis' }).click();

        // The new instance opens in its panel, pending until the agent confirms.
        await expect(page.getByTestId('service-panel')).toBeVisible();
        await expect(page.getByText(`Creating ${name} on app-1…`)).toBeVisible();
        await expect(page.getByRole('group', { name: new RegExp(`^${name}:`) })).toBeVisible();
        await shot(page, theme, 'created');
        await page.goto(canvas, { waitUntil: 'networkidle' });

        // The demo instance: Overview connect card.
        await page.getByRole('group', { name: /^sessions:/ }).click();
        await expect(page.getByTestId('service-panel')).toBeVisible();
        await expect(page.getByTestId('connection-url')).toContainText(/redis:\/\/default:.+:6380/);
        await expect(page.getByText('REDIS_HOST=', { exact: false }).first()).toBeVisible();
        await expect(page.getByText('On app-1', { exact: true })).toBeVisible();
        await expect(page.getByText(/redis-cli -p 6380/).first()).toBeVisible();
        for (const key of ['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD']) {
            await expect(page.getByText(`\${{ sessions.${key} }}`, { exact: true })).toBeVisible();
        }
        await expect(page.getByRole('tab', { name: 'Databases & users' })).toHaveCount(0);
        await shot(page, theme, 'overview');

        // Rotate password: applied to the instance (queued for app-1), the reveal is reset.
        await page.getByRole('button', { name: 'Rotate password' }).click();
        await expect(page.getByText('Password rotated')).toBeVisible();
        await shot(page, theme, 'rotated');

        // Settings: memory, eviction, persistence (with the "none" warning), engine version only (no port).
        await page.getByRole('tab', { name: 'Settings' }).click();
        await expect(page.getByRole('heading', { name: 'Instance' })).toBeVisible();
        await expect(page.getByLabel('Memory limit (MB)')).toHaveValue(/^\d+$/); // 256 in the demo data, until a run saves another
        await expect(page.getByRole('heading', { name: 'Engine' })).toBeVisible();
        await expect(page.getByLabel('Port', { exact: true })).toHaveCount(0);
        await page.getByRole('combobox', { name: 'Persistence' }).click();
        await page.getByRole('option', { name: /^None/ }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Nothing is written to disk' })).toBeVisible();
        await shot(page, theme, 'settings-none');
        await page.getByRole('combobox', { name: 'Persistence' }).click();
        await page.getByRole('option', { name: /^Append-only/ }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Nothing is written to disk' })).toHaveCount(0);
        const memory = page.getByLabel('Memory limit (MB)');
        await memory.fill((await memory.inputValue()) === '512' ? '384' : '512');
        await page.getByRole('button', { name: 'Save', exact: true }).first().click();
        await expect(page.getByText('Instance settings saved')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Delete instance' })).toBeVisible();
        await shot(page, theme, 'settings');

        // Databases index: app-1's Redis row lists its instances' ports, not the stock 6379.
        await page.goto('/databases', { waitUntil: 'networkidle' });
        const row = page.getByRole('row').filter({ hasText: 'app-1' }).filter({ hasText: 'Redis' });
        await expect(row).toContainText('6380');
        await expect(row).not.toContainText('6379');
        await shot(page, theme, 'index');

        expect.soft(errors, 'console errors').toEqual([]);
        await context.close();
    });
}
