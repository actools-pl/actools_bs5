const { test, expect } = require('playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

if (!process.env.ACTOOLS_GL_FIXTURE || !process.env.ACTOOLS_GL_EDITOR_PASSWORD || !process.env.ACTOOLS_GL_ADMIN_PASSWORD) {
  throw new Error('Seed the disposable Drupal Generic Lesson fixture before running this suite.');
}
const fixture = JSON.parse(fs.readFileSync(process.env.ACTOOLS_GL_FIXTURE, 'utf8'));
const reader = page => page.locator('.generic-lesson--reader');
const header = page => page.locator('.page > header[role="banner"]');
const quiz = (page, name) => page.locator(`.gl-quiz[data-quiz-id="${fixture.quiz[name].id}"]`);
const step = (page, index) => reader(page).locator(`.lesson-step[data-step-id="${fixture.stepIds[index]}"]`);
const errorsByPage = new WeakMap();

test.beforeEach(async ({ page }) => {
  const errors = [];
  errorsByPage.set(page, errors);
  page.on('pageerror', error => errors.push(error.message));
  await page.emulateMedia({ reducedMotion: 'reduce' });
});
test.afterEach(async ({ page }, info) => {
  if (info.status !== info.expectedStatus) {
    console.error('Generic Lesson browser failure:', JSON.stringify({ url: page.url(), errors: errorsByPage.get(page), geometry: await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, focus: document.activeElement?.outerHTML, css: document.documentElement.getAttribute('style'), scrollPadding: getComputedStyle(document.documentElement).scrollPaddingTop, chrome: [...document.querySelectorAll('.page > header[role="banner"], [data-offset-top], [data-actools-nav-toggle]')].map(element => ({ tag: element.tagName, id: element.id, classes: element.className, offset: element.getAttribute('data-offset-top'), position: getComputedStyle(element).position, top: element.getBoundingClientRect().top, bottom: element.getBoundingClientRect().bottom })) })) }));
    await info.attach('lesson-page.html', { body: await page.content(), contentType: 'text/html' });
  }
});

