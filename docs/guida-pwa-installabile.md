# Guida PWA installabile

Ultimo aggiornamento: 2026-04-26

## Scopo
Abilitare Logeon come applicazione installabile su desktop e dispositivi mobili senza trasformare il gameplay in una app offline.

## Cosa fa il supporto PWA core
1. espone `manifest.webmanifest`
2. espone `service-worker.js`
3. registra automaticamente il service worker quando la PWA e abilitata
4. aggiunge meta e icone necessarie alla modalita installabile
5. precarica gli asset statici principali per ridurre tempi di avvio e download ripetuti

## Cosa non fa
1. non rende il gameplay completamente offline
2. non sincronizza azioni o chat in assenza di rete
3. non sostituisce la necessita di pubblicare il gioco in HTTPS

## Configurazione minima
Puoi configurare la PWA in due modi:
1. dal pannello admin in `Impostazioni -> PWA installabile`, che salva gli override in `sys_configs`
2. da `configs/app.php`, usando `APP['pwa']` come fallback di progetto o configurazione iniziale della release

Esempio:

```php
'pwa' =>
array (
  'enabled' => true,
  'name' => 'Nome del tuo gioco',
  'short_name' => 'Nome breve',
  'description' => 'Descrizione breve del gioco.',
  'start_path' => '/game',
  'scope' => '/',
  'display' => 'standalone',
  'orientation' => 'portrait',
  'theme_color' => '#1f2937',
  'background_color' => '#ffffff',
  'icon_path' => '/assets/imgs/logo/logo.png',
  'icon_192_path' => '/assets/imgs/logo/pwa-192.png',
  'icon_512_path' => '/assets/imgs/logo/pwa-512.png',
  'icon_maskable_path' => '/assets/imgs/logo/pwa-maskable.png',
  'cache_enabled' => true,
  'cache_version' => '20260426',
),
```

## Campi consigliati
1. `enabled`: attiva o disattiva la modalita installabile.
2. `name`: nome completo mostrato dal sistema operativo.
3. `short_name`: nome breve per launcher, home screen o dock.
4. `start_path`: pagina iniziale dell'app installata. Per molti giochi ha senso `/` oppure `/game`.
5. `scope`: per installazioni standard lascia `/`.
6. `theme_color`: colore UI usato da diversi browser e launcher.
7. `background_color`: colore di sfondo dello splash iniziale.
8. `cache_enabled`: abilita la cache asset gestita dal service worker.
9. `cache_version`: incrementalo quando vuoi forzare il rinnovo della cache asset.

## Icone
Logeon usa in fallback `APP['brand_logo_icon']` o `favicon.ico`, ma per una PWA curata e meglio fornire:
1. una icona quadrata `192x192`
2. una icona quadrata `512x512`
3. una icona maskable opzionale per Android

Nota:
`assets/imgs/logo/logo.png` nel repository attuale e `112x112`. Funziona come fallback tecnico, ma non e la dimensione ideale per tutte le esperienze di installazione.

## Requisiti di pubblicazione
1. pubblica il gioco in HTTPS
2. non spostare `manifest.webmanifest` o `service-worker.js`: sono gia serviti dal core
3. se cambi branding o bundle principali, aggiorna `cache_version`

## Dove vengono serviti i file PWA
Quando `enabled` e `true`, Logeon espone automaticamente:
1. `/manifest.webmanifest`
2. `/service-worker.js`

Se il gioco e installato in una sottocartella, i path vengono adattati in base a `APP['baseurl']`.

## Strategia consigliata per i creatori di gioco
1. attiva PWA solo quando il branding minimo e pronto
2. imposta `start_path` su `/` se vuoi una home pubblica installabile, oppure su `/game` se vuoi aprire subito l'area gioco
3. mantieni `cache_enabled` attivo solo per asset statici
4. non promettere gioco offline completo ai giocatori

## Checklist rapida
1. `APP['pwa']['enabled'] = true`
2. HTTPS attivo
3. icone PWA inserite
4. `start_path` verificato
5. `cache_version` aggiornato a ogni release che cambia asset critici
