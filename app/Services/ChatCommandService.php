<?php

declare(strict_types=1);

namespace App\Services;

class ChatCommandService
{
    private $catalogCache = null;
    private $supportedCommandsCache = null;

    private function defaultCatalog()
    {
        return [
            [
                'key' => '/dado',
                'value' => '/dado 1d20',
                'hint' => 'Tiro di dado. Esempio: /dado 2d6',
                'aliases' => ['/dice'],
                'kind' => 'dice',
            ],
            [
                'key' => '/skill',
                'value' => '/skill ',
                'hint' => 'Usa una skill in chat',
                'kind' => 'skill',
            ],
            [
                'key' => '/oggetto',
                'value' => '/oggetto ',
                'hint' => 'Usa un oggetto in chat',
                'kind' => 'oggetto',
            ],
            [
                'key' => '/conflitto',
                'value' => '/conflitto @',
                'hint' => 'Proponi un conflitto in location (es. /conflitto @12 Duello rituale)',
                'kind' => 'conflitto',
            ],
            [
                'key' => '/sussurra',
                'value' => '/sussurra "',
                'hint' => 'Sussurro 1:1',
                'aliases' => ['/w', '/whisper'],
                'kind' => 'whisper',
            ],
            [
                'key' => '/w',
                'value' => '/w "',
                'hint' => 'Alias sussurro',
                'kind' => 'whisper',
            ],
            [
                'key' => '/fato',
                'value' => '/fato ',
                'hint' => 'Narrazione Fato (solo master/staff)',
                'kind' => 'fato',
            ],
            [
                'key' => '/png',
                'value' => '/png @',
                'hint' => 'Messaggio come PNG narrativo. Es: /png @NomePNG messaggio',
                'kind' => 'png',
            ],
            [
                'key' => '/lascia',
                'value' => '/lascia ',
                'hint' => 'Lascia un oggetto a terra. Es: /lascia @SpadaMagica',
                'kind' => 'lascia',
            ],
            [
                'key' => '/dai',
                'value' => '/dai ',
                'hint' => 'Dai monete a un personaggio in location. Es: /dai @Mario 50',
                'kind' => 'dai',
            ],
        ];
    }

