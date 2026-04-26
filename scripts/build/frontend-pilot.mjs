import { build, context } from 'esbuild';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const appOutdir = path.join(root, 'assets', 'js', 'dist');
const metafileDir = path.join(root, 'tmp', 'build-meta');
const isWatch = process.argv.includes('--watch');
const isRelease = process.argv.includes('--release');

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function toPosix(value) {
  return String(value || '').replace(/\\/g, '/');
}

function resetDir(dir) {
  fs.rmSync(dir, { recursive: true, force: true });
  ensureDir(dir);
}

resetDir(metafileDir);
ensureDir(appOutdir);

function cleanupBuildOutputs(outdir) {
  if (!fs.existsSync(outdir)) {
    ensureDir(outdir);
    return;
  }

  const entries = fs.readdirSync(outdir, { withFileTypes: true });
  for (const entry of entries) {
    const abs = path.join(outdir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'chunks') {
        fs.rmSync(abs, { recursive: true, force: true });
      }
      continue;
    }
    if (!entry.isFile()) {
      continue;
    }
    if (entry.name.endsWith('.js') || entry.name.endsWith('.js.map')) {
      fs.rmSync(abs, { force: true });
    }
  }
}

function createAppBuildGroup() {
  const entryPoints = {
    'runtime-core': path.join(root, 'assets', 'js', 'app', 'entries', 'runtime-core.entry.js'),
    'public-core': path.join(root, 'assets', 'js', 'app', 'entries', 'public-core.entry.js'),
    'game-core': path.join(root, 'assets', 'js', 'app', 'entries', 'game-core.entry.js'),
    'admin-core': path.join(root, 'assets', 'js', 'app', 'entries', 'admin-core.entry.js'),
    'game-home': path.join(root, 'assets', 'js', 'app', 'entries', 'game-home.entry.js'),
    'game-location': path.join(root, 'assets', 'js', 'app', 'entries', 'game-location.entry.js'),
    'game-community': path.join(root, 'assets', 'js', 'app', 'entries', 'game-community.entry.js'),
    'game-character': path.join(root, 'assets', 'js', 'app', 'entries', 'game-character.entry.js'),
    'game-world': path.join(root, 'assets', 'js', 'app', 'entries', 'game-world.entry.js'),
    'admin-priority': path.join(root, 'assets', 'js', 'app', 'entries', 'admin-priority.entry.js'),
    'admin-governance': path.join(root, 'assets', 'js', 'app', 'entries', 'admin-governance.entry.js'),
    'admin-economy-content': path.join(root, 'assets', 'js', 'app', 'entries', 'admin-economy-content.entry.js'),
    'admin-narrative': path.join(root, 'assets', 'js', 'app', 'entries', 'admin-narrative.entry.js'),
    'admin-logs': path.join(root, 'assets', 'js', 'app', 'entries', 'admin-logs.entry.js'),
    'admin-bootstrap': path.join(root, 'assets', 'js', 'app', 'core', 'bootstrap.admin.js'),
    'game-bootstrap': path.join(root, 'assets', 'js', 'app', 'core', 'bootstrap.game.js')
  };

  return {
    name: 'app',
    outdir: appOutdir,
    entryPoints,
    entryNames: '[name].bundle',
    metafile: path.join(metafileDir, 'app.meta.json')
  };
}

