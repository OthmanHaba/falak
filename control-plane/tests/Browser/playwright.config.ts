import { defineConfig, devices } from '@playwright/test';

/**
 * Browser smoke tests (docs/UI_DESIGN.md §8): `bun run test:browser`.
 *
 *   KILN_E2E_URL       base URL (default https://localhost:8443, the sim; self-signed certs are accepted)
 *   KILN_E2E_EMAIL     login (default admin@kiln.test)
 *   KILN_E2E_PASSWORD  password (default kiln-demo-2026)
 */
export const baseURL = process.env.KILN_E2E_URL ?? 'https://localhost:8443';

export default defineConfig({
    testDir: '.',
    testMatch: /.*\.spec\.ts$/,
    outputDir: './.results',
    globalSetup: './global-setup.ts',
    fullyParallel: true,
    workers: process.env.CI ? 2 : 4,
    retries: 0,
    timeout: 60_000,
    reporter: [['list'], ['html', { open: 'never', outputFolder: './.report' }]],
    use: {
        ...devices['Desktop Chrome'],
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
    },
});
