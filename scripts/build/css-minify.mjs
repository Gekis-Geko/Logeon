#!/usr/bin/env node
import path from 'node:path';
import fs from 'node:fs';
import { build } from 'esbuild';

function parseArgs(argv) {
  const args = { target: null };
  for (let i = 2; i < argv.length; i += 1) {
    const token = String(argv[i] || '').trim();
    if (token === '--target' && argv[i + 1]) {
      args.target = String(argv[i + 1]).trim();
      i += 1;
    }
  }
  return args;
}

function isCssCandidate(fileName) {
  const lower = fileName.toLowerCase();
  if (!lower.endsWith('.css')) return false;
  if (lower.endsWith('.min.css')) return false;
  return true;
}

async function minifyCssFile(filePath) {
  const abs = path.resolve(filePath);
  const dir = path.dirname(abs);
  const base = path.basename(abs);
  const contents = fs.readFileSync(abs, 'utf8');

  await build({
    stdin: {
      contents,
      resolveDir: dir,
      sourcefile: base,
      loader: 'css',
    },
    outfile: abs,
    minify: true,
    sourcemap: 'external',
    legalComments: 'none',
    logLevel: 'silent',
    write: true,
    allowOverwrite: true,
  });
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.target) {
    console.error('[css-minify] Missing --target <dir>');
    process.exit(1);
  }

  const targetDir = path.resolve(args.target);
  if (!fs.existsSync(targetDir) || !fs.statSync(targetDir).isDirectory()) {
    console.error(`[css-minify] Target directory not found: ${targetDir}`);
    process.exit(1);
  }

  const entries = fs.readdirSync(targetDir, { withFileTypes: true });
  const files = entries
    .filter((entry) => entry.isFile() && isCssCandidate(entry.name))
    .map((entry) => path.join(targetDir, entry.name))
    .sort();

  let done = 0;
  for (const filePath of files) {
    await minifyCssFile(filePath);
    done += 1;
    console.log(`[css-minify] ${path.basename(filePath)} + map`);
  }

  console.log(`[css-minify] done: ${done} file(s) in ${targetDir}`);
}

main().catch((error) => {
  const message = (error && error.message) ? error.message : String(error);
  console.error(`[css-minify] failed: ${message}`);
  process.exit(1);
});