async function visit(page, url) {
  const response = await page.goto(url);
  expect(response?.status(), `Drupal route ${url}`).toBe(200);
  return response;
}
async function login(page, admin = false) {
  await visit(page, '/user/login');
  await page.getByLabel('Username', { exact: true }).fill(admin ? fixture.adminUsername : fixture.username);
  await page.getByLabel('Password', { exact: true }).fill(process.env[admin ? 'ACTOOLS_GL_ADMIN_PASSWORD' : 'ACTOOLS_GL_EDITOR_PASSWORD']);
  await page.getByRole('button', { name: 'Log in', exact: true }).click();
  await expect(page).not.toHaveURL(/\/user\/login$/);
}
async function lesson(page) {
  await visit(page, fixture.lessonPath);
  await expect(reader(page)).toHaveClass(/\bis-enhanced\b/);
  await mathReady(page);
}
async function mathReady(page) {
  await expect(reader(page).locator('mjx-container').first()).toBeAttached();
  await page.evaluate(async () => { await window.MathJax.startup.promise; await document.fonts.ready; });
}
async function goStep(page, index) {
  const link = reader(page).locator(`.lesson-outline__item[data-step-target="${fixture.stepIds[index]}"] a`);
  await link.click();
  await expect(step(page, index)).toBeVisible();
}
async function noOverflow(page) {
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, actual: document.documentElement.scrollWidth }));
  expect(dimensions.actual, JSON.stringify(dimensions)).toBeLessThanOrEqual(dimensions.viewport);
}
async function screenshot(page, info, name) {
  const directory = process.env.ACTOOLS_GL_BROWSER_OUTPUT || info.project.outputDir;
  fs.mkdirSync(directory, { recursive: true });
  await page.evaluate(async () => { await document.fonts.ready; });
  await page.screenshot({ path: path.join(directory, `${name}.png`), fullPage: false });
}
function scenario(name, baseURL) {
  if (process.env.ACTOOLS_GL_THEME_FIXTURE !== '1' || !process.env.ACTOOLS_GL_DRUSH || !process.env.ACTOOLS_GL_PROJECT) {
    throw new Error('Presentation changes require the guarded disposable fixture.');
  }
  execFileSync(process.env.ACTOOLS_GL_DRUSH, [`--uri=${baseURL}`, 'php:script', path.join(__dirname, 'configure-generic-lesson-site.php')], {
    cwd: process.env.ACTOOLS_GL_PROJECT, env: { ...process.env, ACTOOLS_GL_SCENARIO: name }, timeout: 30000, stdio: 'pipe',
  });
  execFileSync(process.env.ACTOOLS_GL_DRUSH, [`--uri=${baseURL}`, 'cache:rebuild'], { cwd: process.env.ACTOOLS_GL_PROJECT, env: process.env, timeout: 30000, stdio: 'pipe' });
}
async function readable(locator) {
  const painted = await locator.evaluate(element => {
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const rgba = color => { context.clearRect(0, 0, 1, 1); context.fillStyle = color; context.fillRect(0, 0, 1, 1); const values = [...context.getImageData(0, 0, 1, 1).data]; return [...values.slice(0, 3), values[3] / 255]; };
    const over = (a, b) => [0, 1, 2].map(i => a[i] * a[3] + b[i] * (1 - a[3])).concat(1);
    const ancestors = []; for (let node = element; node; node = node.parentElement) ancestors.unshift(node);
    let background = [255, 255, 255, 1];
    for (const ancestor of ancestors) background = over(rgba(getComputedStyle(ancestor).backgroundColor), background);
    return { background, foreground: over(rgba(getComputedStyle(element).color), background) };
  });
  const luminance = color => color.slice(0, 3).map(value => { value /= 255; return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4; }).reduce((sum, value, i) => sum + value * [0.2126, 0.7152, 0.0722][i], 0);
  const a = luminance(painted.foreground), b = luminance(painted.background);
  expect((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05), `Text contrast ${JSON.stringify(painted)}`).toBeGreaterThanOrEqual(4.5);
}
async function inputBoundary(locator) {
  const colors = await locator.evaluate(element => {
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const rgba = color => { context.clearRect(0, 0, 1, 1); context.fillStyle = color; context.fillRect(0, 0, 1, 1); const values = [...context.getImageData(0, 0, 1, 1).data]; return [...values.slice(0, 3), values[3] / 255]; };
    const over = (a, b) => [0, 1, 2].map(i => a[i] * a[3] + b[i] * (1 - a[3])).concat(1);
    const ancestors = []; for (let node = element; node; node = node.parentElement) ancestors.unshift(node);
    let background = [255, 255, 255, 1];
    for (const ancestor of ancestors) background = over(rgba(getComputedStyle(ancestor).backgroundColor), background);
    return { background, border: over(rgba(getComputedStyle(element).borderTopColor), background) };
  });
  const luminance = color => color.slice(0, 3).map(value => { value /= 255; return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4; }).reduce((sum, value, i) => sum + value * [0.2126, 0.7152, 0.0722][i], 0);
  const a = luminance(colors.border), b = luminance(colors.background);
  expect((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05), `Input boundary contrast ${JSON.stringify(colors)}`).toBeGreaterThanOrEqual(3);
}
async function visibleFocus(locator, page) {
  await page.keyboard.press('ArrowDown');
  await locator.focus();
  await expect(locator).toBeFocused();
  const outline = await locator.evaluate(element => ({ style: getComputedStyle(element).outlineStyle, width: parseFloat(getComputedStyle(element).outlineWidth) }));
  expect(outline.style).not.toBe('none');
  expect(outline.width).toBeGreaterThanOrEqual(2);
}
async function targetClearance(page, target, tight = false) {
  await expect(target).toBeInViewport();
  await expect.poll(async () => target.evaluate(element => element.getBoundingClientRect().top - document.querySelector('.page > header[role="banner"]').getBoundingClientRect().bottom)).toBeGreaterThanOrEqual(6);
  if (tight) {
    const gap = await target.evaluate(element => element.getBoundingClientRect().top - document.querySelector('.page > header[role="banner"]').getBoundingClientRect().bottom);
    expect(gap, 'Anchor offset must not count the theme sticky header twice.').toBeLessThanOrEqual(55);
  }
}

