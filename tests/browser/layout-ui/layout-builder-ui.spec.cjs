const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

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
  await page.goto(fixture.topicPath);
  await page.getByRole('link', { name: 'Layout', exact: true }).click();
  await expect(page).toHaveURL(`${testInfo.project.use.baseURL}${fixture.topicPath}/layout`);

  const createText = await openInlineBlock(page, 'Actools: Text and image');
  await createText.locator('[name="settings[label]"]').fill('Meniscus editor block');
  await createText.locator('[name="settings[block_form][field_actools_heading][0][value]"]').fill('Reading a meniscus');
  await createText.locator('[name="settings[block_form][field_actools_body][0][value]"]').fill('First explanation written in the live layout form.');
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
      await expect(publicPage.getByText('Revised explanation saved through the editor.', { exact: true })).toBeVisible();
      await expect(publicPage.getByText('First explanation written in the live layout form.', { exact: true })).toHaveCount(0);
      await expect(publicPage.locator('.actools-text-image--media-left img')).toHaveAttribute('alt', 'Complete meniscus diagram');
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
