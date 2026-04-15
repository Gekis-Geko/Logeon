<?php

const CONFIG = [
    'debug' => false,
    'cache' => [
        'enabled' => true,
        'ttl' => 300,
        'dir' => __DIR__ . '/../tmp/cache',
    ],
    'dirs' => [
        'base' => '/app',
        'core' => '/core',
        'views' => '/app/views',
        'assets' => '/assets',
        'imgs' => '/assets/imgs',
        'tmp' => __DIR__ . '/../tmp',
    ],
    'password_length' => 5,
    'session_time_life' => 5400,
    'location_chat_history_hours' => 3,
    'location_whisper_retention_hours' => 24,
    'inventory' => [
        'capacity_max' => 30,
        'stack_max' => 50,
    ],
    'chat_commands' => [
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
            'value' => '/sussurra @nome-personaggio "',
            'hint' => 'Sussurro 1:1',
            'aliases' => ['/w', '/whisper'],
            'kind' => 'whisper',
        ],
        [
            'key' => '/w',
            'value' => '/w @nome-personaggio "',
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
    ],
];
