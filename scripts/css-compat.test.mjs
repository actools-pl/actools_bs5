import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp, mkdir, readFile, writeFile, cp, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {compareCss, selectors, sha256} from './css-compat.mjs';

test('detects utility loss, including RTL and responsive contexts', () => {
  const original = '.card{color:red}.ratio-2x1{aspect-ratio:2/1}' +
    '@media(min-width: 992px){.d-lg-flex{display:flex}}[dir=rtl] .card{margin-right:1rem}';
  const changed = '.card{color:blue}.ratio-2x1{aspect-ratio:2/1}' +
    '.d-lg-flex{display:flex}';
  const report = compareCss('fixture.css', original, changed);
  assert.equal(report.changed, true);
  assert.deepEqual(report.missingSelectors, [
    '@media(min-width:992px) | .d-lg-flex', '[dir=rtl] .card',
  ]);
  assert.deepEqual(report.addedSelectors, ['.d-lg-flex']);
});

test('handles comments, strings, comma arguments and SVG data URLs', () => {
  const css = '/* .old { } */ .card:is(.a,.b), [data-label="a,b"] {' +
    'content:"{literal}";background:url("data:image/svg+xml,<svg>{}</svg>")}' +
    '@supports(display:grid){.grid{display:grid}}' +
    '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
  assert.deepEqual([...selectors(css)].sort(), [
    '.card:is(.a,.b)', '@supports(display:grid) | .grid', '[data-label="a,b"]',
  ]);
});

test('normalizes selector formatting and reports declaration drift via hashes', () => {
  const report = compareCss('fixture.css', '.a > .b, .c {color:red}', '.a>.b,.c{color:blue}');
  assert.deepEqual(report.missingSelectors, []);
  assert.deepEqual(report.addedSelectors, []);
  assert.notEqual(report.canonicalSha256, report.candidateSha256);
  assert.equal(report.changed, true);
});

test('rejects unclosed rule blocks', () => {
  assert.throws(() => selectors('.broken{color:red'), /Unclosed CSS block/);
});

test('ignores quoted comments while preserving comment text in attribute strings', () => {
  assert.deepEqual([...selectors('/* user\'s "comment" */.a/**/.b,[data-note="/* literal */"]{color:red}')],
    ['.a.b', '[data-note="/* literal */"]']);
});

async function fixture(t) {
  const directory = await mkdtemp(path.join(tmpdir(), 'actools-css-test-'));
  t.after(() => rm(directory, {recursive: true, force: true}));
  for (const name of ['scripts', 'scss/base', 'css/base', 'css/layout', 'css/theme',
    '.build/css/base', '.build/css/layout', '.build/css/theme']) {
    await mkdir(path.join(directory, name), {recursive: true});
  }
  const sources = {};
  for (const name of ['build-css.mjs', 'css-compat.mjs']) {
    await cp(fileURLToPath(new URL(name, import.meta.url)), path.join(directory, 'scripts', name));
  }
  await writeFile(path.join(directory, 'package.json'), '{"private":true}\n');
  await writeFile(path.join(directory, 'package-lock.json'), '{"lockfileVersion":3}\n');
  await writeFile(path.join(directory, 'scss/base/fixture.scss'), '.kept{color:red}\n');
  for (const name of ['package-lock.json', 'package.json', 'scripts/build-css.mjs',
    'scripts/css-compat.mjs', 'scss/base/fixture.scss'].sort()) {
    sources[name] = sha256(await readFile(path.join(directory, name)));
  }
  const outputs = {};
  const entries = [
    'base/bootstrap.base', 'base/tokens.base', 'base/actools.base', 'base/ckeditor5.bootstrap.base',
    'layout/edge2edge.layout', 'layout/equal-height.layout', 'layout/print.layout', 'layout/builder.layout',
    'theme/content-moderation.theme', 'theme/sticky-header.theme', 'theme/navigation.theme',
    'theme/generic-lesson.theme',
  ];
  for (const name of entries) {
    await writeFile(path.join(directory, 'css', `${name}.css`), '.kept{color:red}.removed{display:block}\n');
    const css = '.kept{color:blue}\n';
    await writeFile(path.join(directory, '.build/css', `${name}.css`), css);
    outputs[`${name}.css`] = sha256(css);
  }
  await writeFile(path.join(directory, '.build/css-manifest.json'),
    JSON.stringify({compiler: 'test fixture; no Sass compilation performed', sources, outputs}));
  const run = (...args) => spawnSync(process.execPath, ['scripts/build-css.mjs', ...args], {
    cwd: directory, encoding: 'utf8',
  });
  return {directory, run};
}

