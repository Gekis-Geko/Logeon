# Temi Logeon (public/game)

Questa cartella ospita i temi custom.

Struttura consigliata:

```text
custom/themes/<theme-id>/
  theme.json
  views/
    layouts/
    app/layouts/
  assets/
    css/
    js/
    images/
    fonts/
```

Esempio `theme.json` minimo:

```json
{
  "id": "fantasy-dark",
  "name": "Fantasy Dark",
  "version": "1.0.0",
  "compat": {
    "core": ">=0.7.0 <1.0.0"
  },
  "shell": {
    "public_layout": "layouts/theme-public.twig",
    "game_layout": "app/layouts/theme-game.twig"
  },
  "assets": {
    "public_css": ["css/public.css"],
    "public_js": ["js/public.js"],
    "game_css": ["css/game.css"],
    "game_js": ["js/game.js"]
  }
}
```

Note:
1. admin non supporta override strutturale tema;
2. in caso di tema invalido il runtime torna automaticamente al core.
