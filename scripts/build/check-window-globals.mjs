#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const args = new Set(process.argv.slice(2));
const shouldUpdate = args.has('--update');

const sourceRoots = [
  'assets/js/app',
  'assets/js/components',
  'assets/js/services'
];

const registryPath = path.join(root, 'scripts', 'build', 'window-globals-registry.json');
const assignmentRegex = /(?:window|globalWindow)\.([A-Za-z_$][\w$]*)\s*=/g;

function toPosix(value) {
  return String(value || '').replace(/\\/g, '/');
}

function walkFiles(startAbs, out) {
  const entries = fs.readdirSync(startAbs, { withFileTypes: true });
  for (const entry of entries) {
    const abs = path.join(startAbs, entry.name);
    if (entry.isDirectory()) {
      walkFiles(abs, out);
      continue;
    }
    if (!entry.isFile() || !entry.name.endsWith('.js')) {
      continue;
    }
    out.push(abs);
  }
}

function categorizeOwner(name, relPath) {
  if (name.startsWith('Admin')) return 'admin-runtime';
  if (name.startsWith('Game')) return 'game-runtime';
  if (name.startsWith('__')) return 'core-runtime';
  if (relPath.includes('/features/public/')) return 'public-runtime';
  if (relPath.includes('/features/game/')) return 'game-runtime';
  if (relPath.includes('/features/admin/')) return 'admin-runtime';
  if (relPath.includes('/components/')) return 'ui-core';
  if (relPath.includes('/services/')) return 'services-core';
  return 'core-runtime';
}

function collectAssignments() {
  const files = [];
  for (const relRoot of sourceRoots) {
    const absRoot = path.join(root, relRoot);
    if (fs.existsSync(absRoot)) {
      walkFiles(absRoot, files);
    }
  }

  const discovered = new Map();
  for (const absFile of files) {
    const rel = toPosix(path.relative(root, absFile));
    const text = fs.readFileSync(absFile, 'utf8');
    let match;
    while ((match = assignmentRegex.exec(text)) !== null) {
      const name = String(match[1] || '').trim();
      if (!name) continue;
      if (!discovered.has(name)) {
        discovered.set(name, {
          name,
          files: new Set(),
          owner: categorizeOwner(name, rel),
          remove_by: 'phase-4',
          todo: 'Migrare da global window API a import espliciti e registry runtime.'
        });
      }
      discovered.get(name).files.add(rel);
    }
  }

  return [...discovered.values()]
    .map((item) => ({
      name: item.name,
      owner: item.owner,
      remove_by: item.remove_by,
      todo: item.todo,
      files: [...item.files].sort()
    }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

function loadRegistry() {
  if (!fs.existsSync(registryPath)) return null;
  try {
    return JSON.parse(fs.readFileSync(registryPath, 'utf8'));
  } catch (error) {
    throw new Error(`Registry JSON non valido: ${error.message}`);
  }
}

function saveRegistry(entries) {
  const payload = {
    generated_at: new Date().toISOString(),
    generated_by: 'scripts/build/check-window-globals.mjs',
    purpose: 'Registro ufficiale temporaneo delle API globali su window/globalWindow.',
    entries
  };
  fs.writeFileSync(registryPath, JSON.stringify(payload, null, 2), 'utf8');
}

function validateMeta(entry) {
  return !!(entry && entry.owner && entry.remove_by && entry.todo);
}

function run() {
  const discovered = collectAssignments();

  if (shouldUpdate) {
    saveRegistry(discovered);
    console.log(`[window-globals] registry aggiornato: ${toPosix(path.relative(root, registryPath))}`);
    console.log(`[window-globals] entries: ${discovered.length}`);
    return;
  }

  const registry = loadRegistry();
  if (!registry || !Array.isArray(registry.entries)) {
    console.error('[window-globals] registry mancante o non valido.');
    console.error('[window-globals] esegui: node scripts/build/check-window-globals.mjs --update');
    process.exit(1);
  }

  const known = new Map(registry.entries.map((entry) => [entry.name, entry]));
  const discoveredByName = new Map(discovered.map((entry) => [entry.name, entry]));

  const unauthorized = discovered.filter((entry) => !known.has(entry.name));
  const invalidMeta = registry.entries.filter((entry) => !validateMeta(entry));
  const stale = registry.entries.filter((entry) => !discoveredByName.has(entry.name));

  if (unauthorized.length > 0) {
    console.error('[window-globals] nuove API globali non autorizzate trovate:');
    for (const item of unauthorized) {
      console.error(` - ${item.name} (${item.files[0] || 'n/a'})`);
    }
    process.exit(1);
  }

  if (invalidMeta.length > 0) {
    console.error('[window-globals] entries senza metadata complete (owner/remove_by/todo):');
    for (const item of invalidMeta) {
      console.error(` - ${item.name}`);
    }
    process.exit(1);
  }

  if (stale.length > 0) {
    console.warn('[window-globals] warning: entries stale presenti nel registry:');
    for (const item of stale.slice(0, 20)) {
      console.warn(` - ${item.name}`);
    }
    if (stale.length > 20) {
      console.warn(` - ... (${stale.length - 20} altre)`);
    }
  }

  console.log(`[window-globals] OK. monitored: ${discovered.length} API globali`);
}

try {
  run();
} catch (error) {
  console.error(`[window-globals] failed: ${error.message}`);
  process.exit(1);
}