test('publishing blocks selector removals and leaves shipped files untouched', async t => {
  const {directory, run} = await fixture(t);
  const original = await readFile(path.join(directory, 'css/base/bootstrap.base.css'), 'utf8');
  const result = run('publish');
  assert.equal(result.status, 1);
  assert.match(result.stderr, /removes shipped selectors/);
  assert.equal(await readFile(path.join(directory, 'css/base/bootstrap.base.css'), 'utf8'), original);
  const report = JSON.parse(await readFile(path.join(directory, '.build/css-build-report.json'), 'utf8'));
  assert.deepEqual(report.files[0].missingSelectors, ['.removed']);
});

test('publishing requires an explicit selector-removal override', async t => {
  const {directory, run} = await fixture(t);
  const result = run('publish', '--allow-selector-removal');
  assert.equal(result.status, 0, result.stderr);
  assert.equal(await readFile(path.join(directory, 'css/base/bootstrap.base.css'), 'utf8'), '.kept{color:blue}\n');
});

test('publishing rejects modified staged CSS even with removal override', async t => {
  const {directory, run} = await fixture(t);
  await writeFile(path.join(directory, '.build/css/base/bootstrap.base.css'), '.uncompiled{color:red}');
  const result = run('publish', '--allow-selector-removal');
  assert.equal(result.status, 1);
  assert.match(result.stderr, /edited after compilation/);
});

test('publishing rejects stale candidates after source edits', async t => {
  const {directory, run} = await fixture(t);
  await writeFile(path.join(directory, 'scss/base/fixture.scss'), '.new-source{color:red}');
  const result = run('publish', '--allow-selector-removal');
  assert.equal(result.status, 1);
  assert.match(result.stderr, /sources or dependency definitions changed/);
});


test('scoped publishing preserves every unselected shipped bundle', async t => {
  const {directory, run} = await fixture(t);
  const original = await readFile(path.join(directory, 'css/base/bootstrap.base.css'), 'utf8');
  const result = run('publish', '--entry=theme/navigation.theme', '--allow-selector-removal');
  assert.equal(result.status, 0, result.stderr);
  assert.equal(await readFile(path.join(directory, 'css/theme/navigation.theme.css'), 'utf8'), '.kept{color:blue}\n');
  assert.equal(await readFile(path.join(directory, 'css/base/bootstrap.base.css'), 'utf8'), original);
  const report = JSON.parse(await readFile(path.join(directory, '.build/css-build-report.json'), 'utf8'));
  assert.deepEqual(report.files.map(file => file.file), ['theme/navigation.theme.css']);
});

test('scoped publishing still blocks removals and rejects unknown entry paths', async t => {
  const {directory, run} = await fixture(t);
  assert.equal(run('publish', '--entry=theme/navigation.theme').status, 1);
  const original = await readFile(path.join(directory, 'css/theme/navigation.theme.css'), 'utf8');
  const result = run('publish', '--entry=../../outside', '--allow-selector-removal');
  assert.equal(result.status, 1);
  assert.match(result.stderr, /Usage:/);
  assert.equal(await readFile(path.join(directory, 'css/theme/navigation.theme.css'), 'utf8'), original);
});
