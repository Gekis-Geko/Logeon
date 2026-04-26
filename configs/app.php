<?php

const APP = [
  'baseurl' => 'https://logeon.site',
  'lang' => 'it',
  'name' => 'Logeon - Engine PbC',
  'title' => 'Logeon - Engine PbC',
  'description' => 'Piattaforma per la creazione e la gestione di <em>browser game</em> di genere <em>giochi di ruolo</em> (gdr) in ambito del play by chat.',
  'brand_logo_icon' => '/assets/imgs/logo/logo.png',
  'brand_logo_wordmark' => '/assets/imgs/logo/logo_tipografy.png',
  'wm_name' => 'Fabio Fondi',
  'wm_email' => 'fabio.fondi.88@gmail.com',
  'dba_name' => 'Fabio Fondi',
  'dba_email' => 'fabio.fondi.88@gmail.com',
  'support_name' => 'Fabio Fondi',
  'support_email' => 'fabio.fondi.88@gmail.com',
  'shop' =>
   [
    'sell_ratio' => 0.5,
  ],
  'oauth_google' =>
   [
    'enabled' => false,
    'client_id' => '',
    'client_secret' => '',
    'redirect_uri' => '',
  ],
  'frontend' =>
   [
    'pilot_bundle_mode' => 'auto',
    'pilot_bundle_enabled' => false,
    'pilot_bundle_version' => '20260409',
  ],
  'pwa' =>
   [
    'enabled' => true,
    'name' => 'Logeon - Engine PbC',
    'short_name' => 'Logeon',
    'description' => 'Motore open-source per giochi play-by-chat installabile come app.',
    'start_path' => '/',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'theme_color' => '#0d6efd',
    'background_color' => '#ffffff',
    'icon_path' => '/assets/imgs/logo/logo.png',
    'icon_192_path' => '/assets/imgs/logo/pwa-192.png',
    'icon_512_path' => '/assets/imgs/logo/pwa-512.png',
    'icon_maskable_path' => '',
    'cache_enabled' => true,
    'cache_version' => '20260426',
  ],
  'theme' =>
   [
    'enabled' => true,
    'active_theme' => '',
    'strict_mode' => true,
    'allow_custom_js' => true,
  ],
];
