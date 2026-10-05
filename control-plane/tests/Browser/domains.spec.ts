import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Domain choices: the template Configure form (generated sslip.io name / custom domain with DNS instructions and the
 * live check), the canvas Create picker (Docker image), Settings → Networking → Add domain, Settings → Compose and
 * Settings → Domains, in both themes. Needs the demo seeders (app-1 = 49.12.40.11) and no FALAK_TEST_DOMAIN. Creates
 * nothing. The live DNS check runs against the configured resolver; example.com names do not resolve.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `domains-${name}.png`) });
}

async function openCanvas(page: Page) {
    await page.goto('/projects', { waitUntil: 'networkidle' });
    await page
        .locator('a[href$="/production"]')
        .filter({ hasText: /^Default$/ })
        .first()
        .click();
    await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
    await page.waitForLoadState('networkidle');
}

for (const theme of ['dark', 'light'] as const) {
    test(`domain choices (${theme})`, async ({ browser, baseURL }) => {
        const origin = new URL(baseURL ?? '').origin;
        const context = await browser.newContext({
            baseURL,
            ignoreHTTPSErrors: true,
            viewport: { width: 1440, height: 1000 },
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

        // Template Configure (the reported screen): no test domain → each public service defaults to a generated name.
        await page.goto('/templates', { waitUntil: 'networkidle' });
        await page.getByTestId('template-minio').first().click();
        const form = page.getByRole('form', { name: 'Configure MinIO' });
        await expect(form).toBeVisible();
        const minio = form.getByRole('radiogroup', { name: 'minio domain' });
        await expect(minio.getByRole('radio', { name: /Generate \(sslip\.io\)/ })).toHaveAttribute('aria-checked', 'true');
        await expect(form.getByText('minio.49-12-40-11.sslip.io', { exact: true })).toBeVisible();
        await expect(form.getByText('console-minio.49-12-40-11.sslip.io')).toBeVisible();
        await expect(form.getByText(/No test domain is configured/)).toHaveCount(0);
        await form.getByRole('radiogroup', { name: 'console domain' }).scrollIntoViewIfNeeded();
        await shot(page, theme, 'template-generated');

        // Custom domain for the console: the record to add and the live check.
        await form.getByRole('radiogroup', { name: 'console domain' }).getByRole('radio', { name: 'Custom domain' }).click();
        await form.getByLabel('console domain: custom domain').fill('files.example.com');
        const dns = form.getByLabel('DNS for files.example.com');
        await expect(dns.getByRole('cell', { name: 'A', exact: true })).toBeVisible();
        await expect(dns.getByText('49.12.40.11').first()).toBeVisible();
        await expect(dns.getByText('files', { exact: true }).first()).toBeVisible();
        await expect(dns.getByRole('cell', { name: 'AAAA', exact: true })).toBeVisible();
        await expect(dns.locator('[data-dns-status]')).toBeVisible({ timeout: 15_000 });
        await dns.scrollIntoViewIfNeeded();
        await shot(page, theme, 'template-custom');
        await page.keyboard.press('Escape');

        // Canvas → Create → Docker image: the same picker under the servers.
        await openCanvas(page);
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await page.getByRole('option', { name: /Docker image/ }).click();
        const picker = page.getByRole('dialog', { name: 'Deploy a Docker image' });
        await picker.getByPlaceholder('nginx:1.27').fill('ghcr.io/acme/api:latest');
        await expect(picker.getByRole('radiogroup', { name: 'Domain' })).toBeVisible();
        await expect(picker.getByText('api.49-12-40-11.sslip.io')).toBeVisible();
        await shot(page, theme, 'create-generated');
        await picker.getByRole('radio', { name: 'Custom domain' }).click();
        await picker.getByLabel('Domain: custom domain').fill('api.example.com');
        await expect(picker.getByLabel('DNS for api.example.com')).toBeVisible();
        await shot(page, theme, 'create-custom');
        await page.keyboard.press('Escape');

        // Settings → Networking → Add domain: your own (DNS instructions) or a generated one.
        await page.goto('/servers', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'app-1', exact: true }).first().click();
        await page.waitForLoadState('networkidle');
        await page
            .getByRole('link', { name: /Storefront/ })
            .first()
            .click();
        await page.waitForURL(/\/service\/site\/[0-9a-z]{26}/i);
        await page.goto(`${new URL(page.url()).pathname.replace(/\/service\/site\/([0-9a-z]{26}).*$/i, '/service/site/$1')}/settings/networking`, {
            waitUntil: 'networkidle',
        });
        await page.getByRole('button', { name: 'Add domain' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Add domain' });
        await dialog.getByLabel('Domain name').fill('shop.example.com');
        await expect(dialog.getByLabel('DNS for shop.example.com')).toBeVisible();
        await expect(dialog.locator('[data-dns-status]')).toBeVisible({ timeout: 15_000 });
        await shot(page, theme, 'networking-custom');
        await dialog.getByRole('radio', { name: 'Generate one' }).click();
        await expect(dialog.getByTestId('domain-name')).toHaveText(/^storefront\.49-12-40-1[12]\.sslip\.io$/);
        await shot(page, theme, 'networking-generated');
        await page.keyboard.press('Escape');

        // Settings → Domains (organization).
        await page.goto('/settings/domains', { waitUntil: 'networkidle' });
        await expect(page.getByRole('heading', { name: 'Generated domains' })).toBeVisible();
        await shot(page, theme, 'settings');

        expect(errors, 'console errors').toEqual([]);
        await context.close();
    });
}
