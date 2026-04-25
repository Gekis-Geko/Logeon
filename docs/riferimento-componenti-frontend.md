# Riferimento Componenti Frontend

Ultimo aggiornamento: 2026-04-27

## Scopo
Riferimento sintetico dei componenti JS usati nel runtime Logeon.
Questa guida sostituisce la vecchia documentazione frammentata in molti file.

## Dove si trovano
1. Componenti shared: `assets/js/components/*`
2. Utility pure: `assets/js/components/utils/*`

## Componenti UI principali
1. `Modal.js`: wrapper lifecycle modale (show/hide, beforeShow, afterHide, form reset, spinner).
2. `Dialog.js`: conferme/alert standard.
3. `Toast.js`: notifiche toast.
4. `Tooltip.js`: tooltip runtime-safe.
5. `DataGrid.js`: tabelle con sorting/filter/paginazione.
6. `Paginator.js`: paginazione dataset.
7. `Search.js`: ricerca locale su liste/griglie.
8. `Uploader.js`: upload file con progress.
9. `TipTapEditor.js`: editor rich-text (sostituisce Summernote).

### TipTapEditor — note operative

**Auto-init**: al `DOMContentLoaded` su tutti gli elementi `.summernote, .richtext-editor`; nelle modali Bootstrap al `show.bs.modal`.

**API globale** (`window.TipTapEditor`):

```js
// Inizializza editor sullo scope indicato (o sull'intero document)
TipTapEditor.init(root, options);

// Recupera istanza esistente (null se non inizializzata)
const instance = TipTapEditor.getInstance(textarea);

// Inizializza se non esiste, restituisce l'istanza
const instance = TipTapEditor.ensureInstance(textarea, { height: 300 });

// Distrugge l'istanza e ripristina il textarea originale
TipTapEditor.destroy(textarea);
```

**Opzioni** (tutte opzionali):

```js
TipTapEditor.init(document, {
    height: 250,               // altezza area editor in px (default 250)
    imageUploadTarget: 'richtext_image',  // target Uploader per le immagini
    callbacks: {
        onInit: function () { /* chiamato quando l'editor è pronto */ }
    }
});
```

**Bridge jQuery** (retrocompatibilita callsite Summernote preesistenti):

```js
$('.summernote').summernote();                    // init
$('.summernote').summernote('code');              // get HTML
$('.summernote').summernote('code', '<p>...</p>'); // set HTML
$('.summernote').summernote('pasteHTML', html);   // inserisce HTML alla posizione cursore
$('.summernote').summernote('reset');             // svuota
$('.summernote').summernote('focus');
$('.summernote').summernote('disable');
$('.summernote').summernote('enable');
$('.summernote').summernote('destroy');
```

### DataGrid — uso corretto con filtri

```js
// Caricamento con filtri liberi (pagine con form filtro)
grid.loadData(payload, limit, page, orderBy);

// Pagine log: usa setFilters(), NON chiamare load() dopo
grid.setFilters({ user_id: 5, from: '2026-01-01' });
// setFilters chiama load() internamente — una doppia chiamata manda una seconda
// request con filtri vuoti che sovrascrive il risultato filtrato.
```

### Modal — uso tipico

```js
const modal = Modal('myModalId', {
    beforeShow: function (data) {
        // popola i campi prima dell'apertura
        this.form.find('[name=name]').val(data.name);
    },
    afterHide: function () {
        this.form[0].reset();
    }
});
modal.init();
modal.show({ name: 'Mario' });
modal.hide();
```

## Componenti di selezione input
1. `SelectionGroup.js`: base comune.
2. `RadioGroup.js`: selezione singola.
3. `CheckGroup.js`: selezione multipla.
4. `SwitchGroup.js`: toggle guidato (on/off, si/no).

## Componenti runtime e supporto
1. `Navbar.js`: comportamento navbar/offcanvas.
2. `Dashboard.js`: helper pagine dashboard/admin.
3. `PollManager.js`: polling periodico.
4. `EventBus.js`: evento pub/sub leggero.
5. `PermissionGate.js`: gating UI da permessi.
6. `ConfigStore.js`: accesso config frontend.
7. `Calendar.js`: utility calendario.
8. `DocsRender.js`: rendering contenuti docs.
9. `SlideShow.js`: slideshow immagini.
10. `DiceEngine.js` e `Dices.js`: motore dadi.
11. `CommandParser.js`: parser comandi chat.

## Runtime area admin
1. Le pagine admin non usano componenti `Admin*.js` come sorgente primaria.
2. La logica pagina vive in `assets/js/app/features/admin/*`.
3. L'orchestrazione pagina/modulo passa da:
   - `assets/js/app/core/admin.registry.js`
   - `assets/js/app/core/admin.runtime.js`
4. I componenti shared restano in `assets/js/components/*` (DataGrid, SwitchGroup, Dialog, Modal, ecc.).

## Utility pure (`assets/js/components/utils`)
1. `Utils.js`: helper base generici.
2. `Dates.js`: formattazione date/ora.
3. `Form.js`: serializzazione e helper form.
4. `Cookie.js`: helper cookie.

## Regole d'uso rapide
1. Riusa un componente shared prima di crearne uno nuovo.
2. Evita logica di dominio dentro i componenti UI.
3. Permessi UI sempre come supporto UX, mai come sicurezza.
4. Se aggiungi un componente shared, documenta almeno: scopo, API minima, esempio uso.
