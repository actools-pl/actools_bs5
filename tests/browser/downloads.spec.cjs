const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const css = ['components/base/root/root.css', 'css/base/bootstrap.base.css',
  'css/base/tokens.base.css', 'css/base/actools.base.css', 'css/theme/publishing.theme.css']
  .map(name => fs.readFileSync(path.join(root, name), 'utf8').replace(/^\uFEFF/, '')).join('\n');

// Composite translucent text over the actual opaque panel background before
// calculating WCAG contrast. Visibility alone cannot detect unreadable text.
function contrast(foreground, background) {
  const parse = value => value.match(/[\d.]+/g).map(Number);
  const bg = parse(background);
  const fg = parse(foreground);
  const alpha = fg[3] ?? 1;
  const effective = fg.slice(0, 3).map((value, i) => value * alpha + bg[i] * (1 - alpha));
  const luminance = values => values.slice(0, 3).map(value => {
    value /= 255;
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  }).reduce((sum, value, i) => sum + value * [0.2126, 0.7152, 0.0722][i], 0);
  const a = luminance(effective), b = luminance(bg);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

for (const mode of ['light', 'dark']) {
  for (const width of [320, 1440]) {
    test(`Resource downloads wrap labels and retain metadata at ${width}px in ${mode}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 1000 });
      await page.setContent(`<!doctype html><html data-bs-theme="${mode}"><head><style>${css}</style></head><body><main class="container"><article class="actools-resource"><h1>Capillarity worked examples</h1><div class="actools-resource-downloads"><div class="actools-resource-downloads__label">Download</div><div class="actools-resource-downloads__item"><span class="actools-resource-downloads__link"><a href="#pdf">Capillary rise: worked examples and complete explanations for JEE Advanced</a></span><span class="actools-resource-downloads__metadata"><span class="actools-resource-downloads__type">PDF</span><span aria-hidden="true">·</span><span class="actools-resource-downloads__size">420 KB</span></span></div><div class="actools-resource-downloads__item"><span class="actools-resource-downloads__link"><a href="#docx">Download DOCX</a></span><span class="actools-resource-downloads__metadata"><span class="actools-resource-downloads__type">DOCX</span><span aria-hidden="true">·</span><span class="actools-resource-downloads__size">2 MB</span></span></div></div></article></main></body></html>`);
      await expect(page.getByRole('link')).toHaveCount(2);
      await expect(page.locator('.actools-resource-downloads__type')).toHaveText(['PDF', 'DOCX']);
      await expect(page.locator('.actools-resource-downloads__size')).toHaveText(['420 KB', '2 MB']);
      const background = await page.locator('.actools-resource-downloads').evaluate(el => getComputedStyle(el).backgroundColor);
      for (const text of await page.locator('.actools-resource-downloads__label, .actools-resource-downloads__type, .actools-resource-downloads__size, .actools-resource-downloads__link a').all()) {
        const foreground = await text.evaluate(el => getComputedStyle(el).color);
        expect(contrast(foreground, background), `${await text.innerText()}: ${foreground} on ${background}`).toBeGreaterThanOrEqual(4.5);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      for (const row of await page.locator('.actools-resource-downloads__item').all()) {
        const panel = await page.locator('.actools-resource-downloads').boundingBox();
        for (const child of await row.locator(':scope > span').all()) {
          const box = await child.boundingBox();
          expect(box.x).toBeGreaterThanOrEqual(panel.x);
          expect(box.x + box.width).toBeLessThanOrEqual(panel.x + panel.width + 1);
        }
      }
      await page.keyboard.press('Tab');
      await expect(page.getByRole('link').first()).toBeFocused();
      expect(await page.getByRole('link').first().evaluate(el => getComputedStyle(el).outlineStyle)).not.toBe('none');
      await page.emulateMedia({ media: 'print' });
      await expect(page.locator('.actools-resource-downloads__metadata').first()).toBeVisible();
      expect(await page.locator('.actools-resource-downloads').evaluate(el => getComputedStyle(el).breakInside)).toBe('avoid');
    });
  }
}
