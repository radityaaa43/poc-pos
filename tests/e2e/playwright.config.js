import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    globalSetup: './support/global-setup.js',
    // One worker: steps share one database. CI scales out with --shard instead.
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['blob'], ['github']] : 'list',
    outputDir: '../../test-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8010',
        locale: 'id-ID',
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.js/ },
        {
            name: 'chromium',
            testMatch: /features\/.*\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
    ],
});
