<?php

/*
|--------------------------------------------------------------------------
| Health endpoint
|--------------------------------------------------------------------------
|
| Ez a legegyszerűbb backend végpont.
|
| A célja:
| - ellenőrizni, hogy a PHP egyáltalán fut-e
| - ellenőrizni, hogy az adatbázis kapcsolat létre tud-e jönni
|
| Tipikus használat:
| - fejlesztés elején gyors teszt
| - Docker indulás után ellenőrzés
| - később monitoring / health check
|
*/

// Betöltjük a közös API segédfüggvényeket.
require_once __DIR__ . '/../config/api.php';

// Ha a böngésző preflight OPTIONS kérést küld, azt itt rögtön kezeljük.
handlePreflightRequest();

// Ez a végpont csak GET kérést fogad.
ensureRequestMethod('GET');

try {
    // Ha ez a fájl sikeresen betöltődik, akkor létrejön a $pdo kapcsolat.
    require_once __DIR__ . '/../config/database.php';

    // Sikeres válasz JSON formában.
    sendJson([
        'success' => true,
        'message' => 'PHP backend is running',
        'database' => 'connected',
    ]);
} catch (Throwable $throwable) {
    // Ha bármi hiba történik (pl. rossz DB kapcsolat), 500-as hibát küldünk.
    //
    // Throwable:
    //   egy tág típus, ami exceptiont és más hibákat is el tud kapni
    sendJson([
        'success' => false,
        'message' => 'Database connection failed',
    ], 500);
}
