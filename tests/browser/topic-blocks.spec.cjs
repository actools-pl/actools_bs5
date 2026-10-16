const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const css = ['components/base/root/root.css', 'css/base/bootstrap.base.css',
  'css/base/tokens.base.css', 'css/base/actools.base.css',
  'components/organisms/resource-card/resource-card.css',
  'components/organisms/resource-card/resource-card-grid.css',
  'components/organisms/text-image/text-image.css']
  .map(name => fs.readFileSync(path.join(root, name), 'utf8').replace(/^\uFEFF/, '')).join('\n');
const image = (width, height) => 'data:image/svg+xml;base64,' + Buffer.from(
  `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="#ddd"/></svg>`
).toString('base64');

// Representative field/SDC DOM for CSS behavior. Drupal integration tests render
// the actual Twig wrappers, field formatters and Layout Builder sections.
for (const [viewport, available, columns] of [[320, 296, 1], [768, 720, 2], [1440, 1200, 3], [1440, 300, 1]]) {
  test(`resource grid uses its ${available}px column within a ${viewport}px viewport`, async ({ page }) => {
    await page.setViewportSize({ width: viewport, height: 1000 });
    const card = index => `<li class="actools-resource-cards__item"><article class="actools-resource-card"><div class="actools-resource-card__content"><div class="actools-resource-card__metadata">Notes · Foundation</div><h3 class="actools-resource-card__heading"><a href="#resource-${index}">Capillarity resource ${index}</a></h3><div class="actools-resource-card__summary">A useful teaching resource with a longwordthatmustnotpushthecardoutsidetheavailablecolumn.</div></div>${index === 2 ? '' : `<div class="actools-resource-card__media"><img src="${image(220, 132)}" width="220" height="132" alt="Complete diagram ${index}"></div>`}</article></li>`;
    await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><main style="max-width:${available}px;margin:12px"><h1>Surface tension</h1><div class="field--name-field-actools-resources"><h2>Resources</h2><ul class="actools-resource-cards__items">${[1, 2, 3].map(card).join('')}</ul></div></main></body></html>`);
    await page.waitForFunction(() => [...document.images].every(i => i.complete && i.naturalWidth));
    const dimensions = await page.locator('.actools-resource-cards__items').evaluate(el => ({
      columns: getComputedStyle(el).gridTemplateColumns.split(' ').length,
      scroll: document.documentElement.scrollWidth,
      viewport: innerWidth,
    }));
    expect(dimensions.columns).toBe(columns);
    expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.viewport);
    const img = await page.locator('img').first().boundingBox();
    expect(img.width).toBeLessThanOrEqual(220);
    expect(img.width / img.height).toBeCloseTo(220 / 132, 2);
    const cards = page.locator('.actools-resource-card');
    const cardMetrics = await cards.evaluateAll(elements => elements.map(card => {
      const rect = card.getBoundingClientRect();
      const content = card.querySelector('.actools-resource-card__content').getBoundingClientRect();
      const title = card.querySelector('.actools-resource-card__heading').getBoundingClientRect();
      const media = card.querySelector('.actools-resource-card__media');
      return {
        textFirst: card.firstElementChild.classList.contains('actools-resource-card__content'),
        mediaLast: !media || media === card.lastElementChild,
        mediaBelowText: !media || media.getBoundingClientRect().y >= content.bottom - 1,
        titleY: title.y,
        titleOffset: title.y - rect.y,
        cardHeight: rect.height,
        contentHeight: content.height,
      };
    }));
    for (const card of cardMetrics) {
      expect(card.textFirst).toBe(true);
      expect(card.mediaLast).toBe(true);
      expect(card.mediaBelowText).toBe(true);
    }
    await expect(cards.nth(1).locator('.actools-resource-card__media')).toHaveCount(0);
    // An optional image cannot push one title below its image-free neighbour.
    expect(cardMetrics[0].titleOffset).toBeCloseTo(cardMetrics[1].titleOffset, 1);
    if (columns > 1) expect(cardMetrics[0].titleY).toBeCloseTo(cardMetrics[1].titleY, 1);
    else {
      // Stacked cards take their own natural height; the image-free card has
      // only its content and border, without a thumbnail-sized empty region.
      expect(cardMetrics[1].cardHeight).toBeCloseTo(cardMetrics[1].contentHeight + 2, 1);
      expect(cardMetrics[1].cardHeight).toBeLessThan(cardMetrics[0].cardHeight);
    }
    await page.keyboard.press('Tab');
    const link = page.getByRole('link').first();
    await expect(link).toBeFocused();
    expect(await link.evaluate(el => getComputedStyle(el).outlineStyle)).not.toBe('none');
  });
}

for (const position of ['left', 'right']) {
  for (const available of [300, 720]) {
    test(`text/image ${position} respects a ${available}px available column`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 1000 });
      await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><main style="max-width:${available}px;margin:12px"><div class="actools-text-image-block"><section class="actools-text-image actools-text-image--media-${position}"><div class="actools-text-image__content"><h2>Reading a meniscus</h2><div class="actools-text-image__body"><p>The complete diagram stays visible.</p></div></div><div class="actools-text-image__media"><img src="${image(1600, 900)}" width="1600" height="900" alt="Meniscus diagram"></div></section></div></main></body></html>`);
      await page.waitForFunction(() => document.images[0].complete && document.images[0].naturalWidth);
      const text = await page.locator('.actools-text-image__content').boundingBox();
      const media = await page.locator('.actools-text-image__media').boundingBox();
      const img = await page.locator('img').boundingBox();
      expect(img.width).toBeLessThanOrEqual(media.width + 1);
      expect(img.width / img.height).toBeCloseTo(1600 / 900, 2);
      if (available < 640) expect(media.y).toBeGreaterThanOrEqual(text.y + text.height);
      else if (position === 'left') expect(media.x + media.width).toBeLessThanOrEqual(text.x);
      else expect(text.x + text.width).toBeLessThanOrEqual(media.x);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.emulateMedia({ media: 'print' });
      expect(await page.locator('.actools-text-image').evaluate(el => getComputedStyle(el).display)).toBe('block');
    });
  }
}
