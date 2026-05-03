<?php

/*
|--------------------------------------------------------------------------
| Admin új slot hozzáadása egy meglévő foglaláshoz
|--------------------------------------------------------------------------
|
| Ezt a végpontot az admin dashboard használja, amikor egy már meglévő
| foglaláshoz további időblokkot szeretnénk hozzáadni.
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

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

$bookingDate = validateDateValue(getRequiredStringField($body, 'booking_date', 10), 'booking_date');
$slot = validateSlotValue(getRequiredStringField($body, 'slot', 20));

$selectedBookingDate = DateTimeImmutable::createFromFormat('Y-m-d', $bookingDate);
$today = new DateTimeImmutable('today');

if (!$selectedBookingDate || $selectedBookingDate->format('Y-m-d') !== $bookingDate) {
    sendJson([
        'success' => false,
        'message' => 'A foglalási dátum formátuma érvénytelen.',
    ], 422);
}

if ($selectedBookingDate <= $today) {
    sendJson([
        'success' => false,
        'message' => 'Csak jövőbeli időponthoz lehet új blokkot hozzáadni.',
    ], 422);
}

$bookingStatement = $pdo->prepare('
    SELECT id, status
    FROM bookings
    WHERE id = :id
    LIMIT 1
');
$bookingStatement->execute(['id' => $bookingId]);
$booking = $bookingStatement->fetch();

if ($booking === false) {
    sendJson([
        'success' => false,
        'message' => 'A megadott foglalás nem található.',
    ], 404);
}

if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
    sendJson([
        'success' => false,
        'message' => 'Csak aktív foglaláshoz lehet új időpontot hozzáadni.',
    ], 409);
}

$existingSlotStatement = $pdo->prepare("
    SELECT
        bs.id,
        bs.booking_id,
        bs.booking_date,
        bs.slot,
        b.status,
        b.customer_name
    FROM booking_slots bs
    INNER JOIN bookings b ON b.id = bs.booking_id
    WHERE bs.booking_date = :booking_date
      AND bs.slot = :slot
      AND b.status IN ('pending', 'confirmed')
    LIMIT 1
");

$existingSlotStatement->execute([
    'booking_date' => $bookingDate,
    'slot' => $slot,
]);

$existingSlot = $existingSlotStatement->fetch();

if ($existingSlot !== false) {
    sendJson([
        'success' => false,
        'message' => 'Ez az idősáv már foglalt vagy függőben lévő kéréshez tartozik.',
        'data' => [
            'booking_date' => $existingSlot['booking_date'],
            'slot' => $existingSlot['slot'],
            'status' => $existingSlot['status'],
            'customer_name' => $existingSlot['customer_name'],
        ],
    ], 409);
}

$insertSlotStatement = $pdo->prepare('
    INSERT INTO booking_slots (
        booking_id,
        booking_date,
        slot
    ) VALUES (
        :booking_id,
        :booking_date,
        :slot
    )
');

try {
    $insertSlotStatement->execute([
        'booking_id' => $bookingId,
        'booking_date' => $bookingDate,
        'slot' => $slot,
    ]);
} catch (Throwable $throwable) {
    sendJson([
        'success' => false,
        'message' => 'Nem sikerült új időpontot hozzáadni ehhez a foglaláshoz.',
    ], 500);
}

sendJson([
    'success' => true,
    'message' => 'Az új időpont hozzáadva a foglaláshoz.',
    'data' => [
        'booking_id' => $bookingId,
        'booking_date' => $bookingDate,
        'slot' => $slot,
    ],
]);
