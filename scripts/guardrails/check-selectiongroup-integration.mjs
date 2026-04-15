#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();

function read(relPath) {
    const abs = path.join(ROOT, relPath);
    if (!fs.existsSync(abs)) {
        throw new Error(`Missing file: ${relPath}`);
    }
    return fs.readFileSync(abs, 'utf8');
}

function has(content, pattern) {
    if (pattern instanceof RegExp) {
        return pattern.test(content);
    }
    return content.indexOf(pattern) !== -1;
}

function assert(condition, message, issues) {
    if (!condition) {
        issues.push(message);
    }
}

function main() {
    const issues = [];

    const header = read('app/views/layouts/header.twig');
    const idxSelection = header.indexOf('/assets/js/components/SelectionGroup.js');
    const idxCheck = header.indexOf('/assets/js/components/CheckGroup.js');
    const idxRadio = header.indexOf('/assets/js/components/RadioGroup.js');

    assert(idxSelection !== -1, 'header.twig: include SelectionGroup.js mancante.', issues);
    assert(idxCheck !== -1, 'header.twig: include CheckGroup.js mancante.', issues);
    assert(idxRadio !== -1, 'header.twig: include RadioGroup.js mancante.', issues);
    if (idxSelection !== -1 && idxCheck !== -1) {
        assert(idxSelection < idxCheck, 'header.twig: SelectionGroup.js deve essere caricato prima di CheckGroup.js.', issues);
    }
    if (idxSelection !== -1 && idxRadio !== -1) {
        assert(idxSelection < idxRadio, 'header.twig: SelectionGroup.js deve essere caricato prima di RadioGroup.js.', issues);
    }

    const checkGroup = read('assets/js/components/CheckGroup.js');
    const radioGroup = read('assets/js/components/RadioGroup.js');
    assert(has(checkGroup, /SelectionGroup\s*\(/), 'CheckGroup.js: adapter verso SelectionGroup non trovato.', issues);
    assert(has(radioGroup, /SelectionGroup\s*\(/), 'RadioGroup.js: adapter verso SelectionGroup non trovato.', issues);

    const targets = [
        { file: 'assets/js/app/features/platform/SettingsPage.js', checks: [/CheckGroup\s*\(/, /RadioGroup\s*\(/] },
        { file: 'assets/js/app/features/platform/ShopPage.js', checks: [/RadioGroup\s*\(/] },
        { file: 'assets/js/app/features/platform/WeatherPage.js', checks: [/RadioGroup\s*\(/] },
        { file: 'assets/js/app/features/platform/MessagesPage.js', checks: [/RadioGroup\s*\(/] },
        { file: 'assets/js/app/core/platform.ui.js', checks: [/RadioGroup\s*\(/] }
    ];

    for (const target of targets) {
        const content = read(target.file);
        for (const rule of target.checks) {
            assert(has(content, rule), `${target.file}: callsite atteso non trovato (${rule}).`, issues);
        }
    }

    if (issues.length === 0) {
        console.log('[guardrails] PASS: integrazione SelectionGroup verificata sui target gameplay.');
        process.exit(0);
    }

    console.error('[guardrails] FAIL: problemi integrazione SelectionGroup.');
    for (const issue of issues) {
        console.error('- ' + issue);
    }
    process.exit(1);
}

main();
