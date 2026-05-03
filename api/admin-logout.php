<?php

/*
|--------------------------------------------------------------------------
| Admin kijelentkezés
|--------------------------------------------------------------------------
|
| Ez a végpont törli az aktív admin sessiont.
|
*/

require_once __DIR__ . '/../config/api.php';

handlePreflightRequest();
ensureRequestMethod('POST');

destroyAdminSession();

sendJson([
    'success' => true,
    'message' => 'Sikeres kijelentkezés.',
]);
