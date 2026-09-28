import { expect, test, type Browser, type Page } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { storageStatePath } from './global-setup';
import { globalAllowedConsole } from './routes';

/**
 * Panel stack, canvas groups and the projects dashboard (docs/UI_DESIGN.md §3.1, §4.3, §5.5), in both themes:
 * panel open animation (end state), stacked deployment panel (Esc / back / deep link), compose + user groups on the
 * canvas, and the dashboard grid / list. Needs the UiDemoSeeder data.
 */
const SCREENSHOTS = join(import.meta.dirname, 'screenshots');

async function open(browser: Browser, baseURL: string | undefined, theme: 'dark' | 'light', options: { motion?: boolean; mobile?: boolean } = {}) {
    const origin = new URL(baseURL ?? '').origin;
    const context = await browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        viewport: options.mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 },
        storageState: existsSync(storageStatePath) ? storageStatePath : undefined,
        colorScheme: theme,
        reducedMotion: options.motion ? 'no-preference' : 'reduce',
    });
    await context.addCookies([{ name: 'appearance', value: theme, url: origin }]);
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(`Uncaught: ${error.message}`));
    page.on('console', (message) => {
        const text = message.text();
        if (message.type() === 'error' && !/Failed to load resource/.test(text) && !globalAllowedConsole.some((pattern) => pattern.test(text))) {
            errors.push(text);
        }
    });

    return { context, page, errors };
}

async function shot(page: Page, theme: string, name: string) {
    mkdirSync(join(SCREENSHOTS, theme), { recursive: true });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: join(SCREENSHOTS, theme, `${name}.png`) });
}

async function canvas(page: Page, project = 'Default') {
    await page.goto('/projects', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: project, exact: true }).click();
    await page.waitForURL(/\/production$/);
    await page.waitForLoadState('networkidle');
}

/** Wait until no animation runs on the panel layers (enter / recede / exit). */
async function settled(page: Page) {
    await page.waitForFunction(() => [...document.querySelectorAll('[data-kiln-panel]')].every((element) => element.getAnimations().length === 0));
    await page.waitForTimeout(320); // transform transitions of receding layers
}

async function layerStyle(page: Page, depth: number) {
    return page.locator(`[data-kiln-panel][data-depth="${depth}"]`).evaluate((element) => {
        const style = getComputedStyle(element);
        const matrix = new DOMMatrixReadOnly(style.transform === 'none' ? undefined : style.transform);

        return { transform: style.transform, opacity: Number(style.opacity), scale: matrix.a, x: matrix.e };
    });
}

