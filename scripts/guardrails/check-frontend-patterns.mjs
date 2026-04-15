#!/usr/bin/env node
/**
 * check-frontend-patterns.mjs
 *
 * Guardrail frontend Logeon — verifica pattern vietati nel codebase JS e Twig.
 * Exit 0 = tutto OK, exit 1 = almeno un FAIL.
 * WARN non alza exit code.
 *
 * Blocchi:
 *   1. window.$ / window.jQuery come guard condizionale in componenti puri (non Request.js)
 *   2. $.ajax / $.fn usati direttamente fuori da services/Request.js
 *   3. Pattern vietati nelle view Twig (script inline, onclick, fetch sparso)
 *   4. Coerenza bootstrap negli entry point
 *   5. Import path alias legacy
 */

import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();

// --------------------------------------------------------------------------
// Configurazione
// --------------------------------------------------------------------------

const SKIP_DIRS = new Set([
    '.git', 'vendor', 'tmp', 'db_backup', 'node_modules',
    'assets/vendor', 'assets/js/dist'
]);

// Blocco 1 & 2 — file esclusi perché usano window.$ / jQuery legittimamente.
// Includere: servizi che wrappano jQuery, componenti UI con jQuery come peer dependency.
const JQUERY_ALLOWED_FILES = new Set([
    'assets/js/services/Request.js',
    'assets/js/components/utils/Form.js',
    'assets/js/services/Auth.js',
    // Componenti UI che dipendono da jQuery come peer runtime (degradano se assente)
    'assets/js/components/Modal.js',
    'assets/js/components/Navbar.js',
    'assets/js/components/Toast.js',
    'assets/js/components/Tooltip.js',
    'assets/js/components/Uploader.js',
    'assets/js/components/Dialog.js',
    'assets/js/components/DataGrid.js',
]);

// Blocco 3 — layout Twig che possono avere un <script> inline minimale
// per iniettare dati PHP (es. window.__APP_USE_PAGE_BUNDLES).
// Questi producono WARN, non FAIL.
const TWIG_INLINE_SCRIPT_WARN_ALLOWLIST = new Set([
    'app/views/admin/layouts/layout.twig',
    'app/views/app/layouts/layout.twig',
]);

