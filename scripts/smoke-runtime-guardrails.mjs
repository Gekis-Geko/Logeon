import fs from 'node:fs';
import path from 'node:path';

const projectRoot = process.cwd();
const scans = [
    {
        label: 'No deprecated __App token usage in components',
        target: 'assets/js/components',
        pattern: /\b__AppLegacy\b/g
    },
    {
        label: 'No direct App() invocation in components',
        target: 'assets/js/components',
        pattern: /\bApp\(\)/g
    },
    {
        label: 'No deprecated Platform* tokens in platform features',
        target: 'assets/js/app/features/platform',
        pattern: /\bPlatformLegacy[A-Za-z0-9_]+\b/g
    },
    {
        label: 'No deprecated AppCore token in components',
        target: 'assets/js/components',
        pattern: /\bAppLegacyCore\b/g
    }
];

function listFiles(dir) {
    const fullDir = path.join(projectRoot, dir);
    if (!fs.existsSync(fullDir)) {
        return [];
    }
    const out = [];
    const stack = [fullDir];
    while (stack.length) {
        const current = stack.pop();
        const entries = fs.readdirSync(current, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(current, entry.name);
            if (entry.isDirectory()) {
                stack.push(fullPath);
                continue;
            }
            if (entry.isFile() && fullPath.endsWith('.js')) {
                out.push(fullPath);
            }
        }
    }
    return out;
}

function relative(filePath) {
    return path.relative(projectRoot, filePath).replace(/\\/g, '/');
}

let failures = 0;

for (const scan of scans) {
    const files = listFiles(scan.target);
    const hits = [];
    for (const file of files) {
        const source = fs.readFileSync(file, 'utf8');
        if (scan.pattern.test(source)) {
            hits.push(relative(file));
        }
    }
    if (hits.length > 0) {
        failures += 1;
        console.error(`[FAIL] ${scan.label}`);
        for (const hit of hits) {
            console.error(`  - ${hit}`);
        }
    } else {
        console.log(`[PASS] ${scan.label}`);
    }
}

function assertIncludes(filePath, tokens, label) {
    const fullPath = path.join(projectRoot, filePath);
    if (!fs.existsSync(fullPath)) {
        failures += 1;
        console.error(`[FAIL] ${label}: file not found (${filePath})`);
        return;
    }
    const source = fs.readFileSync(fullPath, 'utf8');
    const missing = tokens.filter((token) => !source.includes(token));
    if (missing.length > 0) {
        failures += 1;
        console.error(`[FAIL] ${label}`);
        for (const token of missing) {
            console.error(`  - missing token: ${token}`);
        }
        return;
    }
    console.log(`[PASS] ${label}`);
}

function assertNoMatch(filePath, pattern, label) {
    const fullPath = path.join(projectRoot, filePath);
    if (!fs.existsSync(fullPath)) {
        failures += 1;
        console.error(`[FAIL] ${label}: file not found (${filePath})`);
        return;
    }
    const source = fs.readFileSync(fullPath, 'utf8');
    if (pattern.test(source)) {
        failures += 1;
        console.error(`[FAIL] ${label}`);
        return;
    }
    console.log(`[PASS] ${label}`);
}

assertIncludes(
    'assets/js/components/SelectionGroup.js',
    ['mount: function', 'unmount: function', 'value: function', 'setValue: function'],
    'SelectionGroup exposes unified lifecycle/value API'
);

assertIncludes(
    'assets/js/components/Uploader.js',
    ['validateFile: function', 'retryFile: function', 'retry: function', 'uploaderResolveServices'],
    'Uploader exposes validation/retry/services hooks'
);

assertNoMatch(
    'assets/js/app/core/platform.globals.js',
    /\bPlatformLegacy[A-Za-z0-9_]+\b/g,
    'PlatformGlobals has no deprecated Platform* fallback references'
);

assertNoMatch(
    'assets/js/app/core/platform.modals.js',
    /\bPlatformLegacy[A-Za-z0-9_]+\b/g,
    'PlatformModals has no deprecated Platform* fallback references'
);

assertNoMatch(
    'assets/js/app/core/platform.ui.js',
    /\bPlatformLegacy[A-Za-z0-9_]+\b/g,
    'PlatformUi has no deprecated Platform* fallback references'
);

assertNoMatch(
    'assets/js/app/core/platform.page.js',
    /\bPlatformLegacy[A-Za-z0-9_]+\b/g,
    'PlatformPage has no deprecated Platform* fallback references'
);

assertNoMatch(
    'assets/js/app/core/platform.page.js',
    /\blegacyFactory\b/g,
    'PlatformPage no longer uses deprecated factory mapping token'
);

assertNoMatch(
    'assets/js/app/modules/platform/MessagesModule.js',
    /\bPlatformLegacy[A-Za-z0-9_]+\b/g,
    'MessagesModule has no deprecated Platform* fallback references'
);

assertNoMatch(
    'assets/js/app/modules/platform/NewsModule.js',
    /\bPlatformLegacy[A-Za-z0-9_]+\b/g,
    'NewsModule has no deprecated Platform* fallback references'
);

assertNoMatch(
    'assets/js/app/features/platform/MessagesModal.js',
    /\bPlatformLegacyOpenMessageModal\b/g,
    'MessagesModal has no deprecated open-message alias fallback'
);

assertNoMatch(
    'assets/js/app/features/platform/location/LocationPage.js',
    /\b(syncLayoutLegacy|rollLegacy)\b/g,
    'LocationPage uses neutral fallback naming (no deprecated method names)'
);

assertNoMatch(
    'assets/js/components/BackofficeIndex.js',
    /\bboHandleLegacy(ClickAction|ChangeAction)\b/g,
    'BackofficeIndex uses neutral action handler naming'
);

assertIncludes(
    'app/views/backoffice/index.twig',
    ['/assets/js/components/BackofficeIndex.js'],
    'Backoffice index view includes neutral BackofficeIndex entrypoint'
);

assertNoMatch(
    'app/views/backoffice/index.twig',
    /\/assets\/js\/components\/BackofficeLegacyIndex\.js/g,
    'Backoffice index view no longer includes deprecated BackofficeLegacyIndex entrypoint'
);

assertNoMatch(
    'assets/js/app/features/platform/AppFacade.js',
    /\b(legacyAppFactory|legacyInstance)\b/g,
    'AppFacade uses neutral runtime variable naming'
);

assertNoMatch(
    'assets/js/components/Navbar.js',
    /\blegacyPath\b/g,
    'Navbar uses neutral path variable naming'
);

assertIncludes(
    'assets/js/components/CommandParser.js',
    ['validateDiceArgsDetailed', '/dado 2d6+1'],
    'CommandParser exposes detailed dice validation'
);

assertIncludes(
    'assets/js/app/features/platform/location/LocationChatPage.js',
    ['meta.formatted_short', 'validateDiceArgsDetailed'],
    'LocationChatPage consumes dice formatted metadata and validation'
);

assertIncludes(
    'app/services/ChatCommandService.php',
    ['formatted_short', 'modifier_total', 'formatDiceResult'],
    'ChatCommandService returns enriched dice payload'
);

assertIncludes(
    'app/controllers/LocationMessages.php',
    ['rollDice($args)', 'formatted_short'],
    'LocationMessages persists enriched dice metadata'
);

if (failures > 0) {
    console.error(`\nGuardrail smoke failed (${failures}).`);
    process.exit(1);
}

console.log('\nGuardrail smoke passed.');
