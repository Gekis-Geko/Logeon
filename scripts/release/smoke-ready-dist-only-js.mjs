#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const args = process.argv.slice(2);

function parseArgs(list) {
  const out = { staging: path.join(root, 'dist', 'release', 'staging', 'logeon-core-ready') };
  for (let i = 0; i < list.length; i += 1) {
    const token = String(list[i] || '').trim();
    if (token === '--staging' && list[i + 1]) {
      out.staging = path.resolve(String(list[i + 1]));
      i += 1;
    }
  }
  return out;
}

function exists(p) {
  return fs.existsSync(p);
}

function listTwigFiles(dir) {
  if (!exists(dir)) return [];
  const out = [];
  const stack = [dir];
  while (stack.length) {
    const current = stack.pop();
    const entries = fs.readdirSync(current, { withFileTypes: true });
    for (const entry of entries) {
      const abs = path.join(current, entry.name);
      if (entry.isDirectory()) {
        stack.push(abs);
      } else if (entry.isFile() && abs.endsWith('.twig')) {
        out.push(abs);
      }
    }
  }
  return out;
}

function rel(p) {
  return path.relative(root, p).replace(/\\/g, '/');
}

function fail(message) {
  console.error(`[ready-smoke] FAIL: ${message}`);
}

function pass(message) {
  console.log(`[ready-smoke] PASS: ${message}`);
}

function run() {
  const opts = parseArgs(args);
  const staging = opts.staging;

  if (!exists(staging)) {
    fail(`staging non trovato: ${staging}`);
    process.exit(1);
  }

  let failures = 0;

  const requiredBundles = [
    'assets/js/dist/runtime-core.bundle.js',
    'assets/js/dist/public-core.bundle.js',
    'assets/js/dist/game-core.bundle.js',
    'assets/js/dist/admin-core.bundle.js'
  ];

  for (const file of requiredBundles) {
    const full = path.join(staging, file);
    if (!exists(full)) {
      failures += 1;
      fail(`bundle mancante: ${file}`);
    }
  }
  if (failures === 0) {
    pass('bundle core dist presenti');
  }

  const forbiddenDirs = [
    'assets/js/app',
    'assets/js/components',
    'assets/js/services'
  ];
  for (const dir of forbiddenDirs) {
    const full = path.join(staging, dir);
    if (exists(full)) {
      failures += 1;
      fail(`directory JS sorgente presente (dist-only violato): ${dir}`);
    }
  }
  if (failures === 0) {
    pass('sorgenti JS runtime esclusi dal pacchetto ready');
  }

  const viewsRoot = path.join(staging, 'app', 'views');
  const twigFiles = listTwigFiles(viewsRoot);
  const forbiddenRef = /\/assets\/js\/(app|components|services)\//;
  const offenders = [];
  for (const twig of twigFiles) {
    const source = fs.readFileSync(twig, 'utf8');
    if (forbiddenRef.test(source)) {
      offenders.push(rel(twig));
    }
  }
  if (offenders.length > 0) {
    failures += 1;
    fail('riferimenti Twig a JS sorgente rilevati:');
    for (const file of offenders.slice(0, 20)) {
      console.error(`  - ${file}`);
    }
    if (offenders.length > 20) {
      console.error(`  - ... (${offenders.length - 20} altri file)`);
    }
  } else {
    pass('nessun riferimento Twig a JS sorgente runtime');
  }

  const requiredTwigRefs = [
    { file: 'app/views/layouts/header.twig', token: '/assets/js/dist/runtime-core.bundle.js' },
    { file: 'app/views/layouts/layout.twig', token: '/assets/js/dist/public-core.bundle.js' },
    { file: 'app/views/app/layouts/layout.twig', token: '/assets/js/dist/game-core.bundle.js' },
    { file: 'app/views/admin/layouts/layout.twig', token: '/assets/js/dist/admin-core.bundle.js' }
  ];

  for (const item of requiredTwigRefs) {
    const full = path.join(staging, item.file);
    if (!exists(full)) {
      failures += 1;
      fail(`view mancante: ${item.file}`);
      continue;
    }
    const source = fs.readFileSync(full, 'utf8');
    if (!source.includes(item.token)) {
      failures += 1;
      fail(`token dist mancante in ${item.file}: ${item.token}`);
    }
  }

  if (failures > 0) {
    console.error(`[ready-smoke] failed: ${failures}`);
    process.exit(1);
  }

  pass('ready dist-only JS smoke completato');
}

try {
  run();
} catch (error) {
  fail(error && error.message ? error.message : String(error));
  process.exit(1);
}