test('integration stylesheet follows the module attachment and leaves ordinary pages alone', async ({ page }) => {
  await lesson(page);
  await expect(page.locator('link[rel="stylesheet"][href*="generic-lesson.theme.css"]')).toHaveCount(1);
  await expect(page.locator('#MathJax-script')).toHaveAttribute('src', /\/libraries\/mathjax\/es5\/tex-chtml\.js/);
  const files = await page.locator('link[rel="stylesheet"]').evaluateAll(elements => elements.map(element => element.href));
  expect(files.findIndex(file => file.includes('generic-lesson.theme.css'))).toBeGreaterThan(files.findIndex(file => /generic_lesson\/css\/lesson.css/.test(file)));
  await visit(page, fixture.ordinaryPath);
  await expect(page.locator('.generic-lesson')).toHaveCount(0);
  await expect(page.locator('link[rel="stylesheet"][href*="generic-lesson.theme.css"]')).toHaveCount(0);
  expect(errorsByPage.get(page)).toEqual([]);
});

test('light and dark lessons remain readable and fit phones, tablet and desktop with pale brand and larger text', async ({ page }, info) => {
  try {
    for (const settings of ['default', 'pale-large']) {
      scenario(settings, info.project.use.baseURL);
      for (const mode of ['light', 'dark']) {
        // Deliberately oppose operating-system preference: the theme selector wins.
        await page.emulateMedia({ colorScheme: mode === 'light' ? 'dark' : 'light', reducedMotion: 'reduce' });
        for (const width of [320, 390, 768, 1440]) {
          await page.setViewportSize({ width, height: 1000 });
          await lesson(page);
          await page.locator('html').evaluate((element, value) => element.setAttribute('data-bs-theme', value), mode);
          await readable(step(page, 0).locator('.lesson-step__title'));
          await noOverflow(page);
          if (settings === 'default' && mode === 'light' && [390, 1440].includes(width)) await screenshot(page, info, `lesson-introduction-${width}`);
          await goStep(page, fixture.quizStepIndex);
          for (const name of ['single', 'numerical', 'matrix']) {
            const question = quiz(page, name);
            await expect(question).toBeVisible();
            await readable(question.locator('.gl-quiz__hint'));
            await readable(question.locator('.gl-quiz__submit'));
          }
          const numeric = quiz(page, 'numerical').getByRole('textbox', { name: 'Your answer' });
          await numeric.fill('12');
          await readable(numeric);
          await inputBoundary(numeric);
          await visibleFocus(numeric, page);
          await visibleFocus(quiz(page, 'matrix').getByRole('region', { name: 'Answer matching grid' }), page);
          await noOverflow(page);
          if (settings === 'pale-large' && width === 390) {
            await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
            await noOverflow(page);
            await expect(numeric).toBeVisible();
          }
          if ([390, 1440].includes(width)) {
            await quiz(page, 'numerical').scrollIntoViewIfNeeded();
            await screenshot(page, info, `lesson-${settings}-${mode}-${width}`);
          }
        }
      }
    }
  }
  finally { scenario('default', info.project.use.baseURL); }
  expect(errorsByPage.get(page)).toEqual([]);
});

test('keyboard step and in-text anchors clear the sticky header without doubled offsets', async ({ page }, info) => {
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto('about:blank');
    await visit(page, `${fixture.lessonPath}#${fixture.targetFragment}`);
    await expect(reader(page)).toHaveClass(/\bis-enhanced\b/);
    await mathReady(page);
    await targetClearance(page, page.locator(`#${fixture.targetFragment}`), true);
    await lesson(page);
    const outline = reader(page).locator(`.lesson-outline__item[data-step-target="${fixture.stepIds[1]}"] a`);
    await visibleFocus(outline, page);
    await outline.press('Enter');
    await expect(step(page, 1).locator('.lesson-step__title')).toBeFocused();
    await targetClearance(page, step(page, 1), true);
    await goStep(page, 0);
    const anchor = reader(page).locator(`a[href="#${fixture.targetFragment}"]`).first();
    await visibleFocus(anchor, page);
    await anchor.press('Enter');
    const destination = page.locator(`#${fixture.targetFragment}`);
    await expect(destination).toBeFocused();
    await targetClearance(page, destination, true);
    await visibleFocus(destination, page);
    await noOverflow(page);
  }
  try {
    scenario('sticky-off', info.project.use.baseURL);
    await lesson(page);
    await goStep(page, 1);
    expect(await header(page).evaluate(element => getComputedStyle(element).position)).not.toBe('sticky');
    const top = await step(page, 1).evaluate(element => element.getBoundingClientRect().top);
    expect(top).toBeGreaterThanOrEqual(8);
    expect(top).toBeLessThanOrEqual(40);
  }
  finally { scenario('default', info.project.use.baseURL); }
});

