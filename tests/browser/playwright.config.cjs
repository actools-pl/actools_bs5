const path = require('node:path');
module.exports = {
  testDir: __dirname,
  testIgnore: ['**/layout-ui/**', '**/navigation-ui/**', '**/generic-lesson-ui/**'],
  outputDir: process.env.ACTOOLS_BROWSER_OUTPUT || path.join(__dirname, '../../.build/browser-results'),
  reporter: [['line']],
  use: { browserName: 'chromium', headless: true, screenshot: 'only-on-failure', trace: 'retain-on-failure' },
};
