import {readFile, writeFile, readdir, mkdir, rm, mkdtemp, rename, copyFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {compareCss, sha256} from './css-compat.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));
const buildRoot = path.join(root, '.build');
const candidateRoot = path.join(buildRoot, 'css');
const manifestFile = path.join(buildRoot, 'css-manifest.json');
const reportFile = path.join(buildRoot, 'css-build-report.json');
const allEntries = [
  'base/bootstrap.base', 'base/tokens.base', 'base/actools.base', 'base/ckeditor5.bootstrap.base',
  'layout/edge2edge.layout', 'layout/equal-height.layout', 'layout/print.layout', 'layout/builder.layout',
  'theme/content-moderation.theme', 'theme/sticky-header.theme', 'theme/navigation.theme',
];
const [command = 'build', ...flags] = process.argv.slice(2);
const allowRemoval = flags.includes('--allow-selector-removal');
const requestedEntries = flags.filter(flag => flag.startsWith('--entry=')).map(flag => flag.slice(8));
const entries = requestedEntries.length ? [...new Set(requestedEntries)] : allEntries;

async function sourceHashes() {
  const files = ['package.json', 'package-lock.json', 'scripts/build-css.mjs', 'scripts/css-compat.mjs'];
  async function visit(directory) {
    for (const entry of await readdir(path.join(root, directory), {withFileTypes: true})) {
      const name = `${directory}/${entry.name}`;
      if (entry.isDirectory()) await visit(name);
      else if (name.endsWith('.scss')) files.push(name);
    }
  }
  await visit('scss');
  const hashes = {};
  for (const file of files.sort()) hashes[file] = sha256(await readFile(path.join(root, file)));
  return hashes;
}

async function check() {
  let manifest;
  try { manifest = JSON.parse(await readFile(manifestFile, 'utf8')); }
  catch { throw new Error('No successful candidate build. Run npm run build first.'); }
  if (JSON.stringify(manifest.sources) !== JSON.stringify(await sourceHashes())) {
    throw new Error('Sass sources or dependency definitions changed. Run npm run build again.');
  }
  const files = [];
  for (const entry of entries) {
    const name = `${entry}.css`;
    const candidate = await readFile(path.join(candidateRoot, name));
    if (manifest.outputs[name] !== sha256(candidate)) {
      throw new Error(`Candidate ${name} was edited after compilation. Rebuild before publishing.`);
    }
    files.push(compareCss(name, await readFile(path.join(root, 'css', name)), candidate));
  }
  const report = {compiler: manifest.compiler, files};
  await writeFile(reportFile, JSON.stringify(report, null, 2) + '\n');
  for (const file of files) {
    console.log(`${file.file}: ${file.changed ? 'changed' : 'identical'}, ` +
      `${file.missingSelectors.length} missing and ${file.addedSelectors.length} added selectors`);
  }
  console.log('Detailed hashes and selector changes: .build/css-build-report.json');
  console.log('Selector preservation does not establish visual or RTL equivalence; review changed files.');
  return files.some(file => file.missingSelectors.length > 0);
}

try {
  if (!['build', 'check', 'publish'].includes(command) ||
      flags.some(flag => flag !== '--allow-selector-removal' && !flag.startsWith('--entry=')) ||
      requestedEntries.some(entry => !allEntries.includes(entry)) ||
      (allowRemoval && command !== 'publish')) {
    throw new Error('Usage: node scripts/build-css.mjs build|check|publish [--entry=known/bundle (repeatable)] [--allow-selector-removal for publish only]');
  }
  if (command === 'build') {
    let sass;
    try { sass = await import('sass'); }
    catch { throw new Error('Sass is unavailable. Install the locked development dependencies with npm ci.'); }
    await mkdir(buildRoot, {recursive: true});
    await rm(manifestFile, {force: true});
    const staging = await mkdtemp(path.join(buildRoot, 'css-stage-'));
    try {
      const outputs = {};
      const sources = await sourceHashes();
      for (const entry of entries) {
        const result = sass.compile(path.join(root, 'scss', `${entry}.scss`), {
          style: 'compressed', sourceMap: false, charset: false,
          loadPaths: [path.join(root, 'node_modules')], quietDeps: true,
        });
        const css = result.css + '\n';
        await mkdir(path.dirname(path.join(staging, `${entry}.css`)), {recursive: true});
        await writeFile(path.join(staging, `${entry}.css`), css);
        outputs[`${entry}.css`] = sha256(css);
      }
      if (JSON.stringify(sources) !== JSON.stringify(await sourceHashes())) {
        throw new Error('Source files changed during compilation. Run the build again.');
      }
      await rm(candidateRoot, {recursive: true, force: true});
      await rename(staging, candidateRoot);
      await writeFile(manifestFile, JSON.stringify({compiler: sass.info, sources, outputs}, null, 2) + '\n');
    }
    finally { await rm(staging, {recursive: true, force: true}); }
    console.log('Compiled candidate CSS to .build/css; shipped css files have not been changed.');
  }
  const missing = await check();
  if (missing && !allowRemoval) {
    throw new Error('Candidate CSS removes shipped selectors. Review the report; publishing is blocked. ' +
      'Use npm run publish:css -- --allow-selector-removal only after deliberately reviewing the removals.');
  }
  if (command === 'publish') {
    for (const entry of entries) {
      await copyFile(path.join(candidateRoot, `${entry}.css`), path.join(root, 'css', `${entry}.css`));
    }
    console.log('Published reviewed candidate CSS to css. Verify rendering, keyboard use, print and RTL before deploying.');
  }
}
catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
