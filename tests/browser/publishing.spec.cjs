const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '../..');
const specimen = pathToFileURL(path.join(root, 'docs/preview/topic.html')).href;
const css = ['components/base/root/root.css', 'css/base/bootstrap.base.css',
  'css/base/tokens.base.css', 'css/base/actools.base.css', 'css/theme/publishing.theme.css']
  .map(name => fs.readFileSync(path.join(root, name), 'utf8').replace(/^\uFEFF/, '')).join('\n');

for (const width of [320, 390, 768, 1440]) {
  test(`publishing specimen keeps its reading flow and complete diagrams at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto(specimen);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForFunction(() => [...document.images].every(image => image.complete && image.naturalWidth));
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('.actools-resource-card')).toHaveCount(8);
    await expect(page.locator('.actools-resource-card__metadata')).toHaveCount(8);
    await expect(page.locator('figcaption.actools-text-image__caption')).toHaveCount(1);
    expect(await page.locator('h2').allTextContents()).toEqual([
      'Why water rises in a narrow tube', 'Concepts and notes', 'Worked examples', 'Practice assignments',
    ]);
    const metrics = await page.evaluate(() => ({
      viewport: innerWidth,
      pageWidth: document.documentElement.scrollWidth,
      titleX: document.querySelector('h1').getBoundingClientRect().x,
      introX: document.querySelector('.actools-topic__content .field--name-field-actools-body').getBoundingClientRect().x,
      family: getComputedStyle(document.body).fontFamily,
      images: [...document.images].map(image => {
        const rect = image.getBoundingClientRect();
        return { width: rect.width, height: rect.height, naturalWidth: image.naturalWidth, naturalHeight: image.naturalHeight };
      }),
    }));
    expect(metrics.pageWidth).toBeLessThanOrEqual(metrics.viewport);
    expect(Math.abs(metrics.titleX - metrics.introX)).toBeLessThanOrEqual(1);
    expect(metrics.family.toLowerCase()).toContain('figtree');
    for (const image of metrics.images) {
      expect(image.width).toBeLessThanOrEqual(image.naturalWidth);
      expect(image.width / image.height).toBeCloseTo(image.naturalWidth / image.naturalHeight, 2);
    }
  });
}

test('publishing metadata remains an information line on a full resource', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><article class="node actools-resource actools-resource--full"><div>
    <div class="field actools-resource-metadata actools-resource-metadata__field"><span class="field__item actools-resource-metadata__item">Worked examples</span></div>
    <div class="field actools-resource-metadata actools-resource-metadata__field"><span class="field__item actools-resource-metadata__item">JEE Advanced</span></div>
  </div></article></body></html>`);
  const fields = page.locator('.actools-resource-metadata__field');
  const first = await fields.nth(0).boundingBox();
  const second = await fields.nth(1).boundingBox();
  expect(second.y).toBeCloseTo(first.y, 0);
  expect(second.x).toBeGreaterThan(first.x + first.width);
});

for (const size of ['1rem', '1.25rem']) {
  test(`full Topic body honours ${size} body settings without styling a teaser`, async ({ page }) => {
    await page.setContent(`<!doctype html><html><head><style>${css}\n:root{--bs-body-font-size:${size}}</style></head><body>
      <article class="node actools-topic actools-topic--full actools-topic--publishing"><div class="field--name-field-actools-body">Full topic introduction.</div></article>
      <article class="node actools-topic actools-topic--teaser"><div class="field--name-field-actools-body">Topic teaser.</div></article>
    </body></html>`);
    const sizes = await page.evaluate(() => {
      const full = getComputedStyle(document.querySelector('.actools-topic--publishing .field--name-field-actools-body'));
      const teaser = getComputedStyle(document.querySelector('.actools-topic--teaser .field--name-field-actools-body'));
      return { body: getComputedStyle(document.body).fontSize, full: full.fontSize, fullMax: full.maxInlineSize, teaserMax: teaser.maxInlineSize };
    });
    expect(sizes.full).toBe(sizes.body);
    expect(sizes.fullMax).not.toBe('none');
    expect(sizes.teaserMax).toBe('none');
  });
}
