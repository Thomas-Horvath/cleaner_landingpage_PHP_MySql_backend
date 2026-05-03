<?php

/*
|--------------------------------------------------------------------------
| Admin foglalás státusz frissítése
|--------------------------------------------------------------------------
|
| Az admin innen tud egy függőben lévő ajánlatkérést elfogadni vagy törölni.
| A törlésnél a kapcsolódó slotokat is felszabadítjuk, hogy a későbbi
| foglalásokhoz újra használhatók legyenek.
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

handlePreflightRequest();
ensureRequestMethod('POST');
ensureAdminAuthenticated();

$body = getJsonRequestBody();
$bookingId = $body['booking_id'] ?? null;

if (!is_int($bookingId) && !is_numeric($bookingId)) {
    sendJson([
        'success' => false,
        'message' => 'A booking_id mező kötelező, és számnak kell lennie.',
    ], 422);
}

$bookingId = (int) $bookingId;

if ($bookingId < 1) {
    sendJson([
        'success' => false,
        'message' => 'A booking_id mezőnek pozitív egész számnak kell lennie.',
    ], 422);
}

$nextStatus = $body['status'] ?? null;

if (!is_string($nextStatus)) {
    sendJson([
        'success' => false,
        'message' => 'A status mező kötelező.',
    ], 422);
}

$nextStatus = trim($nextStatus);
$allowedNextStatuses = ['confirmed', 'cancelled'];

if (!in_array($nextStatus, $allowedNextStatuses, true)) {
    sendJson([
        'success' => false,
        'message' => 'Az új státusz csak confirmed vagy cancelled lehet.',
    ], 422);
}

$bookingStatement = $pdo->prepare('
    SELECT
        b.id,
        b.customer_name,
        b.email,
        b.phone,
        b.address,
        b.service_type,
        b.message,
        b.status,
        b.updated_at,
        bs.booking_date,
        bs.slot
    FROM bookings b
    LEFT JOIN booking_slots bs ON bs.booking_id = b.id
    WHERE b.id = :id
    ORDER BY bs.booking_date ASC, bs.slot ASC
    LIMIT 1
');
$bookingStatement->execute(['id' => $bookingId]);
$existingBooking = $bookingStatement->fetch();

if ($existingBooking === false) {
    sendJson([
        'success' => false,
        'message' => 'A megadott foglalás nem található.',
    ], 404);
}

$currentStatus = $existingBooking['status'] ?? null;

if ($currentStatus !== 'pending') {
    sendJson([
        'success' => false,
        'message' => 'Csak függőben lévő ajánlatkérés frissíthető ezen a felületen.',
    ], 409);
}

try {
    $pdo->beginTransaction();

    $updateStatement = $pdo->prepare('
        UPDATE bookings
        SET status = :status
        WHERE id = :id
        LIMIT 1
    ');

    $updateStatement->execute([
        'status' => $nextStatus,
        'id' => $bookingId,
    ]);

    if ($nextStatus === 'cancelled') {
        $deleteSlotsStatement = $pdo->prepare('
            DELETE FROM booking_slots
            WHERE booking_id = :booking_id
        ');

        $deleteSlotsStatement->execute([
            'booking_id' => $bookingId,
        ]);
    }

    $pdo->commit();
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sendJson([
        'success' => false,
        'message' => 'Nem sikerült frissíteni a foglalás státuszát.',
    ], 500);
}

$updatedBookingStatement = $pdo->prepare('
    SELECT id, status, updated_at
    FROM bookings
    WHERE id = :id
    LIMIT 1
');
$updatedBookingStatement->execute(['id' => $bookingId]);
$updatedBooking = $updatedBookingStatement->fetch();

$mailNotifications = notifyBookingStatusChanged($existingBooking ?: [], $nextStatus);

sendJson([
    'success' => true,
    'message' => $nextStatus === 'confirmed'
        ? 'Az ajánlatkérés megerősítve.'
        : 'Az ajánlatkérés törölt státuszba került.',
    'data' => [
        'id' => (int) $updatedBooking['id'],
        'status' => $updatedBooking['status'],
        'updated_at' => $updatedBooking['updated_at'],
    ],
    'meta' => [
        'mail_driver' => getMailConfig()['driver'],
        'notifications' => $mailNotifications,
    ],
]);
