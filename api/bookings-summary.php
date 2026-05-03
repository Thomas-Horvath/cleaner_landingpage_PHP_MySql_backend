<?php

/*
|--------------------------------------------------------------------------
| Foglalás összesítés
|--------------------------------------------------------------------------
|
| Ez a végpont tipikusan admin dashboardhoz hasznos.
|
| Nem a teljes listát adja vissza, hanem rövid összesítést:
| - összes foglalás
| - pending darabszám
| - confirmed darabszám
| - cancelled darabszám
| - van-e függőben lévő foglalás
| - néhány friss pending booking preview
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

handlePreflightRequest();
ensureRequestMethod('GET');

/*
|--------------------------------------------------------------------------
| Összesítő SQL lekérdezés
|--------------------------------------------------------------------------
|
| COUNT(*):
|   összes rekord száma
|
| SUM(CASE WHEN ... THEN 1 ELSE 0 END):
|   feltételes darabszámolás
|
| Ez egy gyakori SQL minta, amikor státusz szerinti összesítést akarunk.
|
*/
$summaryStatement = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM bookings
");

$summary = $summaryStatement->fetch();

/*
|--------------------------------------------------------------------------
| Rövid pending preview lista
|--------------------------------------------------------------------------
|
| Sok admin felületen jó, ha nem csak a darabszám látszik,
| hanem mondjuk az utolsó 5 függőben lévő foglalás is.
|
*/
$pendingPreviewStatement = $pdo->query("
    SELECT
        id,
        customer_name,
        service_type,
        created_at
    FROM bookings
    WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");

$pendingPreview = $pendingPreviewStatement->fetchAll();

// Az array_map segítségével minden rekordot egységes JSON formára alakítunk.
sendJson([
    'success' => true,
    'data' => [
        'total' => (int) ($summary['total'] ?? 0),
        'pending' => (int) ($summary['pending'] ?? 0),
        'confirmed' => (int) ($summary['confirmed'] ?? 0),
        'cancelled' => (int) ($summary['cancelled'] ?? 0),

        // Ez frontend oldalon gyors UI döntésekhez hasznos.
        // Például:
        // "van-e piros értesítési badge a menüben?"
        'has_pending' => (int) ($summary['pending'] ?? 0) > 0,
        'pending_preview' => array_map(static function (array $booking): array {
            return [
                'id' => (int) $booking['id'],
                'customer_name' => $booking['customer_name'],
                'service_type' => $booking['service_type'],
                'created_at' => $booking['created_at'],
            ];
        }, $pendingPreview),
    ],
]);
