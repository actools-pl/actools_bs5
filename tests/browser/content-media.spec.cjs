const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
// Match framework dependencies. External CSS decoding strips a UTF-8 BOM;
// remove it here too so inline fixtures do not invalidate the first selector.
const css = ['components/base/root/root.css', 'css/base/bootstrap.base.css',
  'css/base/tokens.base.css', 'css/base/actools.base.css', 'css/layout/builder.layout.css']
  .map(name => fs.readFileSync(path.join(root, name), 'utf8').replace(/^\uFEFF/, '')).join('\n');
const svg = (width, height) => 'data:image/svg+xml;base64,' + Buffer.from(
  `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="#ddd"/></svg>`
).toString('base64');

test('the actual page-template skip link is visible when reached by keyboard', async ({ page }) => {
  const template = fs.readFileSync(path.join(root, 'templates/system/html.html.twig'), 'utf8');
  const match = template.match(/<a href="#main-content" class="([^"]+)"/);
  expect(match).not.toBeNull();
  await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><a href="#main-content" class="${match[1]}">Skip to main content</a><main id="main-content" tabindex="-1">Physics content</main></body></html>`);
  const link = page.getByRole('link', {name:'Skip to main content'});
  await page.keyboard.press('Tab');
  await expect(link).toBeFocused();
  const dimensions = await link.boundingBox();
  expect(dimensions.width).toBeGreaterThan(1);
  expect(dimensions.height).toBeGreaterThan(1);
  expect(await link.evaluate(element => getComputedStyle(element).clip)).toBe('auto');
  await page.keyboard.press('Enter');
  await expect(page.locator('#main-content')).toBeFocused();
});

for (const width of [320, 375, 768, 1440]) {
  for (const scenario of ['image-field', 'caption', 'layout-column']) {
    test(`${scenario} fits content at ${width}px without enlarging small diagrams`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const large = `<img id="large" src="${svg(1600, 900)}" width="1600" height="900" alt="Large physics diagram">`;
      const fieldClass = scenario === 'image-field' ? 'field--type-image' : 'field--type-text-with-summary';
      const content = `<div id="field" class="field ${fieldClass}">${scenario === 'caption' ? `<figure class="image" style="width:1600px">${large}<figcaption>Diagram caption</figcaption></figure>` : large}<p><img id="small" src="${svg(220, 132)}" width="220" height="132" alt="Small diagram"></p><p><img id="authored" src="${svg(220, 132)}" width="110" height="66" alt="Authored small diagram"></p></div>`;
      const body = scenario === 'layout-column'
        ? `<section class="actools-layout actools-layout--twocol-equal"><div class="actools-layout__grid"><div class="layout__region">${content}</div><div class="layout__region">Related resources</div></div></section>`
        : content;
      await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body><main class="container">${body}</main></body></html>`);
      await expect(page.locator('#large')).toBeVisible();
      await page.waitForFunction(() => [...document.images].every(image => image.complete && image.naturalWidth > 0));
      const metrics = await page.evaluate(() => {
        const field = document.querySelector('#field').getBoundingClientRect();
        const box = id => { const rect = document.querySelector(id).getBoundingClientRect(); return {width:rect.width,height:rect.height}; };
        return {fieldWidth:field.width,large:box('#large'),small:box('#small'),authored:box('#authored'),scrollWidth:document.documentElement.scrollWidth,viewport:innerWidth};
      });
      expect(metrics.large.width).toBeLessThanOrEqual(metrics.fieldWidth + 1);
      expect(metrics.large.width / metrics.large.height).toBeCloseTo(1600 / 900, 1);
      expect(metrics.small.width).toBeCloseTo(220, 0);
      expect(metrics.authored.width).toBeCloseTo(110, 0);
      expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.viewport);
    });
  }
}
