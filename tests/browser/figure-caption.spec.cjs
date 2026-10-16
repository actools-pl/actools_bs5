const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const css = ['components/base/root/root.css', 'css/base/bootstrap.base.css',
  'css/base/tokens.base.css', 'components/organisms/text-image/text-image.css']
  .map(name => fs.readFileSync(path.join(root, name), 'utf8').replace(/^\uFEFF/, '')).join('\n');
const image = width => 'data:image/svg+xml;base64,' + Buffer.from(
  `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${width / 2}"><rect width="100%" height="100%" fill="#ddd"/></svg>`
).toString('base64');

for (const [viewport, available, intrinsic] of [[1440, 1200, 160], [1440, 1200, 300], [1440, 1200, 1600], [320, 296, 1600]]) {
  for (const position of ['left', 'right']) {
    test(`caption stays with a ${intrinsic}px ${position} diagram in ${available}px`, async ({ page }) => {
      await page.setViewportSize({ width: viewport, height: 1000 });
      await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><main style="width:${available}px;max-width:calc(100% - 24px);margin:12px"><div class="actools-text-image-block"><section class="actools-text-image actools-text-image--media-${position}"><div class="actools-text-image__content"><h2>Capillary pressure</h2><p>Read the complete diagram and its caption.</p></div><figure class="actools-text-image__media"><div class="field field__item"><a href="#original"><img src="${image(intrinsic)}" width="${intrinsic}" height="${intrinsic / 2}" alt="Complete capillary diagram"></a></div><figcaption class="actools-text-image__caption">Figure 1. A long explanatory caption describes the wetting liquid, the raised column and the direction of the surface-tension force without stretching the diagram across its available column.</figcaption></figure></section></div></main></body></html>`);
      await page.waitForFunction(() => document.images[0].complete && document.images[0].naturalWidth);
      const bounds = await page.evaluate(() => {
        const box = selector => {
          const r = document.querySelector(selector).getBoundingClientRect();
          return { x: r.x, y: r.y, width: r.width, height: r.height };
        };
        return { figure: box('figure'), caption: box('figcaption'), image: box('img'), text: box('.actools-text-image__content'), scroll: document.documentElement.scrollWidth, viewport: innerWidth };
      });
      expect(bounds.scroll).toBeLessThanOrEqual(bounds.viewport);
      expect(bounds.image.width).toBeLessThanOrEqual(intrinsic);
      expect(bounds.image.width / bounds.image.height).toBeCloseTo(2, 2);
      expect(bounds.caption.x).toBeCloseTo(bounds.figure.x, 0);
      expect(bounds.caption.width).toBeCloseTo(bounds.figure.width, 0);
      expect(Math.abs((bounds.image.x + bounds.image.width / 2) - (bounds.figure.x + bounds.figure.width / 2))).toBeLessThanOrEqual(1);
      expect(bounds.caption.y).toBeGreaterThanOrEqual(bounds.image.y + bounds.image.height);
      if (intrinsic === 300) expect(bounds.figure.width).toBeCloseTo(300, 0);
      if (intrinsic === 160) expect(bounds.figure.width).toBeGreaterThanOrEqual(280);
      if (available < 640) expect(bounds.figure.y).toBeGreaterThanOrEqual(bounds.text.y + bounds.text.height);
      await page.emulateMedia({ media: 'print' });
      await expect(page.locator('figcaption')).toBeVisible();
      expect(await page.locator('figure').evaluate(el => getComputedStyle(el).breakInside)).toBe('avoid');
    });
  }
}
