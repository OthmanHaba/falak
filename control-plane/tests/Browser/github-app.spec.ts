import { expect, test, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { type Server } from 'node:http';
import { join } from 'node:path';
import { startFakeGitHub } from './fake-github';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * GitHub App walkthrough: Settings → Source control → Connect GitHub (for a GitHub organization) → manifest POST →
 * code conversion → installation → setup callback → installed card → repository browser → Delete app, in both
 * themes. GitHub is faked (./fake-github.ts), so this needs a Kiln started with GITHUB_URL and GITHUB_API_URL
 * pointing at KILN_E2E_FAKE_GITHUB (e.g. http://127.0.0.1:8796); skipped otherwise (the sim talks to real GitHub).
 */
const FAKE = process.env.KILN_E2E_FAKE_GITHUB;
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

test.describe.configure({ mode: 'serial' });
test.skip(!FAKE, 'KILN_E2E_FAKE_GITHUB is not set');

let fake: Server | undefined;
test.beforeAll(async () => {
    fake = await startFakeGitHub(Number(new URL(FAKE ?? 'http://127.0.0.1:8796').port));
});
test.afterAll(() => {
    fake?.close();
});

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `github-app-${name}.png`), fullPage: true });
}

async function deleteAppIfPresent(page: Page) {
    const remove = page.getByRole('button', { name: 'Delete app' });
    if (!(await remove.isVisible())) return;
    const name = (await page.getByTestId('github-app-name').innerText()).trim();
    await remove.click();
    await page.getByRole('dialog').getByRole('textbox').fill(name);
    await page.getByRole('dialog').getByRole('button', { name: 'Delete app' }).click();
    await expect(page.getByRole('button', { name: 'Connect GitHub' })).toBeVisible();
}

for (const theme of ['dark', 'light'] as const) {
    test(`github app walkthrough (${theme})`, async ({ browser, baseURL }) => {
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

        await page.goto('/settings/source-control', { waitUntil: 'networkidle' });
        await deleteAppIfPresent(page);

        // Not created: one click, least privilege shown, token as the secondary option.
        const card = page.getByTestId('github-app');
        await expect(card.getByText('Recommended')).toBeVisible();
        await expect(card.getByLabel('Requested access')).toContainText(/contents/i);
        await expect(card.getByLabel('Requested access')).toContainText('push');
        await expect(page.getByRole('button', { name: 'Use a personal access token instead' })).toBeVisible();
        await shot(page, theme, 'connect');

        await card.getByRole('radio', { name: 'GitHub organization' }).click();
        await card.getByLabel('GitHub organization').fill('acme-e2e');
        await shot(page, theme, 'connect-organization');

        // Manifest POST → (fake) GitHub → conversion → installation → setup callback → back in Kiln.
        await card.getByRole('button', { name: 'Connect GitHub' }).click();
        await page.waitForURL(/\/settings\/source-control/);
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('GitHub connected.')).toBeVisible();
        await expect(card.getByText('Owned by acme-e2e')).toBeVisible();
        const installation = card.getByTestId('github-installation');
        await expect(installation).toContainText('acme-e2e');
        await expect(installation).toContainText('3 repositories');
        await expect(installation.getByText('Active')).toBeVisible();
        await expect(installation.getByRole('link', { name: 'Manage access on GitHub' })).toHaveAttribute(
            'href',
            /\/organizations\/acme-e2e\/settings\/installations\/4711$/,
        );
        await shot(page, theme, 'installed');

        await installation.getByRole('button', { name: 'Repositories' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('acme-e2e/storefront')).toBeVisible();
        await dialog.getByLabel('Search repositories').fill('market');
        await expect(dialog.getByText('acme-e2e/marketing-site')).toBeVisible();
        await expect(dialog.getByText('acme-e2e/storefront')).toHaveCount(0);
        await shot(page, theme, 'repositories');
        await page.keyboard.press('Escape');

        // Delete: uninstalls, disconnects and forgets the app.
        await deleteAppIfPresent(page);
        await expect(card.getByTestId('github-installation')).toHaveCount(0);

        expect(errors).toEqual([]);
        await context.close();
    });
}
