const path = require('node:path');
const baseURL = process.env.ACTOOLS_GL_BASE_URL || 'http://127.0.0.1:8894';
const url = new URL(baseURL);
if (url.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)) {
  throw new Error('Generic Lesson browser tests require a disposable loopback HTTP site.');
}
module.exports = {
  testDir: __dirname,
  testMatch: 'generic-lesson-ui.spec.cjs',
  timeout: 180000,
  expect: { timeout: 15000 },
  workers: 1,
  retries: 0,
  outputDir: process.env.ACTOOLS_GL_BROWSER_OUTPUT || path.join(__dirname, '../../../.build/generic-lesson-ui-results'),
  reporter: [['line']],
  use: {
    baseURL, browserName: 'chromium', headless: true,
    actionTimeout: 15000, viewport: { width: 1440, height: 1000 },
    screenshot: 'only-on-failure', trace: 'retain-on-failure',
    launchOptions: process.env.ACTOOLS_GL_CHROMIUM ? { executablePath: process.env.ACTOOLS_GL_CHROMIUM } : {},
  },
};
