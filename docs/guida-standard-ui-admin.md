# Standard UI Admin (Core)

Ultimo aggiornamento: 31 marzo 2026

## Obiettivo
Allineare tutte le pagine `/admin` a un modello unico, mantenendo eccezioni solo dove c'e una motivazione funzionale forte.

## Modello base (default)
1. Header pagina:
- Titolo + sottotitolo a sinistra.
- Azioni globali a destra (`Aggiorna`, `Nuovo`, ecc.).

2. Filtri:
- Blocco filtri dedicato sotto l'header.
- Controlli in formato compatto (`form-control-sm`, `form-select-sm`).
- Layout filtri: usare `row g-2 align-items-end` con celle `col-auto` (evitare `col-*` nei filtri standard).
- Ricerca live con debounce (senza click obbligatorio su `Applica/Cerca`), salvo eccezioni.
- `Reset` deve svuotare i filtri e aggiornare subito la tabella.

3. Tabella dati:
- Datagrid con ordinamento/paginazione standard.
- Colonna azioni uniforme.

4. Azioni riga:
- Icone con tooltip (`Modifica`, `Elimina`, `Dettaglio`, `Stato`).
- Stesso peso visivo su tutte le tabelle.

## Eccezioni consentite
Sono consentite varianti solo quando:
- la pagina e un pannello composito (es. wizard multi-sezione);
- il flusso richiede conferma esplicita prima di filtrare;
- la leggibilita peggiora con azioni solo a icona.

Ogni eccezione deve essere documentata nel file della feature (`assets/js/app/features/admin/<Pagina>.js`) con un commento breve.

## Implementazione tecnica corrente
- Normalizzazione centralizzata in `assets/js/app/core/admin.page.js`:
  - header/toolbar;
  - filtri compatti + live submit;
  - azioni datagrid uniformate a icone + tooltip.
- Stili comuni in:
  - `assets/sass/framework/_admin.scss`
  - `assets/css/admin.css`
