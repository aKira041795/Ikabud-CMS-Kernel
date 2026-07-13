// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/browser',
    timeout: 30000,
    fullyParallel: false,
    retries: 0,
    workers: 1,
    reporter: [
        ['list'],
        ['./tests/browser/WorkbenchReporter.js'],
    ],
    use: {
        baseURL: process.env.APP_URL || 'http://palsystem.test',
        viewport: { width: 1280, height: 720 },
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