    private function normalizeCommandToken($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }
        return ($value[0] === '/') ? $value : ('/' . $value);
    }

    private function normalizeCatalogRow($row)
    {
        if (!is_array($row)) {
            return null;
        }

        $key = $this->normalizeCommandToken($row['key'] ?? '');
        if ($key === '') {
            return null;
        }

        $aliases = [];
        if (isset($row['aliases']) && is_array($row['aliases'])) {
            foreach ($row['aliases'] as $alias) {
                $token = $this->normalizeCommandToken($alias);
                if ($token !== '' && !in_array($token, $aliases, true)) {
                    $aliases[] = $token;
                }
            }
        }

        return [
            'key' => $key,
            'value' => isset($row['value']) ? (string) $row['value'] : ($key . ' '),
            'hint' => isset($row['hint']) ? (string) $row['hint'] : '',
            'aliases' => $aliases,
            'kind' => isset($row['kind']) ? strtolower(trim((string) $row['kind'])) : '',
        ];
    }

    public function getCatalog()
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }

        $source = (defined('CONFIG') && isset(CONFIG['chat_commands']) && is_array(CONFIG['chat_commands']))
            ? CONFIG['chat_commands']
            : $this->defaultCatalog();

        $out = [];
        foreach ($source as $row) {
            $normalized = $this->normalizeCatalogRow($row);
            if ($normalized === null) {
                continue;
            }
            $out[] = $normalized;
        }

        if (empty($out)) {
            foreach ($this->defaultCatalog() as $row) {
                $normalized = $this->normalizeCatalogRow($row);
                if ($normalized !== null) {
                    $out[] = $normalized;
                }
            }
        }

        $this->catalogCache = $out;
        return $this->catalogCache;
    }

    public function getSupportedCommands()
    {
        if ($this->supportedCommandsCache !== null) {
            return $this->supportedCommandsCache;
        }

        $out = [];
        foreach ($this->getCatalog() as $row) {
            $tokens = [$row['key']];
            if (!empty($row['aliases']) && is_array($row['aliases'])) {
                foreach ($row['aliases'] as $alias) {
                    $tokens[] = $alias;
                }
            }
            foreach ($tokens as $token) {
                $token = $this->normalizeCommandToken($token);
                if ($token === '' || in_array($token, $out, true)) {
                    continue;
                }
                $out[] = $token;
            }
        }

        $this->supportedCommandsCache = $out;
        return $this->supportedCommandsCache;
    }

    public function getCommandDefinition($command)
    {
        $command = $this->normalizeCommandToken($command);
        if ($command === '') {
            return null;
        }

        foreach ($this->getCatalog() as $row) {
            if ($row['key'] === $command) {
                return $row;
            }
            if (!empty($row['aliases']) && in_array($command, $row['aliases'], true)) {
                return $row;
            }
        }

        return null;
    }

    public function resolveKind($command)
    {
        $definition = $this->getCommandDefinition($command);
        if (!is_array($definition)) {
            return null;
        }
        $kind = trim((string) ($definition['kind'] ?? ''));
        return ($kind === '') ? null : strtolower($kind);
    }

    private function normalizeModifierList($modifiers)
    {
        $out = [];
        if (!is_array($modifiers)) {
            return $out;
        }
        foreach ($modifiers as $mod) {
            $out[] = (int) $mod;
        }
        return $out;
    }

    private function sumModifiers($modifiers)
    {
        $total = 0;
        foreach ($this->normalizeModifierList($modifiers) as $mod) {
            $total += $mod;
        }
        return $total;
    }

    private function formatModifiers($modifiers)
    {
        $items = [];
        foreach ($this->normalizeModifierList($modifiers) as $mod) {
            $items[] = ($mod >= 0 ? '+' : '') . $mod;
        }
        return implode(' ', $items);
    }

    public function formatDiceResult($result, $options = [])
    {
        if (!is_array($result)) {
            return '';
        }

        $includeExpression = !array_key_exists('include_expression', $options) || (bool) $options['include_expression'];
        $includeRolls = !array_key_exists('include_rolls', $options) || (bool) $options['include_rolls'];
        $includeTotal = !array_key_exists('include_total', $options) || (bool) $options['include_total'];

        $expression = trim((string) ($result['expression'] ?? ''));
        $rolls = isset($result['rolls']) && is_array($result['rolls']) ? $result['rolls'] : [];
        $modifiers = isset($result['modifiers']) && is_array($result['modifiers']) ? $result['modifiers'] : [];
        $total = (int) ($result['total'] ?? 0);

        $parts = [];
        if ($includeExpression && $expression !== '') {
            $parts[] = $expression;
        }
        if ($includeRolls && !empty($rolls)) {
            $parts[] = '[' . implode(', ', array_map('intval', $rolls)) . ']';
        }

        $mods = $this->formatModifiers($modifiers);
        if ($mods !== '') {
            $parts[] = $mods;
        }

        if ($includeTotal) {
            $parts[] = '= ' . $total;
        }

        return trim(implode(' ', $parts));
    }

    public function parse($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strpos($raw, '/') !== 0) {
            return [
                'is_command' => false,
                'command' => null,
                'args' => '',
            ];
        }

        $parts = preg_split('/\s+/', $raw, 2);
        $command = strtolower($parts[0] ?? '');
        $args = trim((string) ($parts[1] ?? ''));

        return [
            'is_command' => true,
            'command' => $command,
            'args' => $args,
        ];
    }

    public function isSupported($command)
    {
        $command = $this->normalizeCommandToken($command);
        if ($command === '') {
            return false;
        }
        return in_array($command, $this->getSupportedCommands(), true);
    }

    public function parseDiceExpression($args)
    {
        $expr = trim((string) $args);
        if ($expr === '') {
            $expr = '1d20';
        }

        if (!preg_match('/^(\d*)d(\d+)(([+-]\d+)*)$/i', $expr, $m)) {
            return null;
        }

        $count = ($m[1] !== '') ? (int) $m[1] : 1;
        $sides = (int) $m[2];
        if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
            return null;
        }

        $modifiers = [];
        if (!empty($m[3])) {
            $mods = [];
            preg_match_all('/[+-]\d+/', $m[3], $mods);
            if (!empty($mods[0])) {
                foreach ($mods[0] as $mod) {
                    $modifiers[] = (int) $mod;
                }
            }
        }

        return [
            'expression' => strtolower($expr),
            'count' => $count,
            'sides' => $sides,
            'modifiers' => $modifiers,
        ];
    }

    public function rollDice($args)
    {
        $parsed = $this->parseDiceExpression($args);
        if ($parsed === null) {
            return null;
        }

        $rolls = [];
        $subtotal = 0;
        for ($i = 0; $i < $parsed['count']; $i++) {
            $roll = random_int(1, $parsed['sides']);
            $rolls[] = $roll;
            $subtotal += $roll;
        }

        $modifierTotal = $this->sumModifiers($parsed['modifiers']);
        $total = $subtotal + $modifierTotal;

        $result = [
            'expression' => $parsed['expression'],
            'count' => $parsed['count'],
            'sides' => $parsed['sides'],
            'rolls' => $rolls,
            'modifiers' => $parsed['modifiers'],
            'subtotal' => $subtotal,
            'modifier_total' => $modifierTotal,
            'total' => $total,
        ];

        $result['formatted'] = $this->formatDiceResult($result, [
            'include_expression' => true,
            'include_rolls' => true,
            'include_total' => true,
        ]);
        $result['formatted_short'] = $this->formatDiceResult($result, [
            'include_expression' => false,
            'include_rolls' => true,
            'include_total' => true,
        ]);

        return $result;
    }

    public function parseWhisperArgs($args)
    {
        $args = trim((string) $args);
        if ($args === '') {
            return null;
        }

        // /sussurra "@Nome Cognome" corpo  oppure  /sussurra "Nome Cognome" corpo
        if (preg_match('/^"([^"]+)"\s+(.+)$/s', $args, $m)) {
            return [
                'target' => ltrim(trim($m[1]), '@'),
                'body' => $this->stripOuterQuotes(trim($m[2])),
            ];
        }

        // /sussurra @ID corpo  (ID numerico)
        if (preg_match('/^@(\d+)\s+(.+)$/s', $args, $m)) {
            return [
                'target_id' => (int) $m[1],
                'target' => '',
                'body' => $this->stripOuterQuotes(trim($m[2])),
            ];
        }

        // /sussurra @NomeSingola corpo  oppure  /sussurra NomeSingola corpo
        $parts = preg_split('/\s+/', $args, 2);
        $target = ltrim(trim((string) ($parts[0] ?? '')), '@');
        $body = $this->stripOuterQuotes(trim((string) ($parts[1] ?? '')));
        if ($target === '' || $body === '') {
            return null;
        }

        return [
            'target' => $target,
            'body' => $body,
        ];
    }

    /**
     * /lascia NomeOggetto [quantità]
     * /lascia @NomeOggetto [quantità]
     *
     * Returns ['item_name' => string, 'quantity' => int] or null.
     */
    public function parseLasciaArgs(string $args): ?array
    {
        $args = trim($args);
        if ($args === '') {
            return null;
        }

        // Strip leading @ from whole token or quoted name
        $args = ltrim($args, '@');

        // Split on whitespace
        $parts = preg_split('/\s+/', $args);
        if (empty($parts)) {
            return null;
        }

        $quantity = 1;
        $last = end($parts);
        if (ctype_digit($last) && count($parts) > 1) {
            $quantity = max(1, (int) $last);
            array_pop($parts);
        }

        $itemName = trim(implode(' ', $parts));
        if ($itemName === '') {
            return null;
        }

        return [
            'item_name' => $itemName,
            'quantity' => $quantity,
        ];
    }

    /**
     * /dai @NomeCognome quantità
     * /dai NomeCognome quantità
     * /dai @ID quantità (numeric character ID)
     *
     * Returns ['target' => string, 'amount' => int] or ['target_id' => int, 'amount' => int] or null.
     */
    public function parseGiveCurrencyArgs(string $args): ?array
    {
        $args = trim($args);
        if ($args === '') {
            return null;
        }

        // Last token must be a positive integer (amount)
        $parts = preg_split('/\s+/', $args);
        if (count($parts) < 2) {
            return null;
        }

        $amountRaw = array_pop($parts);
        if (!ctype_digit($amountRaw) || (int) $amountRaw <= 0) {
            return null;
        }
        $amount = (int) $amountRaw;

        $targetRaw = trim(implode(' ', $parts));
        if ($targetRaw === '') {
            return null;
        }

        // @ID numeric
        if (preg_match('/^@(\d+)$/', $targetRaw, $m)) {
            return [
                'target_id' => (int) $m[1],
                'amount' => $amount,
            ];
        }

        return [
            'target' => ltrim($targetRaw, '@'),
            'amount' => $amount,
        ];
    }

    /**
     * /png @NomePNG messaggio
     * /png @"Nome PNG" messaggio
     *
     * Returns ['npc_name' => string, 'body' => string] or null.
     */
    public function parsePngArgs(string $args): ?array
    {
        $args = trim($args);
        if ($args === '') {
            return null;
        }

        // /png @"Nome PNG con spazi" messaggio
        if (preg_match('/^@"([^"]+)"\s+(.+)$/s', $args, $m)) {
            return [
                'npc_name' => trim($m[1]),
                'body' => trim($m[2]),
            ];
        }

        // /png @NomeSingola messaggio  oppure  /png NomeSingola messaggio
        $parts = preg_split('/\s+/', ltrim($args, '@'), 2);
        $npcName = trim((string) ($parts[0] ?? ''));
        $body = trim((string) ($parts[1] ?? ''));

        if ($npcName === '' || $body === '') {
            return null;
        }

        return [
            'npc_name' => $npcName,
            'body' => $body,
        ];
    }

    private function stripOuterQuotes(string $text): string
    {
        if (strlen($text) >= 2 && $text[0] === '"' && $text[strlen($text) - 1] === '"') {
            return trim(substr($text, 1, -1));
        }
        return $text;
    }
}