// Blocco 4 — entry point "core" che devono importare il bootstrap runtime.
// I page bundle (admin-economy-content, admin-governance, ecc.) non ripetono
// il bootstrap perché lo ricevono già dal core bundle caricato prima.
const ENTRIES_DIR = 'assets/js/app/entries';
const BOOTSTRAP_IMPORT_PATTERN = /import\s+['"][^'"]*(?:RuntimeBootstrap|AppBootstrap)[^'"]*['"]/;
// Solo gli entry applicativi core (admin-core, game-core) devono avere il bootstrap.
// Esclusi: runtime-core (è il bundle fondazione dei servizi, non bootstrappa l'app)
//          public-core (pagine pubbliche statiche, nessun app bootstrap necessario)
const CORE_ENTRY_PATTERN = /^(admin|game)-core\.entry\.js$/;

// Blocco 5 — import path legacy
const LEGACY_IMPORT_PATTERNS = [
    { label: 'Import alias TooltipManager legacy', regex: /require\s*\(\s*['"][^'"]*TooltipManager['"]\s*\)|from\s+['"][^'"]*TooltipManager['"]/g },
    { label: 'Import alias BackofficeMenu legacy', regex: /require\s*\(\s*['"][^'"]*BackofficeMenu['"]\s*\)|from\s+['"][^'"]*BackofficeMenu['"]/g },
    { label: 'Import alias BackofficeDashboard legacy', regex: /require\s*\(\s*['"][^'"]*BackofficeDashboard['"]\s*\)|from\s+['"][^'"]*BackofficeDashboard['"]/g },
    { label: 'Import alias BackofficeLegacyIndex legacy', regex: /require\s*\(\s*['"][^'"]*BackofficeLegacyIndex['"]\s*\)|from\s+['"][^'"]*BackofficeLegacyIndex['"]/g },
    { label: 'Import alias AppLegacyCore legacy', regex: /require\s*\(\s*['"][^'"]*AppLegacyCore['"]\s*\)|from\s+['"][^'"]*AppLegacyCore['"]/g },
];

// --------------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------------

function toPosix(filePath) {
    return filePath.split(path.sep).join('/');
}

function walk(dirPath, outFiles, allowedExts) {
    if (!fs.existsSync(dirPath)) {
        return;
    }
    const entries = fs.readdirSync(dirPath, { withFileTypes: true });
    for (const entry of entries) {
        const absolute = path.join(dirPath, entry.name);
        const relative = toPosix(path.relative(ROOT, absolute));

        if (entry.isDirectory()) {
            if (SKIP_DIRS.has(relative) || SKIP_DIRS.has(entry.name)) {
                continue;
            }
            walk(absolute, outFiles, allowedExts);
            continue;
        }
        if (!entry.isFile()) {
            continue;
        }
        const ext = path.extname(entry.name).toLowerCase();
        if (allowedExts.has(ext)) {
            outFiles.push(absolute);
        }
    }
}

function collectJs(dir) {
    const files = [];
    walk(path.join(ROOT, dir), files, new Set(['.js', '.mjs', '.cjs']));
    return files;
}

function collectTwig(dir) {
    const files = [];
    walk(path.join(ROOT, dir), files, new Set(['.twig']));
    return files;
}

function lineAndColumn(content, index) {
    const before = content.slice(0, index);
    const lines = before.split('\n');
    const line = lines.length;
    const col = lines[lines.length - 1].length + 1;
    return { line, col };
}

function logIssue(issue) {
    const level = issue.warn ? '[WARN]' : '[FAIL]';
    if (issue.type === 'file') {
        console.error(`${level} ${issue.label}: ${issue.file}`);
    } else {
        console.error(`${level} ${issue.label}: ${issue.file}:${issue.line}:${issue.col}`);
    }
}

// --------------------------------------------------------------------------
// Blocco 1 — window.$ guard in componenti puri
// --------------------------------------------------------------------------

function checkJQueryGuardInComponents(issues) {
    const files = collectJs('assets/js/components');
    const pattern = /typeof\s+window\.\$\s*[!=]==\s*['"]undefined['"]|window\.jQuery\s*&&/g;

    for (const abs of files) {
        const rel = toPosix(path.relative(ROOT, abs));
        if (JQUERY_ALLOWED_FILES.has(rel)) {
            continue;
        }
        const content = fs.readFileSync(abs, 'utf8');
        pattern.lastIndex = 0;
        let match;
        while ((match = pattern.exec(content)) !== null) {
            const pos = lineAndColumn(content, match.index);
            issues.push({
                type: 'pattern',
                warn: false,
                label: 'window.$ guard in componente puro (usare jQuery come dipendenza esplicita)',
                file: rel,
                line: pos.line,
                col: pos.col,
            });
        }
    }
}

// --------------------------------------------------------------------------
// Blocco 2 — $.ajax diretto fuori da Request.js
// Cerca $.ajax() usato direttamente nelle feature e nei componenti.
// $.fn NON viene controllato: è usato legittimamente per verificare plugin
// jQuery (es. $.fn.summernote, $.fn.select2).
// --------------------------------------------------------------------------

function checkDirectJQueryAjax(issues) {
    const dirs = ['assets/js/components', 'assets/js/app'];
    const patternAjax = /\$\.ajax\s*\(/g;

    for (const dir of dirs) {
        const files = collectJs(dir);
        for (const abs of files) {
            const rel = toPosix(path.relative(ROOT, abs));
            if (JQUERY_ALLOWED_FILES.has(rel)) {
                continue;
            }
            const content = fs.readFileSync(abs, 'utf8');

            patternAjax.lastIndex = 0;
            let match;
            while ((match = patternAjax.exec(content)) !== null) {
                const pos = lineAndColumn(content, match.index);
                issues.push({
                    type: 'pattern',
                    warn: false,
                    label: '$.ajax() diretto — usare Request.http invece di $.ajax()',
                    file: rel,
                    line: pos.line,
                    col: pos.col,
                });
            }
        }
    }
}

// --------------------------------------------------------------------------
// Blocco 3 — Pattern vietati nelle view Twig
// --------------------------------------------------------------------------

function checkTwigPatterns(issues) {
    const files = collectTwig('app/views');

    // Regex per script inline eseguibile:
    // <script> senza src= e senza type="application/json" (multiriga tollerata dal flag s)
    const inlineScriptPattern = /<script(?![^>]*\bsrc\s*=)(?![^>]*\btype\s*=\s*["']application\/json["'])[^>]*>/gi;
    const onclickPattern = /\bonclick\s*=/g;
    const fetchPattern = /\bfetch\s*\(/g;
    const jqueryAjaxPattern = /\$\.ajax\s*\(/g;

    for (const abs of files) {
        const rel = toPosix(path.relative(ROOT, abs));
        const isLayoutAllowlisted = TWIG_INLINE_SCRIPT_WARN_ALLOWLIST.has(rel);
        const content = fs.readFileSync(abs, 'utf8');

        // Script inline
        inlineScriptPattern.lastIndex = 0;
        let match;
        while ((match = inlineScriptPattern.exec(content)) !== null) {
            // Ignora type="text/javascript" con solo `src=` (già filtrato dalla regex)
            const pos = lineAndColumn(content, match.index);
            issues.push({
                type: 'pattern',
                warn: isLayoutAllowlisted,
                label: isLayoutAllowlisted
                    ? 'Script inline nel layout (accettato solo per data injection minimale)'
                    : 'Script inline eseguibile nella view Twig (vietato)',
                file: rel,
                line: pos.line,
                col: pos.col,
            });
        }

        // onclick inline
        onclickPattern.lastIndex = 0;
        while ((match = onclickPattern.exec(content)) !== null) {
            const pos = lineAndColumn(content, match.index);
            issues.push({
                type: 'pattern',
                warn: false,
                label: 'onclick inline nel markup Twig (usare data-* e listener JS)',
                file: rel,
                line: pos.line,
                col: pos.col,
            });
        }

        // fetch( sparso
        fetchPattern.lastIndex = 0;
        while ((match = fetchPattern.exec(content)) !== null) {
            const pos = lineAndColumn(content, match.index);
            issues.push({
                type: 'pattern',
                warn: false,
                label: 'fetch() sparso nel markup Twig (usare Request.http)',
                file: rel,
                line: pos.line,
                col: pos.col,
            });
        }

        // $.ajax sparso
        jqueryAjaxPattern.lastIndex = 0;
        while ((match = jqueryAjaxPattern.exec(content)) !== null) {
            const pos = lineAndColumn(content, match.index);
            issues.push({
                type: 'pattern',
                warn: false,
                label: '$.ajax() sparso nel markup Twig (usare Request.http)',
                file: rel,
                line: pos.line,
                col: pos.col,
            });
        }
    }
}

// --------------------------------------------------------------------------
// Blocco 4 — Coerenza bootstrap negli entry point
// --------------------------------------------------------------------------

function checkEntryBootstrap(issues) {
    const entriesDir = path.join(ROOT, ENTRIES_DIR);
    if (!fs.existsSync(entriesDir)) {
        return;
    }

    const entries = fs.readdirSync(entriesDir).filter(f => f.endsWith('.entry.js'));
    for (const entryFile of entries) {
        const abs = path.join(entriesDir, entryFile);
        const rel = toPosix(path.relative(ROOT, abs));
        const content = fs.readFileSync(abs, 'utf8');

        const importCount = (content.match(/^import\s+/gm) || []).length;
        if (importCount === 0) {
            issues.push({
                type: 'pattern',
                warn: true,
                label: 'Entry point completamente vuoto — nessun import trovato',
                file: rel,
                line: 1,
                col: 1,
            });
            continue;
        }

        // Solo i core entry devono includere il bootstrap runtime direttamente.
        // I page bundle ricevono il bootstrap dal core bundle caricato prima.
        if (CORE_ENTRY_PATTERN.test(entryFile) && !BOOTSTRAP_IMPORT_PATTERN.test(content)) {
            issues.push({
                type: 'pattern',
                warn: false,
                label: 'Core entry point senza import di RuntimeBootstrap o AppBootstrap',
                file: rel,
                line: 1,
                col: 1,
            });
        }
    }
}

// --------------------------------------------------------------------------
// Blocco 5 — Import path alias legacy
// --------------------------------------------------------------------------

function checkLegacyImportAliases(issues) {
    const files = collectJs('assets/js/app');

    for (const abs of files) {
        const rel = toPosix(path.relative(ROOT, abs));
        const content = fs.readFileSync(abs, 'utf8');

        for (const { label, regex } of LEGACY_IMPORT_PATTERNS) {
            regex.lastIndex = 0;
            let match;
            while ((match = regex.exec(content)) !== null) {
                const pos = lineAndColumn(content, match.index);
                issues.push({
                    type: 'pattern',
                    warn: false,
                    label,
                    file: rel,
                    line: pos.line,
                    col: pos.col,
                });
            }
        }
    }
}

// --------------------------------------------------------------------------
// Main
// --------------------------------------------------------------------------

function main() {
    const issues = [];

    checkJQueryGuardInComponents(issues);
    checkDirectJQueryAjax(issues);
    checkTwigPatterns(issues);
    checkEntryBootstrap(issues);
    checkLegacyImportAliases(issues);

    const warns = issues.filter(i => i.warn);
    const fails = issues.filter(i => !i.warn);

    if (issues.length === 0) {
        console.log('[guardrails] PASS: nessuna violazione pattern frontend.');
        process.exit(0);
    }

    if (fails.length === 0) {
        console.warn('[guardrails] PASS con warning: nessuna violazione bloccante.');
        for (const w of warns) {
            logIssue(w);
        }
        process.exit(0);
    }

    console.error('[guardrails] FAIL: trovate violazioni pattern frontend.');
    for (const issue of issues) {
        logIssue(issue);
    }
    console.error(`\nRiepilogo: ${fails.length} FAIL, ${warns.length} WARN.`);
    process.exit(1);
}

main();