for (const theme of ['dark', 'light'] as const) {
    test(`panel motion and stacked deployment panel (${theme})`, async ({ browser, baseURL }) => {
        const { context, page, errors } = await open(browser, baseURL, theme, { motion: true });
        await canvas(page);

        // Opening animates (slide + fade + scale) and ends at rest.
        await page.getByRole('group', { name: /^Marketing:/ }).click();
        const layer = page.locator('[data-kiln-panel][data-depth="0"]');
        await expect(layer).toBeVisible();
        expect(await layer.evaluate((element) => element.getAnimations().length), 'panel enters with an animation').toBeGreaterThan(0);
        await settled(page);
        expect(await layerStyle(page, 0)).toMatchObject({ transform: 'none', opacity: 1 });
        await expect(layer).toBeFocused();
        await page.waitForLoadState('networkidle');
        await expect(page.getByTestId('deployment-card-live')).toBeVisible();
        await shot(page, theme, 'panel-open');

        // The ACTIVE card expands to the phase timeline.
        await page.getByRole('button', { name: 'Deployment successful' }).click();
        await expect(page.getByRole('button', { name: 'Deployment successful' })).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByTestId('deployment-card-live').getByText('Health')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await shot(page, theme, 'panel-deployment-expanded');

        // View logs stacks the deployment panel; the service panel recedes but stays visible.
        await page.getByRole('button', { name: 'View logs' }).first().click();
        await expect(page.getByTestId('deployment-panel')).toBeVisible();
        await expect(page).toHaveURL(/[?&]logs=[0-9a-z]{26}/);
        await settled(page);
        const below = await layerStyle(page, 1);
        expect(below.scale, 'receded panel is scaled down').toBeLessThan(1);
        expect(below.x, 'receded panel shifts left').toBeLessThan(0);
        expect(await layerStyle(page, 0)).toMatchObject({ transform: 'none', opacity: 1 });
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('log')).toBeVisible();
        await expect(page.getByTestId('log-time-header')).toContainText(/Time/);
        await shot(page, theme, 'panel-stacked-logs');

        // `/` focuses the filter of the top panel; Esc leaves the field, the next Esc pops the deployment panel.
        await page.getByRole('log').click();
        await page.keyboard.press('/');
        await expect(page.getByRole('searchbox', { name: /Filter/ })).toBeFocused();
        await page.keyboard.type('fetch');
        await expect(page.getByText(/\d+ match/)).toBeVisible();
        await shot(page, theme, 'panel-stacked-logs-filtered');
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('deployment-panel')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('deployment-panel')).toHaveCount(0);
        await expect(page.getByTestId('service-panel')).toBeVisible();
        await settled(page);
        expect(await layerStyle(page, 0)).toMatchObject({ transform: 'none' });

        // Browser back re-opens the deployment panel, back again pops it.
        await page.goBack();
        await expect(page.getByTestId('deployment-panel')).toBeVisible();
        await page.goBack();
        await expect(page.getByTestId('deployment-panel')).toHaveCount(0);
        await expect(page.getByTestId('service-panel')).toBeVisible();

        // Deep link straight into the stack (build logs tab).
        const url = new URL(page.url());
        await page.getByRole('button', { name: 'View logs' }).first().click();
        await expect(page).toHaveURL(/[?&]logs=/);
        const deployment = new URL(page.url()).searchParams.get('logs');
        await page.goto(`${url.pathname}?logs=${deployment}&logs_tab=build`, { waitUntil: 'networkidle' });
        await expect(page.getByTestId('deployment-panel')).toBeVisible();
        await expect(page.getByRole('tab', { name: /Build Logs/ })).toHaveAttribute('aria-selected', 'true');
        await expect(page.locator('[data-kiln-panel]')).toHaveCount(2);

        // Network logs: no edge access logs yet, said plainly.
        await page.getByRole('tab', { name: 'Network Logs' }).click();
        await expect(page.getByText('No HTTP request logs for this deployment')).toBeVisible();

        expect.soft(errors, 'console errors').toEqual([]);
        await context.close();
    });

    test(`canvas groups (${theme})`, async ({ browser, baseURL }) => {
        const { context, page, errors } = await open(browser, baseURL, theme);
        await canvas(page);

        // Compose stack as a group of its compose services, a user group around Storefront + its database.
        await expect(page.getByRole('group', { name: /^Automations:/ })).toBeVisible();
        for (const child of ['n8n', 'postgres', 'redis', 'grafana']) {
            await expect(page.getByRole('group', { name: new RegExp(`^${child} \\(Automations\\)`) })).toBeVisible();
        }
        await expect(page.getByRole('group', { name: 'Commerce group', exact: true })).toBeVisible();
        await expect(page.getByTestId('volume-strip').first()).toBeVisible();
        expect(await page.getByTestId('volume-strip').count()).toBeGreaterThanOrEqual(4);
        expect(await page.locator('.kiln-edge').count(), 'reference + depends_on edges').toBeGreaterThanOrEqual(4);
        await expect(page.locator('.kiln-edge path[marker-end]').first()).toBeAttached();
        await shot(page, theme, 'canvas-groups');

        // A compose service opens its site's Services tab.
        await page.getByRole('group', { name: /^redis \(Automations\)/ }).click();
        await page.waitForURL(/\/service\/site\/[0-9a-z]{26}\/services$/);
        await expect(page.getByTestId('service-panel')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await shot(page, theme, 'canvas-compose-panel');
        await page.keyboard.press('Escape');
        await page.waitForURL(/\/production$/);

        if (theme === 'dark') {
            // Collapse / expand the compose group (persisted; restored at the end).
            await page.getByRole('button', { name: 'Fit to screen' }).click();
            await page.getByRole('button', { name: 'Automations group actions' }).click();
            await page.getByRole('menuitem', { name: 'Collapse' }).click();
            await expect(page.getByRole('group', { name: /^n8n \(Automations\)/ })).toBeHidden();
            await shot(page, theme, 'canvas-group-collapsed');
            await page.getByRole('button', { name: 'Automations group actions' }).click();
            await page.getByRole('menuitem', { name: 'Expand' }).click();
            await expect(page.getByRole('group', { name: /^n8n \(Automations\)/ })).toBeVisible();

            // User group: ⌘-click two cards → Group → name it → ungroup again (the Content project).
            await canvas(page, 'Content');
            const modifier = process.platform === 'darwin' ? 'Meta' : 'Control';
            await page.getByRole('group', { name: /^Docs:/ }).click({ modifiers: [modifier] });
            await page.getByRole('group', { name: /^Blog:/ }).click({ modifiers: [modifier] });
            await expect(page.getByRole('region', { name: 'Selection' })).toContainText('2 services selected');
            await shot(page, theme, 'canvas-selection');
            await page.getByRole('button', { name: 'Group', exact: true }).click();
            await page.getByLabel('Group name').fill('Web');
            await page.getByLabel('Group name').press('Enter');
            await expect(page.getByRole('group', { name: 'Web group', exact: true })).toBeVisible();
            await page.waitForLoadState('networkidle');
            await shot(page, theme, 'canvas-user-group');
            await page.getByRole('button', { name: 'Web group actions' }).click();
            await page.getByRole('menuitem', { name: 'Ungroup' }).click();
            await expect(page.getByRole('group', { name: 'Web group', exact: true })).toHaveCount(0);
        }

        expect.soft(errors, 'console errors').toEqual([]);
        await context.close();
    });

    test(`projects dashboard (${theme})`, async ({ browser, baseURL }) => {
        const { context, page, errors } = await open(browser, baseURL, theme);
        await page.goto('/projects', { waitUntil: 'networkidle' });

        const cards = page.getByTestId('project-card');
        await expect(cards).toHaveCount(3);
        // Starred first, then by recent activity; each card previews what's inside.
        await expect(cards.first()).toContainText('Content');
        await expect(cards.first().getByRole('button', { name: 'Unstar Content' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('project-health').first()).toContainText(/production · \d+\/\d+ services online/);
        await expect(cards.filter({ hasText: 'Platform' })).toContainText('No services');
        await shot(page, theme, 'projects-grid');

        await page.getByRole('radio', { name: 'List view' }).click();
        await expect(page.getByTestId('project-row')).toHaveCount(3);
        await shot(page, theme, 'projects-list');

        await page.keyboard.press('/');
        await expect(page.getByRole('searchbox', { name: 'Search projects' })).toBeFocused();
        await page.keyboard.type('storefront');
        await expect(page.getByTestId('project-row')).toHaveCount(1);
        await expect(page.getByTestId('project-row')).toContainText('Default');

        expect.soft(errors, 'console errors').toEqual([]);
        await context.close();
    });

    test(`stacked panels on a phone (${theme})`, async ({ browser, baseURL }) => {
        const { context, page, errors } = await open(browser, baseURL, theme, { mobile: true });
        await canvas(page);
        await page.getByRole('group', { name: /^Marketing:/ }).click();
        await expect(page.getByTestId('service-panel')).toBeVisible();
        await page.waitForLoadState('networkidle');
        await shot(page, theme, 'panel-open.mobile');
        await page.getByRole('button', { name: 'View logs' }).first().click();
        await expect(page.getByTestId('deployment-panel')).toBeVisible();
        await page.waitForLoadState('networkidle');
        const width = await page.locator('[data-kiln-panel][data-depth="0"]').evaluate((element) => element.getBoundingClientRect().width);
        expect(width).toBeGreaterThanOrEqual(389);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(1);
        await shot(page, theme, 'panel-stacked-logs.mobile');
        await page.getByRole('button', { name: 'Close' }).last().click();
        await expect(page.getByTestId('deployment-panel')).toHaveCount(0);

        expect.soft(errors, 'console errors').toEqual([]);
        await context.close();
    });
}
