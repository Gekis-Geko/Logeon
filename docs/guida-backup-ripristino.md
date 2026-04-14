# Guida Backup e Ripristino

Ultimo aggiornamento: 2026-04-03

## Scopo
Definire backup minimi obbligatori e procedura di ripristino.

## Cosa salvare
1. Database applicativo (dump SQL).
2. Cartella progetto (codice + view + asset).
3. File configurazione:
   - `configs/config.php`
   - `configs/db.php`
   - `configs/app.php`
4. Eventuali cartelle upload/media.

## Frequenza consigliata
1. DB: giornaliero.
2. Config: ad ogni modifica.
3. Codice: ad ogni release.

## Procedura backup (schema)
1. Esegui dump DB.
2. Archivia file progetto e config.
3. Verifica integrita archivio.
4. Salva in storage separato.

## Procedura ripristino (schema)
1. Ripristina codice versione target.
2. Ripristina config.
3. Importa dump DB.
4. Verifica permessi filesystem.
5. Esegui smoke runtime.

## Verifiche dopo ripristino
1. Login e sessione.
2. Navigazione `/game`.
3. Navigazione `/admin`.
4. Controllo error log.
