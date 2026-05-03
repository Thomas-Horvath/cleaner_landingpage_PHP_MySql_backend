<?php

/*
|--------------------------------------------------------------------------
| Foglalt idősávok lekérdezése
|--------------------------------------------------------------------------
|
| Ez a végpont elsősorban a foglalási UI-nak lesz fontos.
|
| Mire használható?
| - egy naptárban megjeleníteni, melyik nap/idősáv foglalt
| - új foglalás előtt ütközésellenőrzéshez
| - admin oldalon áttekinteni az időbeosztást
|
| Például:
| /api/booking-slots.php?date_from=2026-05-01&date_to=2026-05-31
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

handlePreflightRequest();
ensureRequestMethod('GET');

// Ennél a végpontnál a dátumtartomány nagyon fontos, ezért ezt külön ellenőrizzük.
$dateFrom = validateDateValue(getQueryStringValue('date_from'), 'date_from');
$dateTo = validateDateValue(getQueryStringValue('date_to'), 'date_to');
$status = validateBookingStatus(getQueryStringValue('status'));

// Ennél a végpontnál a két dátum kötelező.
if ($dateFrom === null || $dateTo === null) {
    sendJson([
        'success' => false,
        'message' => 'date_from and date_to are required',
    ], 422);
}

// Ha nincs külön státusz megadva, alapból csak a confirmed foglalásokat nézzük.
// Ez gyakran a legjobb alapértelmezés, mert a ténylegesen lefoglalt helyeket
// általában a megerősített foglalások blokkolják.
$statusFilter = $status ?? 'confirmed';

/*
|--------------------------------------------------------------------------
| Foglalt slotok lekérdezése
|--------------------------------------------------------------------------
|
| INNER JOIN bookings:
| itt csak olyan slotok kellenek, amelyekhez tényleg tartozik foglalás
|
| WHERE feltételek:
| - csak a kért dátumtartomány
| - csak a kívánt státusz
|
*/
$slotsStatement = $pdo->prepare("
    SELECT
        bs.id,
        bs.booking_id,
        bs.booking_date,
        bs.slot,
        b.status,
        b.customer_name,
        b.service_type
    FROM booking_slots bs
    INNER JOIN bookings b ON b.id = bs.booking_id
    WHERE bs.booking_date BETWEEN :date_from AND :date_to
      AND b.status = :status
    ORDER BY bs.booking_date ASC, bs.slot ASC
");

// A placeholder értékek behelyettesítése.
$slotsStatement->execute([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'status' => $statusFilter,
]);

// Minden találatot lekérünk.
$rows = $slotsStatement->fetchAll();

// A válaszban visszaadjuk a használt szűrőket is.
// Ez frontend debughoz és admin felületen is kényelmes.
sendJson([
    'success' => true,
    'filters' => [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $statusFilter,
    ],

    // Minden SQL sort átalakítunk egy tiszta JSON objektummá.
    'data' => array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'booking_id' => (int) $row['booking_id'],
            'booking_date' => $row['booking_date'],
            'slot' => $row['slot'],
            'status' => $row['status'],
            'customer_name' => $row['customer_name'],
            'service_type' => $row['service_type'],
        ];
    }, $rows),
]);
