const path = require('node:path');
const baseURL = process.env.ACTOOLS_NAV_BASE_URL || 'http://127.0.0.1:8891';
const url = new URL(baseURL);
if (url.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)) {
  throw new Error('Navigation browser tests require the disposable loopback HTTP site.');
}
module.exports = {
  testDir: __dirname,
  testMatch: 'navigation-ui.spec.cjs',
  timeout: 120000,
  expect: { timeout: 15000 },
  workers: 1,
  retries: 0,
  outputDir: process.env.ACTOOLS_NAV_BROWSER_OUTPUT || path.join(__dirname, '../../../.build/navigation-ui-results'),
  reporter: [['line']],
  use: {
    baseURL, browserName: 'chromium', headless: true,
    actionTimeout: 15000, viewport: { width: 1440, height: 1000 },
    screenshot: 'only-on-failure', trace: 'retain-on-failure',
  },
};