test('core toolbar and its horizontal tray stay above lesson headings and controls', async ({ page }, info) => {
  await login(page, true);
  for (const width of [320, 390, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await lesson(page);
    await expect(page.locator('#toolbar-administration .toolbar-bar')).toBeVisible();
    if (width < 1024 && await page.locator('#toolbar-item-administration').getAttribute('aria-pressed') === 'true') await page.locator('#toolbar-item-administration').click();
    if (width === 1440) {
      const manage = page.locator('#toolbar-item-administration');
      if (await manage.getAttribute('aria-pressed') !== 'true') await manage.click();
      if (!(await page.locator('body').getAttribute('class')).includes('toolbar-horizontal')) await page.locator('.toolbar-toggle-orientation button').click();
      await expect(page.locator('body')).toHaveClass(/\btoolbar-horizontal\b/);
    }
    await goStep(page, 1);
    // Core's phone toolbar is in normal flow and can scroll away during the
    // anchor jump. Preserve native placement, requiring the heading to clear
    // every currently visible bar rather than forcing a second jump.
    await targetClearance(page, step(page, 1));
    const geometry = await header(page).evaluate(element => {
      const bars = [...document.querySelectorAll('.toolbar-bar[data-offset-top], .toolbar-tray.is-active[data-offset-top]')].filter(bar => bar.getClientRects().length && bar.getBoundingClientRect().bottom > 0 && bar.getBoundingClientRect().top < innerHeight);
      return { top: element.getBoundingClientRect().top, offset: Math.max(0, ...bars.map(bar => bar.getBoundingClientRect().bottom)) };
    });
    console.log('Toolbar geometry', width, JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('.page > header[role="banner"], .page > header[role="banner"] .contextual-region, [data-offset-top], [data-actools-nav-toggle]')].map(element => ({ id: element.id, classes: element.className, position: getComputedStyle(element).position, top: element.getBoundingClientRect().top, bottom: element.getBoundingClientRect().bottom, offsetParent: element.offsetParent?.className })))));
    const menuToggle = page.locator('[data-actools-nav-region="header"] [data-actools-nav-toggle]');
    if (await menuToggle.isVisible()) {
      const toggleBounds = await menuToggle.boundingBox();
      const bannerBounds = await header(page).boundingBox();
      expect(toggleBounds.y).toBeGreaterThanOrEqual(bannerBounds.y);
      expect(toggleBounds.y + toggleBounds.height, 'The contextual menu block must not shift its toggle over lesson content.').toBeLessThanOrEqual(bannerBounds.y + bannerBounds.height);
      const brand = header(page).getByRole('link', { name: 'Aarin Learning', exact: true });
      const brandBounds = await brand.boundingBox();
      expect(brandBounds.x + brandBounds.width).toBeLessThanOrEqual(toggleBounds.x);
      const triggers = header(page).locator('.contextual > button.trigger');
      await expect(triggers).toHaveCount(2);
      for (const trigger of await triggers.all()) {
        await trigger.focus();
        await expect(trigger).toBeVisible();
        const bounds = await trigger.boundingBox();
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(toggleBounds.x);
        await trigger.press('Enter');
        await expect(trigger).toHaveAttribute('aria-pressed', 'true');
        const links = trigger.locator('..').locator('.contextual-links a');
        await expect(links.first()).toBeVisible();
        await trigger.press('Enter');
        await expect(trigger).toHaveAttribute('aria-pressed', 'false');
      }
    }
    expect(geometry.top).toBeGreaterThanOrEqual(geometry.offset - 1);
    await noOverflow(page);
    await screenshot(page, info, `lesson-toolbar-${width}`);
  }
});

async function answer(page, name) {
  const question = quiz(page, name);
  const correct = fixture.quiz[name].correct;
  if (name === 'single') await question.locator(`input[value="${correct[0]}"]`).check();
  else if (name === 'numerical') await question.getByRole('textbox', { name: 'Your answer' }).fill(String(correct));
  else {
    for (const [row, columns] of Object.entries(correct)) {
      for (const column of columns) await question.getByRole('checkbox', { name: `${row} matches ${column}`, exact: true }).check();
    }
  }
  await question.getByRole('button', { name: 'Check answer', exact: true }).click();
  await expect(question.locator('.gl-quiz__verdict')).toContainText('Correct');
  await expect(question.locator('.gl-quiz__submit')).toBeDisabled();
  await readable(question.locator('.gl-quiz__verdict'));
}

