# Modulo Logeon Weather

## Scopo
Questo modulo registra un provider Meteo via hook `weather.provider`, usando il contract condiviso `App\Contracts\WeatherProviderInterface`.

## Stato attuale
- versione iniziale di bootstrap/bridge;
- implementazione provider nel modulo (`src/WeatherModuleProvider.php`);
- nessuna route o asset aggiuntiva.

## Integrazione runtime
1. `module.json` dichiara `entrypoints.bootstrap = bootstrap.php`.
2. `bootstrap.php` registra `Hooks::add('weather.provider', ...)`.
3. Il core risolve il provider tramite `App\Services\WeatherProviderRegistry`.

## Note operative
- Nessuna dipendenza hard del core verso questo modulo.
- Con modulo disattivo il fallback resta `CoreWeatherProvider`.
- Con modulo attivo il provider modulo viene selezionato dal registry.
