import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Settings → Networking of a compose site (docs/plans/COMPOSE_APPS.md, phase 2): a service picker; each public service
 * has its own domains (add one to grafana, it lists under grafana only) and its own rules ("All services" vs one
 * service). Needs the demo seeders (the Automations stack: n8n primary with automations.acme.dev, grafana). Removes
 * what it adds.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `compose-${name}.png`), fullPage: true });
}

for (const theme of ['dark', 'light'] as const) {
    test(`compose service domains and rules (${theme})`, async ({ browser, baseURL }) => {
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

        await page.goto('/servers', { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'app-1', exact: true }).first().click();
        await page.waitForLoadState('networkidle');
        await page
            .getByRole('link', { name: /Automations/ })
            .first()
            .click();
        await page.waitForURL(/\/service\/site\/[0-9a-z]{26}/i);
        await page.goto(`${new URL(page.url()).pathname.replace(/\/service\/site\/([0-9a-z]{26}).*$/i, '/service/site/$1')}/settings/networking`, {
            waitUntil: 'networkidle',
        });

        // The primary service: the site's own domains.
        const picker = page.getByRole('combobox', { name: 'Compose service' }).first();
        await expect(picker).toBeVisible();
        const domains = page.getByRole('list', { name: 'Domains' });
        await expect(domains.getByRole('link', { name: 'automations.acme.dev' })).toBeVisible();
        await shot(page, theme, 'domains-primary');

        // grafana: its own domains (not the site's); add one for it.
        await picker.click();
        await page.getByRole('option', { name: 'grafana', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Domains of grafana' })).toBeVisible();
        await expect(domains.getByRole('link', { name: 'automations.acme.dev' })).toHaveCount(0);
        await page.getByRole('button', { name: 'Add domain' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Add a domain for grafana' });
        await dialog.getByLabel('Domain name').fill(`grafana-${theme}.acme.dev`);
        await dialog.getByRole('button', { name: 'Add domain' }).click();
        await expect(dialog).toBeHidden({ timeout: 20_000 });
        await expect(domains.getByRole('link', { name: `grafana-${theme}.acme.dev` })).toBeVisible();
        await expect(domains.getByRole('link', { name: 'automations.acme.dev' })).toHaveCount(0);
        await shot(page, theme, 'domains-grafana');

        // Rules: all services vs grafana only.
        const rulesPicker = page.getByRole('combobox', { name: 'Compose service' }).nth(1);
        await rulesPicker.click();
        await page.getByRole('option', { name: 'grafana', exact: true }).click();
        await expect(page.getByText('Rules for all services apply to grafana as well')).toBeVisible();
        await page.getByLabel('Header name').fill('X-Robots-Tag');
        await page.getByLabel('Header value').fill('noindex');
        await page.getByRole('button', { name: 'Save header' }).click();
        await expect(page.getByText('X-Robots-Tag', { exact: true })).toBeVisible();
        await shot(page, theme, 'rules-grafana');
        await rulesPicker.click();
        await page.getByRole('option', { name: 'All services' }).click();
        await expect(page.getByText('X-Robots-Tag', { exact: true })).toHaveCount(0);

        // Clean up: the header and the domain.
        await rulesPicker.click();
        await page.getByRole('option', { name: 'grafana', exact: true }).click();
        page.once('dialog', (confirm) => void confirm.accept());
        await page.getByRole('button', { name: 'Delete header X-Robots-Tag' }).click();
        await expect(page.getByText('X-Robots-Tag', { exact: true })).toHaveCount(0);
        await page.getByRole('button', { name: `grafana-${theme}.acme.dev actions` }).click();
        page.once('dialog', (confirm) => void confirm.accept());
        await page.getByRole('menuitem', { name: 'Remove' }).click();
        await expect(domains.getByRole('link', { name: `grafana-${theme}.acme.dev` })).toHaveCount(0);

        expect(errors).toEqual([]);
        await context.close();
    });
}
