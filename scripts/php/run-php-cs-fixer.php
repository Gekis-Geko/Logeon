<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fixerScript = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'friendsofphp' . DIRECTORY_SEPARATOR . 'php-cs-fixer' . DIRECTORY_SEPARATOR . 'php-cs-fixer';

if (!is_file($fixerScript)) {
    fwrite(
        STDERR,
        "[psr] php-cs-fixer non trovato.\n" .
        "[psr] Esegui: composer install\n",
    );
    exit(1);
}

$forwardArgs = array_slice($argv, 1);
$parts = [
    escapeshellarg(PHP_BINARY),
    escapeshellarg($fixerScript),
    'fix',
    '--config=.php-cs-fixer.dist.php',
    '--allow-risky=no',
];

foreach ($forwardArgs as $arg) {
    $parts[] = escapeshellarg((string) $arg);
}

$command = implode(' ', $parts);
passthru($command, $exitCode);
exit((int) $exitCode);
