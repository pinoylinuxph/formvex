import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/system/Client',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    browserName: 'chromium',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'node tests/system/support/static-server.js',
    url: 'http://127.0.0.1:4173/health',
    reuseExistingServer: false,
    timeout: 10_000,
  },
});
