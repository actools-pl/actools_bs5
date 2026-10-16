const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

if (!process.env.ACTOOLS_NAV_FIXTURE || !process.env.ACTOOLS_NAV_EDITOR_PASSWORD) {
  throw new Error('Run the disposable Drupal navigation workflow before this suite.');
}
const fixture = JSON.parse(fs.readFileSync(process.env.ACTOOLS_NAV_FIXTURE, 'utf8'));
const errorsByPage = new WeakMap();
const header = page => page.locator('.page > header[role="banner"]');
const navigation = page => page.locator('[data-actools-navigation][data-actools-nav-region="header"]');
const footerNavigation = page => page.locator('[data-actools-navigation][data-actools-nav-region="footer"]');
const menuToggle = page => navigation(page).locator('[data-actools-nav-toggle]');
const panel = page => navigation(page).locator('[data-actools-nav-panel]');
const disclosure = (page, title) => navigation(page).getByRole('button', { name: `Toggle ${title} submenu`, exact: true });

test.beforeEach(async ({ page }) => {
  const errors = [];
  errorsByPage.set(page, errors);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
});
test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) {
    const diagnostics = {
      url: page.url(), errors: errorsByPage.get(page),
      body: (await page.locator('body').innerText()).slice(0, 18000),
      navigation: await navigation(page).evaluateAll(elements => elements.map(element => element.outerHTML)),
      geometry: await page.evaluate(() => ({
        viewport: [innerWidth, innerHeight], scroll: [scrollX, scrollY],
        rootStyle: document.documentElement.getAttribute('style'),
        focus: document.activeElement?.outerHTML,
      })),
    };
    console.error('Native navigation failure:', JSON.stringify(diagnostics, null, 2));
    await testInfo.attach('navigation-page.html', { body: await page.content(), contentType: 'text/html' });
  }
});

