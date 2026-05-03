<?php

/*
|--------------------------------------------------------------------------
| Részletes debug adatbázis végpont
|--------------------------------------------------------------------------
|
| Ez a verzió querynként külön próbálkozik, hogy pontosan lássuk,
| melyik SQL fut le és melyik ad hibát a Nethelyen.
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

handlePreflightRequest();
ensureRequestMethod('GET');

$results = [];

$queries = [
    'database_only' => 'SELECT DATABASE() AS database_name',
    'current_user_only' => 'SELECT CURRENT_USER() AS current_user',
    'user_only' => 'SELECT USER() AS login_user',
    'version_only' => 'SELECT VERSION() AS mysql_version',
    'bookings_count' => 'SELECT COUNT(*) AS total FROM `bookings`',
    'booking_slots_count' => 'SELECT COUNT(*) AS total FROM `booking_slots`',
    'bookings_summary_like' => "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN `status` = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN `status` = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
        FROM `bookings`
    ",
];

foreach ($queries as $label => $sql) {
    try {
        $statement = $pdo->query($sql);
        $results[$label] = [
            'success' => true,
            'data' => $statement->fetch(),
        ];
    } catch (Throwable $throwable) {
        $results[$label] = [
            'success' => false,
            'message' => $throwable->getMessage(),
        ];
    }
}

sendJson([
    'success' => true,
    'results' => $results,
]);
