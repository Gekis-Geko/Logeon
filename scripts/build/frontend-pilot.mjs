import { build, context } from 'esbuild';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const outdir = path.join(root, 'assets', 'js', 'dist');
const metafileDir = path.join(root, 'tmp', 'build-meta');
const isWatch = process.argv.includes('--watch');
const isRelease = process.argv.includes('--release');

fs.mkdirSync(outdir, { recursive: true });
fs.mkdirSync(metafileDir, { recursive: true });

const targets = [
  {
    name: 'runtime-core',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'runtime-core.entry.js'),
    outfile: path.join(outdir, 'runtime-core.bundle.js'),
    metafile: path.join(metafileDir, 'runtime-core.meta.json')
  },
  {
    name: 'public-core',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'public-core.entry.js'),
    outfile: path.join(outdir, 'public-core.bundle.js'),
    metafile: path.join(metafileDir, 'public-core.meta.json')
  },
  {
    name: 'game-core',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'game-core.entry.js'),
    outfile: path.join(outdir, 'game-core.bundle.js'),
    metafile: path.join(metafileDir, 'game-core.meta.json')
  },
  {
    name: 'admin-core',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-core.entry.js'),
    outfile: path.join(outdir, 'admin-core.bundle.js'),
    metafile: path.join(metafileDir, 'admin-core.meta.json')
  },
  {
    name: 'game-home',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'game-home.entry.js'),
    outfile: path.join(outdir, 'game-home.bundle.js'),
    metafile: path.join(metafileDir, 'game-home.meta.json')
  },
  {
    name: 'game-location',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'game-location.entry.js'),
    outfile: path.join(outdir, 'game-location.bundle.js'),
    metafile: path.join(metafileDir, 'game-location.meta.json')
  },
  {
    name: 'game-community',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'game-community.entry.js'),
    outfile: path.join(outdir, 'game-community.bundle.js'),
    metafile: path.join(metafileDir, 'game-community.meta.json')
  },
  {
    name: 'game-character',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'game-character.entry.js'),
    outfile: path.join(outdir, 'game-character.bundle.js'),
    metafile: path.join(metafileDir, 'game-character.meta.json')
  },
  {
    name: 'game-world',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'game-world.entry.js'),
    outfile: path.join(outdir, 'game-world.bundle.js'),
    metafile: path.join(metafileDir, 'game-world.meta.json')
  },
  {
    name: 'admin-priority',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-priority.entry.js'),
    outfile: path.join(outdir, 'admin-priority.bundle.js'),
    metafile: path.join(metafileDir, 'admin-priority.meta.json')
  },
  {
    name: 'admin-weather',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-weather.entry.js'),
    outfile: path.join(outdir, 'admin-weather.bundle.js'),
    metafile: path.join(metafileDir, 'admin-weather.meta.json')
  },
  {
    name: 'admin-governance',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-governance.entry.js'),
    outfile: path.join(outdir, 'admin-governance.bundle.js'),
    metafile: path.join(metafileDir, 'admin-governance.meta.json')
  },
  {
    name: 'admin-economy-content',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-economy-content.entry.js'),
    outfile: path.join(outdir, 'admin-economy-content.bundle.js'),
    metafile: path.join(metafileDir, 'admin-economy-content.meta.json')
  },
  {
    name: 'admin-narrative',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-narrative.entry.js'),
    outfile: path.join(outdir, 'admin-narrative.bundle.js'),
    metafile: path.join(metafileDir, 'admin-narrative.meta.json')
  },
  {
    name: 'admin-logs',
    entry: path.join(root, 'assets', 'js', 'app', 'entries', 'admin-logs.entry.js'),
    outfile: path.join(outdir, 'admin-logs.bundle.js'),
    metafile: path.join(metafileDir, 'admin-logs.meta.json')
  }
];

function prettyBytes(bytes) {
  const value = Number(bytes) || 0;
  if (value < 1024) return `${value} B`;
  const kb = value / 1024;
  if (kb < 1024) return `${kb.toFixed(2)} KB`;
  return `${(kb / 1024).toFixed(2)} MB`;
}

function getBuildOptions(target) {
  return {
    entryPoints: [target.entry],
    outfile: target.outfile,
    bundle: true,
    format: 'iife',
    platform: 'browser',
    target: ['es2018'],
    sourcemap: !isRelease,
    minify: true,
    legalComments: 'none',
    metafile: true,
    charset: 'utf8'
  };
}

function printOutput(target) {
  const stats = fs.statSync(target.outfile);
  console.log(`[build] ${target.name}: ${prettyBytes(stats.size)} -> ${path.relative(root, target.outfile)}`);
}

async function runBuildOnce() {
  console.log(`[build] mode: ${isRelease ? 'release' : 'pilot'}`);
  for (const target of targets) {
    const result = await build(getBuildOptions(target));
    if (result.metafile) {
      fs.writeFileSync(target.metafile, JSON.stringify(result.metafile, null, 2), 'utf8');
    }
    printOutput(target);
  }
}

async function runWatch() {
  console.log('[watch] frontend pilot build started');
  for (const target of targets) {
    const ctx = await context(getBuildOptions(target));
    await ctx.watch();
    console.log(`[watch] ${target.name}: watching ${path.relative(root, target.entry)}`);
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
