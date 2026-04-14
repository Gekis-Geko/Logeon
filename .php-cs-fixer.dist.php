<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/app/controllers',
        __DIR__ . '/app/routes',
        __DIR__ . '/app/Services',
        __DIR__ . '/app/Models',
        __DIR__ . '/configs',
        __DIR__ . '/core/Database',
        __DIR__ . '/core/Contracts',
        __DIR__ . '/core/Http',
        __DIR__ . '/core/Logging',
        __DIR__ . '/core/Adapters',
        __DIR__ . '/core/Models',
        __DIR__ . '/core/Services',
        __DIR__ . '/core/Templates',
        __DIR__ . '/core/Themes',
        __DIR__ . '/scripts/php',
    ])
    ->append([
        __DIR__ . '/autoload.php',
        __DIR__ . '/index.php',
    ])
    ->append(glob(__DIR__ . '/core/*.php'))
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_opening_tag' => true,
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'class_definition' => ['single_line' => true],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'native_function_casing' => true,
        'new_with_parentheses' => true,
        'no_closing_tag' => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'break',
                'continue',
                'extra',
                'return',
                'throw',
            ],
        ],
        'no_trailing_whitespace' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
    ])
    ->setFinder($finder);
