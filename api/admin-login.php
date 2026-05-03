<?php

/*
|--------------------------------------------------------------------------
| Admin bejelentkezés
|--------------------------------------------------------------------------
|
| Ez a végpont ellenőrzi a frontendről érkező belépési adatokat,
| és sikeres egyezés esetén létrehozza a PHP sessiont.
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

handlePreflightRequest();
ensureRequestMethod('POST');

$body = getJsonRequestBody();
$username = getRequiredStringField($body, 'username', 120);
$password = getRequiredStringField($body, 'password', 255);

$statement = $pdo->prepare('SELECT username, password_hash, display_name, is_active FROM admin_users WHERE username = :username LIMIT 1');
$statement->execute([
    'username' => $username,
]);

$adminUser = $statement->fetch();

if (!is_array($adminUser) || (int) $adminUser['is_active'] !== 1) {
    sendJson([
        'success' => false,
        'message' => 'Hibás felhasználónév vagy jelszó.',
    ], 401);
}

if (!password_verify($password, $adminUser['password_hash'])) {
    sendJson([
        'success' => false,
        'message' => 'Hibás felhasználónév vagy jelszó.',
    ], 401);
}

$displayName = is_string($adminUser['display_name']) && trim($adminUser['display_name']) !== ''
    ? trim($adminUser['display_name'])
    : getAdminDisplayName();

$sessionData = markAdminAuthenticated($adminUser['username'], $displayName);

sendJson([
    'success' => true,
    'message' => 'Sikeres bejelentkezés.',
    'data' => $sessionData,
]);