function discoverModuleBuildGroups() {
  const modulesDir = path.join(root, 'modules');
  const discovered = [];
  if (!fs.existsSync(modulesDir)) { return discovered; }
  const entries = fs.readdirSync(modulesDir, { withFileTypes: true });
  for (const entry of entries) {
    if (!entry.isDirectory()) { continue; }
    const manifestPath = path.join(modulesDir, entry.name, 'module.json');
    if (!fs.existsSync(manifestPath)) { continue; }
    let manifest;
    try { manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')); } catch { continue; }
    const assets = manifest.assets || {};
    const id = manifest.id || entry.name;
    const moduleDir = path.join(modulesDir, entry.name);
    const moduleMetaDir = path.join(metafileDir, 'modules', entry.name);
    const moduleDistDir = path.join(moduleDir, 'dist');
    const entryPoints = {};

    ensureDir(moduleDistDir);
    ensureDir(moduleMetaDir);

    const adminJs = assets.admin && Array.isArray(assets.admin.js) && assets.admin.js[0];
    if (adminJs) {
      const entryFile = path.join(moduleDir, 'assets', 'js', 'index.admin.js');
      if (fs.existsSync(entryFile)) {
        const entryName = toPosix(path.relative('dist', String(adminJs || ''))).replace(/\.js$/i, '');
        if (entryName && !entryName.startsWith('..')) {
          entryPoints[entryName] = entryFile;
        }
      }
    }
    const gameJs = assets.game && Array.isArray(assets.game.js) && assets.game.js[0];
    if (gameJs) {
      const entryFile = path.join(moduleDir, 'assets', 'js', 'index.game.js');
      if (fs.existsSync(entryFile)) {
        const entryName = toPosix(path.relative('dist', String(gameJs || ''))).replace(/\.js$/i, '');
        if (entryName && !entryName.startsWith('..')) {
          entryPoints[entryName] = entryFile;
        }
      }
    }

    if (Object.keys(entryPoints).length === 0) {
      continue;
    }

    discovered.push({
      name: `module:${id}`,
      outdir: moduleDistDir,
      entryPoints,
      entryNames: '[name]',
      metafile: path.join(moduleMetaDir, 'frontend.meta.json')
    });
  }
  return discovered;
}

function prettyBytes(bytes) {
  const value = Number(bytes) || 0;
  if (value < 1024) return `${value} B`;
  const kb = value / 1024;
  if (kb < 1024) return `${kb.toFixed(2)} KB`;
  return `${(kb / 1024).toFixed(2)} MB`;
}

function getBuildOptions(group) {
  return {
    entryPoints: group.entryPoints,
    outdir: group.outdir,
    bundle: true,
    format: 'esm',
    splitting: true,
    platform: 'browser',
    target: ['es2020'],
    sourcemap: !isRelease,
    minify: true,
    legalComments: 'none',
    metafile: true,
    charset: 'utf8',
    entryNames: group.entryNames || '[name]',
    chunkNames: 'chunks/[name]-[hash]',
    assetNames: 'assets/[name]-[hash]'
  };
}

function writeMetafile(group, result) {
  if (!result.metafile) {
    return;
  }
  ensureDir(path.dirname(group.metafile));
  fs.writeFileSync(group.metafile, JSON.stringify(result.metafile, null, 2), 'utf8');
}

function printGroupOutputs(group, result) {
  const outputs = result.metafile ? Object.entries(result.metafile.outputs) : [];
  const entryOutputs = outputs
    .filter(([, meta]) => !!meta.entryPoint)
    .sort((a, b) => String(a[0]).localeCompare(String(b[0])));

  for (const [outfile] of entryOutputs) {
    const stats = fs.statSync(outfile);
    console.log(`[build] ${group.name}:${path.basename(outfile)}: ${prettyBytes(stats.size)} -> ${path.relative(root, outfile)}`);
  }

  const chunkCount = outputs.filter(([outfile, meta]) => !meta.entryPoint && outfile.endsWith('.js')).length;
  if (chunkCount > 0) {
    console.log(`[build] ${group.name}: shared chunks ${chunkCount}`);
  }
}

async function runBuildOnce() {
  console.log(`[build] mode: ${isRelease ? 'release' : 'pilot'}`);
  const allGroups = [createAppBuildGroup(), ...discoverModuleBuildGroups()];
  for (const group of allGroups) {
    cleanupBuildOutputs(group.outdir);
    const result = await build(getBuildOptions(group));
    writeMetafile(group, result);
    printGroupOutputs(group, result);
  }
}

async function runWatch() {
  console.log('[watch] frontend pilot build started');
  const allGroups = [createAppBuildGroup(), ...discoverModuleBuildGroups()];
  for (const group of allGroups) {
    cleanupBuildOutputs(group.outdir);
    const ctx = await context(getBuildOptions(group));
    await ctx.watch();
    console.log(`[watch] ${group.name}: watching ${Object.keys(group.entryPoints).length} entry points`);
  }
}

(async function main() {
  if (isWatch) {
    await runWatch();
    return;
  }

  await runBuildOnce();
})().catch((error) => {
  console.error('[build] frontend pilot failed');
  console.error(error && error.message ? error.message : error);
  process.exit(1);
});
