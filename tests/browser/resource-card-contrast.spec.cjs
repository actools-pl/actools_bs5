const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const fixturePath = process.env.ACTOOLS_TOKEN_FIXTURES;
if (!fixturePath) throw new Error('Generate theme tokens with build-token-fixtures.php and set ACTOOLS_TOKEN_FIXTURES.');
const variants = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const css = ['components/base/root/root.css', 'css/base/bootstrap.base.css',
  'css/base/tokens.base.css', 'css/base/actools.base.css',
  'components/organisms/resource-card/resource-card.css']
  .map(name => fs.readFileSync(path.join(root, name), 'utf8').replace(/^\uFEFF/, '')).join('\n');

// Calculate from browser-computed sRGB values, without rounding the threshold.
function contrast(foreground, background) {
  const luminance = colour => {
    const values = colour.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => {
      v /= 255;
      return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return values[0] * 0.2126 + values[1] * 0.7152 + values[2] * 0.0722;
  };
  const a = luminance(foreground), b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

for (const variant of variants) {
  for (const mode of ['light', 'dark']) {
    for (const width of [320, 1440]) {
      test(`resource title respects readable links: ${variant.name}, ${mode}, ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 800 });
        await page.setContent(`<!doctype html><html data-bs-theme="${mode}"><head><style>${css}\n${variant.css}</style></head><body><main><a id="ordinary" href="#ordinary">Ordinary theme link</a><article class="actools-resource-card"><div class="actools-resource-card__content"><h3 class="actools-resource-card__heading"><a id="card-title" href="#resource">Capillarity teaching resources</a></h3></div></article></main></body></html>`);
        const title = page.locator('#card-title');
        const ordinary = page.locator('#ordinary');
        for (const state of ['normal', 'hover', 'focus']) {
          await page.mouse.move(width - 1, 799);
          await page.evaluate(() => document.activeElement?.blur());
          if (state === 'hover') await title.hover();
          if (state === 'focus') await title.focus();
          const colour = await title.evaluate(el => getComputedStyle(el).color);
          const background = await page.locator('.actools-resource-card').evaluate(el => getComputedStyle(el).backgroundColor);
          expect(contrast(colour, background), `${variant.name} ${mode} ${state}: ${colour} on ${background}`).toBeGreaterThanOrEqual(4.5);
          // Normal links are the authority for brand/mode-specific colours.
          if (state === 'normal') {
            expect(colour).toBe(await ordinary.evaluate(el => getComputedStyle(el).color));
          }
          if (state === 'focus') {
            expect(await title.evaluate(el => getComputedStyle(el).outlineStyle)).not.toBe('none');
          }
        }
      });
    }
  }
}
