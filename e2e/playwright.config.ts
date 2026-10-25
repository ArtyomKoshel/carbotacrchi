import { defineConfig, devices } from '@playwright/test';

/*
 * UI tests of the two ways in — the mini app / site (/miniapp/) and the admin (/admin) — on a
 * local server over the prod dump. The server is laravel/dev/router.php on PHP's built-in
 * server, started here unless one already runs on the port; laravel/.env must say APP_ENV=local
 * (the sign-in without Telegram) and point at the dump. See README.md.
 */
const PORT = Number(process.env.E2E_PORT ?? 8097);

export default defineConfig({
  testDir: './tests',
  timeout: 90_000,
  expect: { timeout: 20_000 },
  // PHP's built-in server answers one request at a time
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    locale: 'ru-RU',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // E2E_SLOWMO=400 npm run test:headed — each action waits, so a person can follow the clicks
    launchOptions: { slowMo: Number(process.env.E2E_SLOWMO ?? 0) },
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
  ],
  webServer: {
    command: `php -d extension=pdo_mysql -d extension=intl -d memory_limit=1G -S 127.0.0.1:${PORT} -t ../laravel/public ../laravel/dev/router.php`,
    url: `http://127.0.0.1:${PORT}/up`,
    reuseExistingServer: true,
    timeout: 60_000,
    // the built-in server logs every connection to stderr
    stdout: 'ignore',
    stderr: 'ignore',
  },
});
