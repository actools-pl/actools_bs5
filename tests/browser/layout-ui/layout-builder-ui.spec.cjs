const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const fixturePath = process.env.ACTOOLS_LAYOUT_FIXTURE;
if (!fixturePath || !process.env.ACTOOLS_LAYOUT_EDITOR_PASSWORD) {
  throw new Error('Run the disposable-site workflow before the Layout Builder UI suite.');
}
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const browserErrors = new WeakMap();

test.beforeEach(async ({ page }) => {
  const errors = [];
  browserErrors.set(page, errors);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
});

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) {
    // Text diagnostics are also in the job log so a failed first interaction
    // can be diagnosed without downloading the screenshot/trace archive.
    const diagnostics = {
      url: page.url(),
      errors: browserErrors.get(page),
      runtime: await page.evaluate(() => ({ readyState: document.readyState, Drupal: typeof window.Drupal, ajax: typeof window.Drupal?.ajax })),
      bodyText: (await page.locator('body').innerText()).slice(0, 12000),
      inputs: await page.locator('input, textarea, select').evaluateAll(elements => elements.map(element => ({
        tag: element.tagName, type: element.type, name: element.name,
      }))),
      links: await page.getByRole('link').evaluateAll(elements => elements.map(element => ({
        text: element.textContent, href: element.getAttribute('href'), classes: element.className, once: element.getAttribute('data-once'),
      }))),
      scripts: await page.locator('script[src]').evaluateAll(elements => elements.map(element => element.src)),
    };
    console.error('Layout Builder failure diagnostics:', JSON.stringify(diagnostics, null, 2));
    await testInfo.attach('failed-editor-page.html', { body: await page.content(), contentType: 'text/html' });
  }
});

// These are real off-canvas forms and contextual links. No request.post,
// evaluate(DOM mutation), Drush, entity API or direct layout endpoint shortcut
// performs the create/edit/move/save operations under test.
async function clickAjaxLink(link) {
  // Core attaches these behaviors asynchronously after a page/dialog update.
  // A visible link can otherwise still perform its non-JS fallback navigation.
  await expect(link).toHaveAttribute('data-once', /\bajax\b/);
  await link.click();
}

async function clickAjaxButton(button) {
  await expect(button).toHaveAttribute('data-once', /\bdrupal-ajax\b/);
  await button.click();
}

async function openInlineBlock(page, label) {
  await clickAjaxLink(page.getByRole('link', { name: /^Add block\b/ }).first());
  const dialog = page.locator('#drupal-off-canvas');
  await expect(dialog).toBeVisible();
  await clickAjaxLink(dialog.getByRole('link', { name: /^Create (content|custom) block$/ }));
  await clickAjaxLink(dialog.getByRole('link', { name: label, exact: true }));
  await expect(dialog.locator('[name="settings[label]"]')).toBeVisible();
  return dialog;
}

async function contextualAction(page, block, label) {
  const trigger = block.locator('.contextual .trigger');
  await expect(trigger).toHaveCount(1);
  // Core contextual triggers become visible on keyboard focus. Focusing is a
  // real editor interaction and does not remove classes or force-click links.
  await trigger.focus();
  await trigger.press('Enter');
  await expect(trigger).toHaveAttribute('aria-pressed', 'true');
  const action = block.getByRole('link', { name: label, exact: true });
  await expect(action).toBeVisible();
  await expect(action).toHaveAttribute('data-once', /\bajax\b/);
  // Stay on the keyboard after opening the contextual menu. A synthetic
  // pointer jump from its old off-canvas position can cross another region
  // and legitimately close this menu via core's mouseleave handler.
  await action.focus();
  await action.press('Enter');
  await expect(page.locator('#drupal-off-canvas')).toBeVisible();
  return page.locator('#drupal-off-canvas');
}

