<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\MultiCurrency\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

return static function ($moduleRuntime = null, $moduleManifest = null): void {
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    \Core\Hooks::add('twig.view_paths', static function ($paths) {
        if (!is_array($paths)) {
            $paths = [];
        }
        $viewPath = __DIR__ . '/views';
        if (!in_array($viewPath, $paths, true)) {
            $paths[] = $viewPath;
        }
        return $paths;
    });

    \Core\Hooks::add('twig.slot.character.profile.wallets', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'multi-currency-profile-wallets',
            'template' => 'multi-currency/profile/wallets-extra.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.shop.price.extra', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'multi-currency-shop-price-extra',
            'template' => 'multi-currency/shop/price-extra.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('currency.extra_wallets', static function ($wallets, $characterId = 0) {
        if (!is_array($wallets)) {
            $wallets = [];
        }

        // E-4: hook registrato. In questo step non aggiungiamo righe extra
        // per evitare duplicazioni con il payload core gia presente.
        return $wallets;
    });

    \Core\Hooks::add('currency.available_list', static function ($available) {
        if (!is_array($available)) {
            $available = [];
        }

        try {
            return (new \Modules\Logeon\MultiCurrency\Services\AdditionalCurrencyService())
                ->extendAvailableList($available);
        } catch (\Throwable $e) {
            return $available;
        }
    });

    \Core\Hooks::add('app.module_endpoints', static function ($endpoints) {
        if (!is_array($endpoints)) {
            $endpoints = [];
        }

        $endpoints['currenciesList'] = '/admin/multi-currencies/list';
        $endpoints['currenciesCreate'] = '/admin/multi-currencies/create';
        $endpoints['currenciesUpdate'] = '/admin/multi-currencies/update';
        $endpoints['currenciesDelete'] = '/admin/multi-currencies/delete';

        return $endpoints;
    });
};