test('signed-in learners submit and restore MCQ, numeric and matrix answers, then save and reset reading progress', async ({ page }, info) => {
  scenario('reset-learner', info.project.use.baseURL);
  await login(page);
  await lesson(page);
  const progress = reader(page).getByRole('progressbar', { name: 'Lesson progress' });
  await expect(progress).toHaveAttribute('aria-valuenow', '0');
  await goStep(page, fixture.quizStepIndex);
  for (const name of ['single', 'numerical', 'matrix']) await answer(page, name);
  await page.reload();
  await expect(reader(page)).toHaveClass(/\bis-enhanced\b/);
  await goStep(page, fixture.quizStepIndex);
  for (const name of ['single', 'numerical', 'matrix']) {
    await expect(quiz(page, name).locator('.gl-quiz__verdict')).toContainText('Correct');
    await expect(quiz(page, name).locator('.gl-quiz__submit')).toBeDisabled();
  }
  await goStep(page, 0);
  await reader(page).getByRole('button', { name: 'Mark complete', exact: true }).click();
  await expect(progress).not.toHaveAttribute('aria-valuenow', '0');
  await page.reload();
  await expect(reader(page)).toHaveClass(/\bis-enhanced\b/);
  await expect(progress).not.toHaveAttribute('aria-valuenow', '0');
  page.once('dialog', dialog => dialog.accept());
  await reader(page).getByRole('button', { name: 'Start over', exact: true }).click();
  await expect(progress).toHaveAttribute('aria-valuenow', '0');
  await goStep(page, fixture.quizStepIndex);
  for (const name of ['single', 'numerical', 'matrix']) await expect(quiz(page, name).locator('.gl-quiz__verdict')).toContainText('Correct');
  expect(errorsByPage.get(page)).toEqual([]);
});

test('wide teaching figures and tables stay inside the reader and retain native table semantics', async ({ page }, info) => {
  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await lesson(page);
    await goStep(page, 0);
    await noOverflow(page);
    const table = reader(page).getByRole('table', { name: fixture.tableCaption });
    await expect(table).toBeVisible();
    expect(await table.getByRole('columnheader').count()).toBeGreaterThanOrEqual(3);
    const dimensions = await table.evaluate(element => ({ width: element.clientWidth, scroll: element.scrollWidth }));
    if (dimensions.scroll > dimensions.width) {
      await table.focus();
      await expect(table).toBeFocused();
      await table.press('ArrowRight');
      await expect.poll(() => table.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
    }
    for (const image of await reader(page).locator('img').all()) {
      const geometry = await image.evaluate(element => ({ width: element.getBoundingClientRect().width, natural: element.naturalWidth }));
      expect(geometry.width).toBeLessThanOrEqual(width);
      expect(geometry.natural).toBeGreaterThan(0);
    }
    if (width === 390) await screenshot(page, info, 'lesson-figures-table-phone');
  }
});

test('printing includes every step and public worked/practice solutions without revealing unresolved quiz keys', async ({ page }, info) => {
  await lesson(page);
  const initialHidden = await reader(page).locator('.lesson-step').evaluateAll(elements => elements.map(element => element.hidden));
  expect(initialHidden.some(Boolean)).toBe(true);
  const body = await page.content();
  expect(body).not.toContain(fixture.secretSolution);
  await page.emulateMedia({ media: 'print' });
  await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
  for (let index = 0; index < fixture.stepIds.length; index++) await expect(step(page, index)).toBeVisible();
  for (const disclosure of await reader(page).locator('details.gl-print-open').all()) await expect(disclosure).toHaveAttribute('open', '');
  await expect(reader(page).getByText(fixture.workedSolution, { exact: false })).toBeVisible();
  await expect(reader(page).getByText(fixture.practiceSolution, { exact: false })).toBeVisible();
  await expect(reader(page).locator('.generic-lesson__controls')).toBeHidden();
  await expect(header(page)).toBeHidden();
  expect(await page.content()).not.toContain(fixture.secretSolution);
  const directory = process.env.ACTOOLS_GL_BROWSER_OUTPUT || info.project.outputDir;
  await page.pdf({ path: path.join(directory, 'generic-lesson-print.pdf'), format: 'A4', printBackground: true });
  await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
  await page.emulateMedia({ media: 'screen' });
  expect(await reader(page).locator('.lesson-step').evaluateAll(elements => elements.map(element => element.hidden))).toEqual(initialHidden);
});