async function openMenu(page) {
  const toggle = menuToggle(page);
  if (await toggle.isVisible() && await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
}
async function openBranch(page, title) {
  const button = disclosure(page, title);
  if (await button.getAttribute('aria-expanded') !== 'true') await button.click();
}
async function assertNoOverflow(page) {
  const geometry = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
  expect(geometry.scroll).toBeLessThanOrEqual(geometry.width);
}
async function screenshot(page, testInfo, name) {
  const directory = process.env.ACTOOLS_NAV_BROWSER_OUTPUT || testInfo.project.outputDir;
  fs.mkdirSync(directory, { recursive: true });
  await page.evaluate(async () => { await document.fonts.ready; });
  await page.screenshot({ path: path.join(directory, `${fixture.adminInterface}-${name}.png`), fullPage: false });
  await testInfo.attach(`${name}.html`, { body: await page.content(), contentType: 'text/html' });
}
function scenario(name, baseURL) {
  if (process.env.ACTOOLS_NAV_UI_FIXTURE !== '1' || !process.env.ACTOOLS_NAV_DRUSH || !process.env.ACTOOLS_NAV_PROJECT) {
    throw new Error('Settings changes require the guarded disposable CI fixture.');
  }
  execFileSync(process.env.ACTOOLS_NAV_DRUSH, [
    `--uri=${baseURL}`, 'php:script', path.join(__dirname, 'configure-navigation-site.php'),
  ], {
    cwd: process.env.ACTOOLS_NAV_PROJECT,
    env: { ...process.env, ACTOOLS_NAV_SCENARIO: name },
    timeout: 30000, stdio: 'pipe',
  });
  execFileSync(process.env.ACTOOLS_NAV_DRUSH, [`--uri=${baseURL}`, 'cache:rebuild'], {
    cwd: process.env.ACTOOLS_NAV_PROJECT, env: process.env, timeout: 30000, stdio: 'pipe',
  });
}
async function login(page) {
  await page.goto('/user/login');
  await page.getByLabel('Username', { exact: true }).fill(fixture.username);
  await page.getByLabel('Password', { exact: true }).fill(process.env.ACTOOLS_NAV_EDITOR_PASSWORD);
  await page.getByRole('button', { name: 'Log in', exact: true }).click();
}
async function assertHeadingClearsHeader(page, id) {
  await expect.poll(async () => {
    const heading = await page.locator(`#${id}`).boundingBox();
    const banner = await header(page).boundingBox();
    return heading.y - (banner.y + banner.height);
  }).toBeGreaterThanOrEqual(6);
}
async function assertReadable(locator) {
  const painted = await locator.evaluate(element => {
    const rgba = colour => {
      const values = colour.match(/[\d.]+/g).map(Number);
      return [...values.slice(0, 3), values.length > 3 ? values[3] : 1];
    };
    const over = (foreground, background) => [0, 1, 2].map(index => foreground[index] * foreground[3] + background[index] * (1 - foreground[3])).concat(1);
    const ancestors = [];
    for (let current = element; current; current = current.parentElement) ancestors.unshift(current);
    let background = [255, 255, 255, 1];
    for (const ancestor of ancestors) background = over(rgba(getComputedStyle(ancestor).backgroundColor), background);
    return { background, foreground: over(rgba(getComputedStyle(element).color), background) };
  });
  const luminance = colour => {
    const channels = colour.slice(0, 3).map(channel => {
      const value = channel / 255;
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
  };
  const a = luminance(painted.foreground), b = luminance(painted.background);
  expect((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05), JSON.stringify(painted)).toBeGreaterThanOrEqual(4.5);
}

test('real header and footer menus preserve native links and fit all viewport boundaries', async ({ page }, testInfo) => {
  for (const width of [320, 390, 768, 991, 992, 1023, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(fixture.currentTopicPath);
    await expect(navigation(page)).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Primary navigation', exact: true })).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Footer topic navigation', exact: true })).toHaveCount(1);
    await expect(header(page).getByRole('link', { name: /Aarin Content Tools/ })).toBeVisible();
    await expect(menuToggle(page)).toBeVisible({ visible: width < 1024 });
    await openMenu(page);
    await openBranch(page, fixture.parentTitle);
    await openBranch(page, fixture.childTitle);
    const current = navigation(page).getByRole('link', { name: fixture.currentTopicTitle, exact: true });
    await expect(current).toBeVisible();
    await expect(current).toHaveAttribute('aria-current', 'page');
    await expect(navigation(page).locator('li.is-active-trail')).toHaveCount(3);
    await expect(navigation(page).getByText(fixture.unpublishedTitle, { exact: true })).toHaveCount(0);
    await expect(footerNavigation(page).locator('[data-actools-nav-toggle], [data-actools-submenu-toggle], [data-actools-nav-panel]')).toHaveCount(0);
    await expect(footerNavigation(page).getByRole('link', { name: fixture.currentTopicTitle, exact: true })).toBeVisible();
    const controlTargets = await navigation(page).locator('[aria-controls]').evaluateAll(elements => elements.map(element => ({
      id: element.getAttribute('aria-controls'), matches: document.querySelectorAll(`[id="${element.getAttribute('aria-controls')}"]`).length,
    })));
    expect(new Set(controlTargets.map(target => target.id)).size).toBe(controlTargets.length);
    expect(controlTargets.every(target => target.matches === 1)).toBe(true);
    await openBranch(page, 'Study support');
    const native = navigation(page).getByRole('link', { name: 'Jump to worked examples', exact: true });
    await expect(native).toHaveAttribute('data-publisher-link', 'preserved');
    await expect(native).toHaveAttribute('title', 'Read the worked examples');
    await expect(native).toHaveAttribute('rel', /\bhelp\b/);
    await expect(native).toHaveClass(/\bfixture-native-url\b/);
    const url = new URL(await native.getAttribute('href'), testInfo.project.use.baseURL);
    expect(url.searchParams.get('ref')).toBe('navigation');
    expect(url.hash).toBe('#worked-examples');
    await expect(navigation(page).getByRole('link', { name: 'Study support', exact: true })).toHaveCount(0);
    await assertNoOverflow(page);
  }
  expect(errorsByPage.get(page)).toEqual([]);
});

test('keyboard disclosures, Escape focus and resized panels remain usable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 850 });
  await page.goto(fixture.currentTopicPath);
  await menuToggle(page).focus();
  await menuToggle(page).press('Enter');
  await expect(panel(page)).toBeVisible();
  await openBranch(page, fixture.parentTitle);
  await openBranch(page, fixture.childTitle);
  const current = navigation(page).getByRole('link', { name: fixture.currentTopicTitle, exact: true });
  await current.focus();
  await current.press('Escape');
  await expect(disclosure(page, fixture.childTitle)).toBeFocused();
  await expect(disclosure(page, fixture.childTitle)).toHaveAttribute('aria-expanded', 'false');
  await disclosure(page, fixture.childTitle).press('Escape');
  await expect(disclosure(page, fixture.parentTitle)).toBeFocused();
  await disclosure(page, fixture.parentTitle).press('Escape');
  await expect(menuToggle(page)).toBeFocused();
  await expect(menuToggle(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(panel(page)).toBeHidden();
  await page.setViewportSize({ width: 1440, height: 850 });
  await expect(menuToggle(page)).toBeHidden();
  await expect(panel(page)).toBeVisible();
  await openBranch(page, fixture.parentTitle);
  await page.getByRole('heading', { level: 1 }).click();
  await page.setViewportSize({ width: 390, height: 850 });
  await expect(menuToggle(page)).toBeVisible();
  await expect(panel(page)).toBeHidden();
  await openMenu(page);
  await navigation(page).getByRole('link', { name: fixture.parentTitle, exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`${fixture.parentPath}$`));
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(fixture.parentTitle);
});

test('native hash navigation, history and skip links clear the measured sticky header', async ({ page }, testInfo) => {
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${fixture.currentTopicPath}#worked-examples`);
    await expect(page.locator('html')).toHaveClass(/\bactools-sticky-header-active\b/);
    await assertHeadingClearsHeader(page, 'worked-examples');
    await openMenu(page);
    await openBranch(page, 'Study support');
    await navigation(page).getByRole('link', { name: 'Continue to practice assignments', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${fixture.currentTopicPath}#practice-assignments$`));
    if (width < 1024) await expect(panel(page)).toBeHidden();
    await assertHeadingClearsHeader(page, 'practice-assignments');
    await page.goBack();
    await expect(page).toHaveURL(new RegExp(`${fixture.currentTopicPath}#worked-examples$`));
    await assertHeadingClearsHeader(page, 'worked-examples');
  }
  await page.goto(fixture.currentTopicPath);
  await page.keyboard.press('Tab');
  await expect(page.locator('.skip-link')).toBeFocused();
  await page.locator('.skip-link').press('Enter');
  await expect(page).toHaveURL(/#main-content$/);
  const clearance = await page.locator('#main-content').evaluate(element => element.getBoundingClientRect().top - document.querySelector('.page > header[role="banner"]').getBoundingClientRect().bottom);
  expect(clearance).toBeGreaterThanOrEqual(6);
  try {
    scenario('sticky-off', testInfo.project.use.baseURL);
    await page.goto(`${fixture.currentTopicPath}#practice-assignments`);
    await expect(page.locator('html')).not.toHaveClass(/\bactools-sticky-header-active\b/);
    expect(await header(page).evaluate(element => getComputedStyle(element).position)).not.toBe('sticky');
    expect((await header(page).boundingBox()).y).toBeLessThan(0);
    const padding = await page.locator('html').evaluate(element => parseFloat(getComputedStyle(element).scrollPaddingBlockStart) || 0);
    expect(padding).toBeLessThanOrEqual(1);
  }
  finally { scenario('default', testInfo.project.use.baseURL); }
});

test('installed core admin interface stays above the header and Layout Builder dialog', async ({ page }, testInfo) => {
  await login(page);
  for (const width of [390, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(fixture.currentTopicPath);
    if (fixture.adminInterface === 'toolbar') await expect(page.locator('#toolbar-administration')).toBeVisible();
    else await expect(page.locator('#admin-toolbar')).toHaveCount(1);
    await page.evaluate(() => window.scrollTo(0, 600));
    await expect.poll(() => header(page).evaluate(element => element.getBoundingClientRect().top)).toBeGreaterThanOrEqual(0);
    const offsets = await page.evaluate(() => {
      const banner = document.querySelector('.page > header[role="banner"]');
      const bars = [...document.querySelectorAll('.toolbar-bar[data-offset-top], .toolbar-tray.is-active[data-offset-top], .top-bar[data-offset-top], .admin-toolbar-control-bar[data-offset-top]')].filter(element => {
        const box = element.getBoundingClientRect(), style = getComputedStyle(element);
        return style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0 && box.top <= 1 && box.bottom > 0;
      });
      return { headerTop: banner.getBoundingClientRect().top, visibleBarBottom: Math.max(0, ...bars.map(element => element.getBoundingClientRect().bottom)) };
    });
    expect(offsets.headerTop).toBeGreaterThanOrEqual(offsets.visibleBarBottom - 1);
    await assertNoOverflow(page);
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  if (fixture.adminInterface === 'toolbar') {
    await page.goto(fixture.currentTopicPath);
    const manage = page.locator('#toolbar-item-administration');
    await expect(manage).toBeVisible();
    if (await manage.getAttribute('aria-pressed') !== 'true') await manage.click();
    if (!(await page.locator('body').getAttribute('class')).includes('toolbar-horizontal')) {
      await page.locator('.toolbar-toggle-orientation button').click();
    }
    await expect(page.locator('body')).toHaveClass(/\btoolbar-horizontal\b/);
    await expect(manage).toHaveAttribute('aria-pressed', 'true');
    const expandedTop = await header(page).evaluate(element => element.getBoundingClientRect().top);
    await manage.click();
    await expect(manage).toHaveAttribute('aria-pressed', 'false');
    // The actual Drupal tray changes the declared top displacement without
    // scrolling or resizing the viewport. The sticky header must follow it.
    await expect.poll(() => header(page).evaluate(element => element.getBoundingClientRect().top)).toBeLessThan(expandedTop - 1);
  }
  await page.goto(`${fixture.currentTopicPath}/layout`);
  const addBlock = page.getByRole('link', { name: /^Add block\b/ }).first();
  await expect(addBlock).toHaveAttribute('data-once', /\bajax\b/);
  await addBlock.click();
  const offcanvas = page.locator('#drupal-off-canvas');
  await expect(offcanvas).toBeVisible();
  const layers = await offcanvas.evaluate(element => ({
    dialog: parseInt(getComputedStyle(element.closest('.ui-dialog')).zIndex, 10),
    banner: parseInt(getComputedStyle(document.querySelector('.page > header[role="banner"]')).zIndex, 10),
  }));
  expect(layers.dialog).toBeGreaterThan(layers.banner);
  await screenshot(page, testInfo, 'layout-builder-dialog');
});

test('same-origin editor preview-frame compatibility keeps the page header static', async ({ page }) => {
  // This exercises the public preview-frame contract. It does not install or
  // claim end-to-end coverage of Drupal Canvas or the contributed Gin theme.
  await page.goto(fixture.previewFramePath);
  const preview = page.frameLocator('iframe[data-canvas-preview]');
  await expect(preview.locator('html')).toHaveClass(/\bis-canvas-preview\b/);
  await expect(preview.locator('html')).not.toHaveClass(/\bactools-sticky-header-active\b/);
  const position = await preview.locator('.page > header[role="banner"]').evaluate(element => getComputedStyle(element).position);
  expect(position).toBe('static');
  const clearance = await preview.locator('html').evaluate(element => parseFloat(getComputedStyle(element).scrollPaddingBlockStart) || 0);
  expect(clearance).toBeLessThanOrEqual(1);
});

test('real menu colours, pale brand, larger text, reduced motion and print remain readable', async ({ page }, testInfo) => {
  try {
    for (const settings of ['default', 'pale-large']) {
      scenario(settings, testInfo.project.use.baseURL);
      for (const mode of ['light', 'dark']) {
        for (const width of [390, 1440]) {
          await page.setViewportSize({ width, height: 1000 });
          await page.emulateMedia({ reducedMotion: 'reduce' });
          await page.goto(fixture.currentTopicPath);
          // Presentation simulation only: interaction tests above use the real
          // editing/navigation UI without mutating it or its native links.
          await page.locator('html').evaluate((element, value) => element.setAttribute('data-bs-theme', value), mode);
          await screenshot(page, testInfo, `${settings}-${mode}-${width}-closed`);
          await openMenu(page);
          await openBranch(page, fixture.parentTitle);
          await openBranch(page, fixture.childTitle);
          for (const title of [fixture.parentTitle, fixture.childTitle, fixture.currentTopicTitle, 'Resources']) {
            const link = navigation(page).getByRole('link', { name: title, exact: true });
            await expect(link).toBeVisible();
            await assertReadable(link);
            // Set real keyboard modality before programmatic focus placement;
            // a pointer-opened disclosure legitimately suppresses :focus-visible.
            await page.keyboard.press('ArrowDown');
            await link.focus();
            await assertReadable(link);
            expect(await link.evaluate(element => getComputedStyle(element).outlineStyle)).not.toBe('none');
          }
          await assertNoOverflow(page);
          await screenshot(page, testInfo, `${settings}-${mode}-${width}-open`);
          if (settings === 'pale-large' && mode === 'light') {
            await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
            await assertNoOverflow(page);
            await expect(navigation(page).getByRole('link', { name: fixture.currentTopicTitle, exact: true })).toBeVisible();
          }
          await page.emulateMedia({ media: 'print' });
          await expect(header(page)).toBeHidden();
          await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
          await page.emulateMedia({ media: 'screen' });
        }
      }
    }
  }
  finally { scenario('default', testInfo.project.use.baseURL); }
});

test('without JavaScript both native menu instances expose every permitted level', async ({ browser }, testInfo) => {
  const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, javaScriptEnabled: false, viewport: { width: 320, height: 900 } });
  const page = await context.newPage();
  try {
    const response = await page.goto(fixture.currentTopicPath);
    expect(response.status()).toBe(200);
    for (const menu of [navigation(page), footerNavigation(page)]) {
      await expect(menu.getByRole('link', { name: fixture.parentTitle, exact: true })).toBeVisible();
      await expect(menu.getByRole('link', { name: fixture.childTitle, exact: true })).toBeVisible();
      await expect(menu.getByRole('link', { name: fixture.currentTopicTitle, exact: true })).toBeVisible();
      await expect(menu.getByText(fixture.unpublishedTitle, { exact: true })).toHaveCount(0);
    }
    await expect(menuToggle(page)).toBeHidden();
    await assertNoOverflow(page);
    await navigation(page).getByRole('link', { name: fixture.parentTitle, exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(fixture.parentTitle);
    await screenshot(page, testInfo, 'no-js-phone');
  }
  finally { await context.close(); }
});

test('optional Resource finder applies native GET filters and excludes drafts', async ({ page }) => {
  await page.goto(fixture.resourceFinderPath);
  await expect(page.locator('.actools-resource-card')).toHaveCount(5);
  await expect(page.getByText(fixture.unpublishedTitle, { exact: true })).toHaveCount(0);
  await page.locator('input[name="q"]').fill('Capillary');
  await page.locator('select[name="kind"]').selectOption('worked_examples');
  await page.locator('select[name="level"]').selectOption('jee_advanced');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  const url = new URL(page.url());
  expect(url.searchParams.get('q')).toBe('Capillary');
  expect(url.searchParams.get('kind')).toBe('worked_examples');
  expect(url.searchParams.get('level')).toBe('jee_advanced');
  await expect(page.locator('.actools-resource-card__heading')).toHaveText(['Capillary rise worked examples']);
  await page.reload();
  await expect(page.locator('.actools-resource-card__heading')).toHaveText(['Capillary rise worked examples']);
  await page.getByRole('button', { name: 'Reset', exact: true }).click();
  await expect(page.locator('.actools-resource-card')).toHaveCount(5);
  await page.locator('input[name="q"]').fill('No such physics resource');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.getByText('No resources match your filters.', { exact: true })).toBeVisible();
  await expect(page.locator('.actools-resource-card')).toHaveCount(0);
});
