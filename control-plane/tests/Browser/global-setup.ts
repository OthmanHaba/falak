import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';

export const storageStatePath = join(import.meta.dirname, '.auth', 'state.json');

/** Log in once and share the session with every test. */
export default async function globalSetup(config: FullConfig): Promise<void> {
    const { baseURL } = config.projects[0].use;
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL, ignoreHTTPSErrors: true });

    await page.goto('/login');
    await page.getByLabel('Email address').fill(process.env.FALAK_E2E_EMAIL ?? 'admin@falak.test');
    await page.getByLabel('Password', { exact: true }).fill(process.env.FALAK_E2E_PASSWORD ?? 'falak-demo-2026');
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 });

    mkdirSync(dirname(storageStatePath), { recursive: true });
    await page.context().storageState({ path: storageStatePath });
    await browser.close();
}