test('without JavaScript every step and public disclosure remains accessible on a phone', async ({ browser }, info) => {
  const context = await browser.newContext({ baseURL: info.project.use.baseURL, javaScriptEnabled: false, viewport: { width: 320, height: 1000 } });
  const page = await context.newPage();
  try {
    await visit(page, fixture.lessonPath);
    for (let index = 0; index < fixture.stepIds.length; index++) await expect(step(page, index)).toBeVisible();
    await expect(reader(page).locator('.generic-lesson__controls')).toBeHidden();
    await expect(quiz(page, 'numerical').getByText('Enable JavaScript to check your answer.', { exact: true })).toBeVisible();
    await expect(quiz(page, 'numerical').getByRole('button', { name: 'Check answer', exact: true })).toBeHidden();
    const solution = reader(page).locator('.gl-practice details.gl-example__solution');
    await solution.locator('summary').click();
    await expect(solution).toHaveAttribute('open', '');
    await expect(solution.getByText(fixture.practiceSolution, { exact: false })).toBeVisible();
    expect(await page.content()).not.toContain(fixture.secretSolution);
    await noOverflow(page);
    await screenshot(page, info, 'lesson-no-js-phone');
  }
  finally { await context.close(); }
});

test('continuous lessons receive theme styling while keeping every step visible', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 1000 });
  await visit(page, fixture.flatLessonPath);
  await expect(page.locator('.generic-lesson')).toHaveCount(1);
  await expect(page.locator('link[rel="stylesheet"][href*="generic-lesson.theme.css"]')).toHaveCount(1);
  await expect(page.locator('.generic-lesson__controls')).toBeHidden();
  for (const item of await page.locator('.generic-lesson .lesson-step').all()) await expect(item).toBeVisible();
  await readable(page.locator('.generic-lesson .lesson-step__title').first());
  await noOverflow(page);
  await screenshot(page, info, 'lesson-flat-phone');
});


test('Layout Builder placement and the real Paragraphs editor retain lesson markup and editing controls', async ({ page }, info) => {
  await visit(page, fixture.layoutLessonPath);
  await expect(reader(page)).toHaveCount(1);
  await expect(reader(page)).toHaveClass(/\bis-enhanced\b/);
  await expect(page.locator('.layout-builder__layout')).toHaveCount(0);
  await expect(reader(page).locator('.lesson-step')).toHaveCount(1);
  await expect(reader(page).locator('.generic-lesson__controls')).toHaveCount(1);
  await expect(page.locator('link[rel="stylesheet"][href*="generic-lesson.theme.css"]')).toHaveCount(1);
  await noOverflow(page);
  await screenshot(page, info, 'lesson-layout-builder-display');
  await login(page, true);
  await visit(page, `${fixture.lessonPath}/edit`);
  await expect(page.locator('form.node-form')).toBeVisible();
  await expect(page.locator('[data-drupal-selector="edit-field-gl-content-wrapper"]')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save', exact: true })).toBeVisible();
  await expect(page.locator('script[src*="generic_lesson/js/option-editor.js"]')).toHaveCount(1);
  await visit(page, `${fixture.layoutLessonPath}/layout`);
  const addBlock = page.getByRole('link', { name: /^Add block\b/ }).first();
  await expect(addBlock).toBeVisible();
  await expect(addBlock).toHaveAttribute('data-once', /\bajax\b/);
  await addBlock.click();
  await expect(page.locator('#drupal-off-canvas')).toBeVisible();
  await expect(page.getByRole('dialog', { name: 'Choose a block', exact: true })).toBeVisible();
  await expect(page.locator('#drupal-off-canvas').getByRole('searchbox', { name: 'Enter a part of the block name to filter by.' })).toBeVisible();
  await expect(page.locator('#drupal-off-canvas').getByRole('link', { name: 'Lesson content', exact: true })).toBeVisible();
  await screenshot(page, info, 'lesson-layout-builder-editor');
  expect(errorsByPage.get(page)).toEqual([]);
});
