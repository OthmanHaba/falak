import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Machine check (v0.6.0): the demo "customer-vm" (UiDemoSeeder → InfrastructureDemoSeeder) needs attention — nginx
 * holds port 80 and MariaDB is installed where MySQL was chosen; Docker from Docker's repository is adopted. Checks the
 * fleet filter, the badge, the panel (blocks first, decisions, fix hints) and that Provision stays disabled, in both
 * themes. Changes nothing (Re-check is not clicked: there is no real agent behind the demo server).
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `machine-check-${name}.png`), fullPage: true });
}

for (const theme of ['dark', 'light'] as const) {
    test(`machine check needs attention (${theme})`, async ({ browser, baseURL }) => {
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

        // Fleet: the server shows "Needs attention" and the status filter finds it.
        await page.goto('/servers?status=needs_attention', { waitUntil: 'networkidle' });
        const row = page.getByRole('link', { name: 'customer-vm' }).first();
        await expect(row).toBeVisible();
        await expect(page.getByText('Needs attention').first()).toBeVisible();

        // Server page: the panel is expanded above the fold, blocks first.
        await row.click();
        await page.waitForURL(/\/servers\/[0-9A-Za-z]{26}$/);
        await page.waitForLoadState('networkidle');
        const panel = page.locator('section#machine-check');
        await expect(panel.getByRole('heading', { name: 'Machine check' })).toBeVisible();
        await expect(panel.getByText('Nothing was installed')).toBeVisible();

        const rows = panel.locator('#machine-check-rows > li');
        await expect(rows.first()).toHaveAttribute('data-testid', /machine-check-(database|edge)/);
        await expect(panel.getByTestId('machine-check-database')).toContainText('MariaDB 11.8.2 is installed, but this server is set up for MySQL.');
        await expect(panel.getByTestId('machine-check-database')).toContainText('Blocked');
        await expect(panel.getByTestId('machine-check-edge')).toContainText("Port 80 is in use by nginx, which Kiln's edge needs.");
        await expect(panel.getByTestId('machine-check-edge')).toContainText('systemctl disable --now nginx.service');
        await expect(panel.getByTestId('machine-check-docker')).toContainText('Use existing');
        await expect(panel.getByTestId('machine-check-docker')).toContainText('docker-ce 28.1.1 · download.docker.com');
        await expect(panel.getByTestId('machine-check-swap')).toContainText('Keeps the existing swap (/swap.img)');
        await expect(panel.getByTestId('machine-check-firewall')).toContainText('ufw is active');

        // Re-check is offered; Provision is disabled while something blocks.
        await expect(panel.getByRole('button', { name: 'Re-check' })).toBeEnabled();
        await expect(panel.getByRole('button', { name: 'Provision' })).toBeDisabled();
        await shot(page, theme, 'needs-attention');

        // Mobile: the rows stay readable.
        await page.setViewportSize({ width: 390, height: 844 });
        await panel.scrollIntoViewIfNeeded();
        await shot(page, theme, 'needs-attention-mobile');

        expect(errors, errors.join('\n')).toEqual([]);
        await context.close();
    });
}

test('a server without a machine check shows no panel', async ({ browser, baseURL }) => {
    const context = await browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        storageState: existsSync(storageStatePath) ? storageStatePath : undefined,
    });
    const page = await context.newPage();
    await page.goto('/servers', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'app-1' }).first().click();
    await page.waitForLoadState('networkidle');

    // Demo servers predate the machine check: no panel without a check and an agent that runs it.
    await expect(page.locator('section#machine-check')).toHaveCount(0);
    await context.close();
});