async function chooseResource(page, dialog, delta, resource) {
  const input = dialog.locator(`[name="settings[block_form][field_actools_resources][${delta}][target_id]"]`);
  await expect(input).toHaveAttribute('data-once', /\bautocomplete\b/);
  await input.fill(resource.title);
  const suggestion = page.locator('.ui-autocomplete .ui-menu-item').filter({ hasText: resource.title });
  await expect(suggestion).toBeVisible();
  await suggestion.click();
  await expect(input).toHaveValue(`${resource.title} (${resource.id})`);
}

test('editor creates, edits, reorders and saves inline blocks through Layout Builder', async ({ page, browser }, testInfo) => {
  const errors = browserErrors.get(page);

  await page.goto('/user/login');
  await page.getByLabel('Username', { exact: true }).fill(fixture.username);
  await page.getByLabel('Password', { exact: true }).fill(process.env.ACTOOLS_LAYOUT_EDITOR_PASSWORD);
  await page.getByRole('button', { name: 'Log in', exact: true }).click();

  // Metadata is edited through the ordinary Drupal node form, then checked on
  // its referenced card in the anonymously cached Topic below.
  await page.goto(fixture.resources[0].editPath);
  const kind = page.getByLabel('Resource kind', { exact: true });
  const level = page.getByLabel('Study level', { exact: true });
  await expect(kind).toHaveAttribute('name', 'field_actools_kind');
  await expect(level).toHaveAttribute('name', 'field_actools_level');
  await expect(kind).toHaveValue('notes');
  await expect(level).toHaveValue('foundation');
  await kind.selectOption('worked_examples');
  await level.selectOption('jee_advanced');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page).toHaveURL(`${testInfo.project.use.baseURL}/node/${fixture.resources[0].id}`);
  await page.goto(fixture.resources[0].editPath);
  await expect(page.getByLabel('Resource kind', { exact: true })).toHaveValue('worked_examples');
  await expect(page.getByLabel('Study level', { exact: true })).toHaveValue('jee_advanced');

  await page.goto(fixture.topicPath);
  await page.getByRole('link', { name: 'Layout', exact: true }).click();
  await expect(page).toHaveURL(`${testInfo.project.use.baseURL}${fixture.topicPath}/layout`);

  const createText = await openInlineBlock(page, 'Actools: Text and image');
  await createText.locator('[name="settings[label]"]').fill('Meniscus editor block');
  await createText.locator('[name="settings[block_form][field_actools_heading][0][value]"]').fill('Reading a meniscus');
  await createText.locator('[name="settings[block_form][field_actools_body][0][value]"]').fill('First explanation written in the live layout form.');
  const createCaption = createText.getByLabel('Image caption', { exact: true });
  await expect(createCaption).toHaveAttribute('name', 'settings[block_form][field_actools_caption][0][value]');
  await expect(createCaption).toHaveJSProperty('required', false);
  await createCaption.fill('First caption written in the live layout form.');
  await createText.getByLabel('Image position', { exact: true }).selectOption('right');
  await expect(createText.locator('input[type="file"]')).toHaveAttribute('data-once', /\bauto-file-upload\b/);
  await createText.locator('input[type="file"]').setInputFiles(fixture.imagePath);
  const alt = createText.locator('[name="settings[block_form][field_actools_image][0][alt]"]');
  await expect(alt).toBeVisible();
  await expect(alt).toHaveJSProperty('required', true);
  await alt.fill('Complete meniscus diagram');
  await clickAjaxButton(createText.getByRole('button', { name: 'Add block', exact: true }));
  await expect(page.locator('#drupal-off-canvas')).toHaveCount(0);
  const textBlock = page.locator('[data-layout-block-uuid]').filter({ has: page.locator('.actools-text-image') });
  await expect(textBlock).toContainText('Reading a meniscus');

  const editText = await contextualAction(page, textBlock, 'Configure');
  await expect(editText.locator('[name="settings[block_form][field_actools_body][0][value]"]')).toHaveValue('First explanation written in the live layout form.');
  await editText.locator('[name="settings[block_form][field_actools_heading][0][value]"]').fill('Understanding a meniscus');
  await editText.locator('[name="settings[block_form][field_actools_body][0][value]"]').fill('Revised explanation saved through the editor.');
  await expect(editText.getByLabel('Image caption', { exact: true })).toHaveValue('First caption written in the live layout form.');
  await editText.getByLabel('Image caption', { exact: true }).fill('Figure 1. Capillary rise in a narrow glass tube.');
  await editText.getByLabel('Image position', { exact: true }).selectOption('left');
  await clickAjaxButton(editText.getByRole('button', { name: 'Update', exact: true }));
  await expect(page.locator('#drupal-off-canvas')).toHaveCount(0);
  await expect(textBlock).toContainText('Understanding a meniscus');

  const createCards = await openInlineBlock(page, 'Actools: Resource cards');
  await createCards.locator('[name="settings[label]"]').fill('Selected practice cards');
  await createCards.locator('[name="settings[block_form][field_actools_heading][0][value]"]').fill('Practice draft');
  await chooseResource(page, createCards, 0, fixture.resources[0]);
  await clickAjaxButton(createCards.getByRole('button', { name: 'Add another item', exact: true }));
  await chooseResource(page, createCards, 1, fixture.resources[1]);
  await clickAjaxButton(createCards.getByRole('button', { name: 'Add block', exact: true }));
  await expect(page.locator('#drupal-off-canvas')).toHaveCount(0);
  const cardsBlock = page.locator('[data-layout-block-uuid]').filter({ has: page.locator('.actools-resource-card') });
  await expect(cardsBlock).toContainText('Practice draft');
  await expect(cardsBlock.locator('.actools-resource-card')).toHaveCount(2);

  const editCards = await contextualAction(page, cardsBlock, 'Configure');
  await expect(editCards.locator('[name="settings[block_form][field_actools_heading][0][value]"]')).toHaveValue('Practice draft');
  await expect(editCards.locator('[name="settings[block_form][field_actools_resources][0][target_id]"]')).toHaveValue(`${fixture.resources[0].title} (${fixture.resources[0].id})`);
  await editCards.locator('[name="settings[block_form][field_actools_heading][0][value]"]').fill('Selected practice');
  await clickAjaxButton(editCards.getByRole('button', { name: 'Update', exact: true }));
  await expect(page.locator('#drupal-off-canvas')).toHaveCount(0);
  await expect(cardsBlock).toContainText('Selected practice');

  // Move the last-added cards above the text block with core's accessible
  // tabledrag keyboard handle, then submit the real Move form.
  const move = await contextualAction(page, cardsBlock, 'Move');
  const currentRow = move.locator('tr.layout-builder-components-table__row--current');
  const handle = currentRow.locator('a.tabledrag-handle');
  await handle.focus();
  await handle.press('ArrowUp');
  await handle.press('Tab');
  const rowLabels = move.locator('.layout-builder-components-table__block-label');
  await expect(rowLabels).toContainText(['Body', 'Resources', 'Selected practice cards (current)', 'Meniscus editor block']);
  await clickAjaxButton(move.getByRole('button', { name: 'Move', exact: true }));
  await expect(page.locator('#drupal-off-canvas')).toHaveCount(0);
  const pending = page.locator('[data-layout-block-uuid]').filter({ has: page.locator('.actools-resource-card, .actools-text-image') });
  await expect(pending).toHaveCount(2);
  await expect(pending.nth(0)).toContainText('Selected practice');
  await expect(pending.nth(1)).toContainText('Understanding a meniscus');

  await page.getByRole('button', { name: 'Save layout', exact: true }).click();
  await expect(page).toHaveURL(`${testInfo.project.use.baseURL}${fixture.topicPath}`);
  await page.reload();
  // Reopen the Layout tab and check the saved ordering, rather than only the
  // transient editor DOM immediately after a successful AJAX response.
  await page.getByRole('link', { name: 'Layout', exact: true }).click();
  await expect(pending.nth(0)).toContainText('Selected practice');
  await expect(pending.nth(1)).toContainText('Understanding a meniscus');

  const anonymous = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
  const publicPage = await anonymous.newPage();
  try {
    // Two independent GETs cover both a fresh and a warmed page/render cache.
    for (let pass = 0; pass < 2; pass++) {
      await publicPage.goto(fixture.topicPath);
      await expect(publicPage.getByRole('heading', { level: 1 })).toHaveCount(1);
      const blocks = publicPage.locator('.actools-resource-cards, .actools-text-image');
      await expect(blocks).toHaveCount(2);
      await expect(blocks.nth(0)).toContainText('Selected practice');
      await expect(blocks.nth(1)).toContainText('Understanding a meniscus');
      await expect(publicPage.locator('.actools-resource-card__heading')).toHaveText(fixture.resources.map(resource => resource.title));
      await expect(publicPage.locator('.actools-resource-card').nth(0).locator('.actools-resource-metadata__item')).toHaveText(['Worked examples', 'JEE Advanced']);
      await expect(publicPage.locator('.actools-resource-card').nth(1).locator('.actools-resource-metadata__item')).toHaveText(['Notes', 'Foundation']);
      await expect(publicPage.getByText('Revised explanation saved through the editor.', { exact: true })).toBeVisible();
      await expect(publicPage.getByText('First explanation written in the live layout form.', { exact: true })).toHaveCount(0);
      await expect(publicPage.locator('.actools-text-image--media-left img')).toHaveAttribute('alt', 'Complete meniscus diagram');
      await expect(publicPage.locator('figure.actools-text-image__media > figcaption.actools-text-image__caption')).toHaveText('Figure 1. Capillary rise in a narrow glass tube.');
      await expect(publicPage.getByText('First caption written in the live layout form.', { exact: true })).toHaveCount(0);
      await expect(publicPage.getByRole('link', { name: 'Layout', exact: true })).toHaveCount(0);
    }
    await testInfo.attach('anonymous-saved-topic.html', { body: await publicPage.content(), contentType: 'text/html' });
    await publicPage.goto(fixture.sharedTopicPath);
    await expect(publicPage.getByText('Shared default introduction.', { exact: true })).toBeVisible();
    await expect(publicPage.locator('.actools-text-image, .actools-resource-cards')).toHaveCount(0);
  }
  finally {
    await anonymous.close();
    await testInfo.attach('browser-errors.json', { body: JSON.stringify(errors, null, 2), contentType: 'application/json' });
  }
  expect(errors).toEqual([]);
});

