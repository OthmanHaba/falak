import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Templates walkthrough (docs/COMPOSE_TEMPLATES.md §3–§4): /templates gallery → configure dialog, the canvas Create
 * picker → Template gallery → configure form, Settings → Templates and its import dialog, in both themes.
 * Needs UiDemoSeeder (a custom template + a Docker-capable server). Does not deploy anything.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `templates-${name}.png`) });
}

for (const theme of ['dark', 'light'] as const) {
    test(`templates walkthrough (${theme})`, async ({ browser, baseURL }) => {
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

        // Full-page gallery: popular + all, search, categories.
        await page.goto('/templates', { waitUntil: 'networkidle' });
        await expect(page.getByRole('heading', { name: 'Templates', exact: true })).toBeVisible();
        await expect(page.getByTestId('template-n8n').first()).toBeVisible();
        await shot(page, theme, 'gallery');
        await page.getByRole('radio', { name: 'Analytics' }).click();
        await expect(page.getByTestId('template-plausible')).toBeVisible();
        await expect(page.getByTestId('template-n8n')).toHaveCount(0);
        await page.getByRole('radio', { name: 'All' }).click();
        await page.getByLabel('Search templates').fill('status api');
        await expect(page.getByTestId('template-acme-status-api')).toBeVisible();
        await shot(page, theme, 'gallery-search');
        await page.getByLabel('Search templates').fill('');

        // Configure dialog: generated secrets masked with Show / Regenerate, domains, servers.
        await page.getByTestId('template-plausible').first().click();
        const form = page.getByRole('form', { name: 'Configure Plausible Analytics' });
        await expect(form).toBeVisible();
        const secret = form.getByLabel(/Secret key base/);
        await expect(secret).toHaveAttribute('type', 'password');
        const before = await secret.inputValue();
        expect(before).toMatch(/^[A-Za-z0-9]{64}$/);
        await form.getByRole('button', { name: 'Regenerate' }).first().click();
        await expect(secret).not.toHaveValue(before);
        await form.getByRole('button', { name: 'Show' }).first().click();
        await expect(secret).toHaveAttribute('type', 'text');
        await expect(form.getByRole('checkbox', { name: 'app-1' })).toBeVisible();
        await shot(page, theme, 'configure');
        await page.keyboard.press('Escape');

        // Canvas: Create picker → Template.
        await page.goto('/projects', { waitUntil: 'networkidle' });
        await page.locator('a[href$="/production"]').first().click();
        await page.waitForURL(/\/projects\/[0-9a-z]{26}\/production$/i);
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await page.getByRole('option', { name: /Template/ }).click();
        const picker = page.getByRole('dialog', { name: 'Deploy a template' });
        await expect(picker.getByTestId('template-n8n')).toBeVisible();
        await shot(page, theme, 'picker-gallery');
        await picker.getByTestId('template-n8n').click();
        await expect(picker.getByRole('form', { name: 'Configure n8n' })).toBeVisible();
        await expect(picker.getByLabel(/Encryption key/)).toHaveAttribute('type', 'password');
        await shot(page, theme, 'picker-configure');
        await page.keyboard.press('Escape');

        // Settings → Templates + import dialog.
        await page.goto('/settings/templates', { waitUntil: 'networkidle' });
        await expect(page.getByText('Acme Status API')).toBeVisible();
        await shot(page, theme, 'settings');
        await page.getByRole('button', { name: 'Import template' }).first().click();
        await expect(page.getByRole('dialog', { name: 'Import template' })).toBeVisible();
        await shot(page, theme, 'settings-import');

        expect(errors, 'console errors').toEqual([]);
        await context.close();
    });
}
