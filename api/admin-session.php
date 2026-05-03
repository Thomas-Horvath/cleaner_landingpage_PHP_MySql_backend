<?php

/*
|--------------------------------------------------------------------------
| Admin session állapot lekérdezése
|--------------------------------------------------------------------------
|
| A frontend ezzel tudja megkérdezni, hogy van-e érvényes admin session.
| Ez különösen hasznos oldalfrissítés után vagy dashboard betöltésekor.
|
*/

require_once __DIR__ . '/../config/api.php';

handlePreflightRequest();
ensureRequestMethod('GET');

$sessionData = getAdminSessionData();

sendJson([
    'success' => true,
    'authenticated' => $sessionData !== null,
    'data' => $sessionData,
]);