async function waitForPublishingImages(page) {
  await page.evaluate(async () => { await document.fonts.ready; });
  // Let native lazy loading fetch real Drupal image derivatives by scrolling
  // them into view; do not replace the images or their loading attributes.
  const images = page.locator('main img');
  for (let index = 0; index < await images.count(); index++) {
    const image = images.nth(index);
    await image.scrollIntoViewIfNeeded();
    await expect.poll(() => image.evaluate(element => element.complete && element.naturalWidth > 0)).toBe(true);
  }
  await page.evaluate(() => window.scrollTo(0, 0));
}

test('published Surface tension topic presents grouped resources on desktop and phone', async ({ page }, testInfo) => {
  const errors = browserErrors.get(page);
  const outputDirectory = process.env.ACTOOLS_LAYOUT_BROWSER_OUTPUT || testInfo.project.outputDir;
  fs.mkdirSync(outputDirectory, { recursive: true });

  // The seeded preview is a saved core Layout Builder override rendered by
  // Drupal. It is separate from the two topics used for live editor operations.
  for (const [width, filename] of [[1440, 'topic-desktop.png'], [390, 'topic-phone.png']]) {
    await page.setViewportSize({ width, height: 1000 });
    for (let pass = 0; pass < 2; pass++) {
      const response = await page.goto(fixture.previewTopicPath);
      expect(response.status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Surface tension');
      await expect(page.getByText(fixture.previewIntroduction, { exact: true })).toHaveCount(1);
      await expect(page.getByText(fixture.previewIntroduction, { exact: true })).toBeVisible();
      await expect(page.getByRole('heading', { level: 2 })).toHaveText(fixture.previewHeadings);
      await expect(page.locator('.actools-resource-card__heading')).toHaveText(fixture.previewResources.map(resource => resource.title));
      await expect(page.locator('h3.actools-resource-card__heading')).toHaveCount(9);
      await expect(page.locator('.actools-resource-card')).toHaveCount(9);
      await expect(page.locator('.actools-resource-card__metadata.actools-resource-metadata')).toHaveCount(9);
      await expect(page.locator('.actools-resource-metadata__item')).toHaveCount(18);
      await expect(page.locator('figure.actools-text-image__media > figcaption.actools-text-image__caption')).toHaveText('Figure 1. Capillary rise in a narrow glass tube.');
      await expect(page.locator('#toolbar-administration, .toolbar-bar, [data-layout-block-uuid]')).toHaveCount(0);
      await expect(page.getByRole('link', { name: 'Layout', exact: true })).toHaveCount(0);

      for (const [index, resource] of fixture.previewResources.entries()) {
        const card = page.locator('.actools-resource-card').nth(index);
        await expect(card.locator('img')).toHaveCount(resource.hasImage ? 1 : 0);
        await expect(card.locator('.actools-resource-card__media')).toHaveCount(resource.hasImage ? 1 : 0);
        const order = await card.evaluate(element => [...element.children].map(child => child.className));
        expect(order[0]).toBe('actools-resource-card__content');
        if (resource.hasImage) expect(order[1]).toBe('actools-resource-card__media');
      }
    }

    await waitForPublishingImages(page);
    const titleBox = await page.getByRole('heading', { level: 1 }).boundingBox();
    const introductionBox = await page.getByText(fixture.previewIntroduction, { exact: true }).boundingBox();
    expect(Math.abs(titleBox.x - introductionBox.x)).toBeLessThanOrEqual(1);
    expect(introductionBox.y).toBeGreaterThanOrEqual(titleBox.y + titleBox.height);
    const layout = await page.evaluate(() => ({
      width: innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      sections: [...document.querySelectorAll('.actools-topic, .actools-text-image, .actools-resource-card, .actools-resource-cards__items')].map(element => {
        const box = element.getBoundingClientRect();
        return { className: element.className, left: box.left, right: box.right };
      }),
      headings: [...document.querySelectorAll('main h1, main h2, main h3')].map(element => Number(element.tagName.slice(1))),
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width);
    for (const section of layout.sections) {
      expect(section.left, section.className).toBeGreaterThanOrEqual(-1);
      expect(section.right, section.className).toBeLessThanOrEqual(layout.width + 1);
    }
    expect(layout.headings).toEqual([1, 2, 2, 3, 3, 3, 2, 3, 3, 3, 2, 3, 3, 3]);

    // A picture in the middle card must not push its identifying text below
    // its image-free neighbours. Metadata, title and summary lead every card.
    if (width === 1440) {
      const firstRowTitles = await page.locator('.actools-resource-card__heading').evaluateAll(elements => elements.slice(0, 3).map(element => element.getBoundingClientRect().top));
      expect(Math.max(...firstRowTitles) - Math.min(...firstRowTitles)).toBeLessThanOrEqual(1);
    }
    for (const card of await page.locator('.actools-resource-card').all()) {
      const image = card.locator('img');
      if (await image.count()) {
        const summary = await card.locator('.actools-resource-card__summary').boundingBox();
        const media = await card.locator('.actools-resource-card__media').boundingBox();
        expect(media.y).toBeGreaterThanOrEqual(summary.y + summary.height - 1);
      }
    }

    // This is a genuine 300 px native diagram in a wider desktop column; its
    // caption follows the figure's bounds rather than the available column.
    const figure = page.locator('figure.actools-text-image__media');
    const figureBox = await figure.boundingBox();
    const diagramBox = await figure.locator('img').boundingBox();
    const captionBox = await figure.locator('figcaption').boundingBox();
    expect(Math.abs(captionBox.x - figureBox.x)).toBeLessThanOrEqual(1);
    expect(Math.abs(captionBox.width - figureBox.width)).toBeLessThanOrEqual(1);
    expect(Math.abs(figureBox.width - diagramBox.width)).toBeLessThanOrEqual(1);
    expect(captionBox.y).toBeGreaterThanOrEqual(diagramBox.y + diagramBox.height);
    expect(diagramBox.width).toBeLessThanOrEqual(301);

    // Every visible native diagram keeps its complete aspect ratio. Both the
    // long-title and image-free cards remain in the real page under test.
    for (const image of await page.locator('main img').all()) {
      const proportions = await image.evaluate(element => {
        const box = element.getBoundingClientRect();
        return { rendered: box.width / box.height, intrinsic: element.naturalWidth / element.naturalHeight };
      });
      expect(proportions.rendered).toBeCloseTo(proportions.intrinsic, 2);
    }

    await page.emulateMedia({ media: 'print' });
    await expect(page.getByRole('heading', { level: 2 })).toHaveText(fixture.previewHeadings);
    for (const heading of await page.getByRole('heading', { level: 2 }).all()) await expect(heading).toBeVisible();
    await expect(page.locator('figcaption.actools-text-image__caption')).toBeVisible();
    await page.emulateMedia({ media: 'screen' });
    await waitForPublishingImages(page);
    await page.screenshot({ path: path.join(outputDirectory, filename), fullPage: true });
    await testInfo.attach(filename.replace('.png', '.html'), { body: await page.content(), contentType: 'text/html' });

    // Full Resource pages use the real native file formatter, public files and
    // anonymously warmed page cache. Descriptions identify the material while
    // type and size remain adjacent, readable information beside the link.
    for (const resource of fixture.previewResources.filter(resource => resource.download)) {
      const download = resource.download;
      for (let pass = 0; pass < 2; pass++) {
        const response = await page.goto(resource.path);
        expect(response.status()).toBe(200);
        const downloads = page.locator('.actools-resource-downloads');
        await expect(downloads).toHaveCount(1);
        await expect(downloads.locator('.actools-resource-downloads__item')).toHaveCount(1);
        const link = downloads.locator('.actools-resource-downloads__link a');
        await expect(link).toHaveText(download.description);
        expect(new URL(await link.getAttribute('href'), testInfo.project.use.baseURL).pathname).toBe(new URL(download.path, testInfo.project.use.baseURL).pathname);
        await expect(downloads.locator('.actools-resource-downloads__type')).toHaveText(download.type);
        await expect(downloads.locator('.actools-resource-downloads__size')).toHaveText(download.sizeLabel);
        await expect(downloads.locator('.actools-resource-downloads__metadata')).toBeVisible();
        const bounds = await downloads.evaluate(element => {
          const box = element.getBoundingClientRect();
          return { left: box.left, right: box.right, viewport: innerWidth, scroll: document.documentElement.scrollWidth };
        });
        expect(bounds.left).toBeGreaterThanOrEqual(-1);
        expect(bounds.right).toBeLessThanOrEqual(bounds.viewport + 1);
        expect(bounds.scroll).toBeLessThanOrEqual(bounds.viewport);
      }
      const fileUrl = new URL(download.path, testInfo.project.use.baseURL);
      expect(fileUrl.origin).toBe(new URL(testInfo.project.use.baseURL).origin);
      const fileResponse = await page.request.get(fileUrl.href);
      expect(fileResponse.status()).toBe(200);
      expect(fileResponse.headers()['content-type'].split(';')[0]).toBe(download.mime);
      const bytes = await fileResponse.body();
      expect(bytes.length).toBe(download.size);
      expect(bytes.subarray(0, download.type === 'PDF' ? 5 : 2).toString('ascii')).toBe(download.type === 'PDF' ? '%PDF-' : 'PK');
      await waitForPublishingImages(page);
      await page.screenshot({ path: path.join(outputDirectory, `resource-${download.type.toLowerCase()}-${width === 1440 ? 'desktop' : 'phone'}.png`), fullPage: true });
      await testInfo.attach(`resource-${download.type.toLowerCase()}-${width}.html`, { body: await page.content(), contentType: 'text/html' });
    }
  }

  expect(errors).toEqual([]);
});
