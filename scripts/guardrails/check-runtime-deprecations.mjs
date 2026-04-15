#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();
const TARGET_DIRS = [
    'assets/js',
    'app/views',
    'configs',
    'custom',
    'core'
];
const ALLOWED_EXTENSIONS = new Set([
    '.js',
    '.mjs',
    '.cjs',
    '.twig',
    '.php',
    '.html'
]);
const SKIP_DIRS = new Set([
    '.git',
    'vendor',
    'tmp',
    'db_backup',
    'node_modules',
    'assets/vendor'
]);

const DIRECT_REQUEST_SCOPE = 'assets/js/components/';
const DIRECT_REQUEST_EXCLUDES = new Set([
    'assets/js/services/Request.js'
]);

const FORBIDDEN_FILES = [
    'assets/js/components/TooltipManager.js',
    'assets/js/components/BackofficeMenu.js',
    'assets/js/components/BackofficeDashboard.js',
    'assets/js/components/BackofficeLegacyIndex.js'
];

const FORBIDDEN_PATTERNS = [
    { label: 'TooltipManager alias', regex: /\bTooltipManager\b/g },
    { label: 'Deprecated include BackofficeMenu.js', regex: /BackofficeMenu\.js/g },
    { label: 'Deprecated include BackofficeDashboard.js', regex: /BackofficeDashboard\.js/g },
    { label: 'Deprecated include BackofficeLegacyIndex.js', regex: /\/assets\/js\/components\/BackofficeLegacyIndex\.js/g },
    { label: 'Deprecated include TooltipManager.js', regex: /TooltipManager\.js/g },
    { label: 'Deprecated open message modal alias', regex: /\bPlatformLegacyOpenMessageModal\b/g },
    { label: 'Deprecated factory mapping token', regex: /\blegacyFactory\b/g },
    { label: 'Deprecated Platform* token', regex: /\bPlatformLegacy[A-Za-z0-9_]+\b/g },
    { label: 'Deprecated AppCore name token', regex: /\bAppLegacyCore\b/g },
    { label: 'Deprecated AppCore filename include', regex: /AppLegacyCore\.js/g },
    { label: 'Deprecated runtime variable name', regex: /\b(legacyAppFactory|legacyInstance|legacyPath)\b/g },
    { label: 'Deprecated global module resolver alias', regex: /\bwindow\.resolveAppModule\b/g },
    { label: 'Deprecated global runtime restart alias', regex: /\bwindow\.restartPlatformRuntime\b/g },
    { label: 'Deprecated global runtime resolver alias', regex: /\bwindow\.resolveRuntimeForModules\b/g },
    { label: 'Deprecated global runtime mode alias', regex: /\bwindow\.isPlatformBootstrapRuntime\b/g }
];

function toPosix(filePath) {
    return filePath.split(path.sep).join('/');
}

function walk(dirPath, outFiles) {
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
            walk(absolute, outFiles);
            continue;
        }

        if (!entry.isFile()) {
            continue;
        }

        const ext = path.extname(entry.name).toLowerCase();
        if (!ALLOWED_EXTENSIONS.has(ext)) {
            continue;
        }

        outFiles.push(absolute);
    }
}

function collectFiles() {
    const files = [];
    for (const dir of TARGET_DIRS) {
        walk(path.join(ROOT, dir), files);
    }
    return files;
}

function lineAndColumn(content, index) {
    const before = content.slice(0, index);
    const lines = before.split('\n');
    const line = lines.length;
    const col = lines[lines.length - 1].length + 1;
    return { line, col };
}

function checkForbiddenFiles(issues) {
    for (const file of FORBIDDEN_FILES) {
        const absolute = path.join(ROOT, file);
        if (fs.existsSync(absolute)) {
            issues.push({
                type: 'file',
                label: 'Deprecated file still present',
                file
            });
        }
    }
}

function checkForbiddenPatterns(files, issues) {
    for (const absoluteFile of files) {
        const relativeFile = toPosix(path.relative(ROOT, absoluteFile));
        const content = fs.readFileSync(absoluteFile, 'utf8');

        for (const rule of FORBIDDEN_PATTERNS) {
            rule.regex.lastIndex = 0;
            let match;
            while ((match = rule.regex.exec(content)) !== null) {
                const pos = lineAndColumn(content, match.index);
                issues.push({
                    type: 'pattern',
                    label: rule.label,
                    file: relativeFile,
                    line: pos.line,
                    col: pos.col,
                    snippet: match[0]
                });
            }
        }
    }
}

function checkDirectRequestCalls(files, issues) {
    const directRequestRegex = /\bRequest\s*\(/g;

    for (const absoluteFile of files) {
        const relativeFile = toPosix(path.relative(ROOT, absoluteFile));
        if (!relativeFile.startsWith(DIRECT_REQUEST_SCOPE)) {
            continue;
        }
        if (DIRECT_REQUEST_EXCLUDES.has(relativeFile)) {
            continue;
        }

        const content = fs.readFileSync(absoluteFile, 'utf8');
        directRequestRegex.lastIndex = 0;

        let match;
        while ((match = directRequestRegex.exec(content)) !== null) {
            const pos = lineAndColumn(content, match.index);
            issues.push({
                type: 'pattern',
                label: 'Direct Request(...) usage is deprecated (use Request.http)',
                file: relativeFile,
                line: pos.line,
                col: pos.col,
                snippet: match[0]
            });
        }
    }
}

function main() {
    const issues = [];
    checkForbiddenFiles(issues);

    const files = collectFiles();
    checkForbiddenPatterns(files, issues);
    checkDirectRequestCalls(files, issues);

    if (issues.length === 0) {
        console.log('[guardrails] PASS: nessuna regressione su alias/file deprecati runtime.');
        process.exit(0);
    }

    console.error('[guardrails] FAIL: trovate regressioni deprecate runtime.');
    for (const issue of issues) {
        if (issue.type === 'file') {
            console.error(`- ${issue.label}: ${issue.file}`);
            continue;
        }
        console.error(`- ${issue.label}: ${issue.file}:${issue.line}:${issue.col} (${issue.snippet})`);
    }

    process.exit(1);
}

main();
