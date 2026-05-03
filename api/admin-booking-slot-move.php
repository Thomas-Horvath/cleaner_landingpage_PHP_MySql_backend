<?php

/*
|--------------------------------------------------------------------------
| Admin meglévő slot áthelyezése
|--------------------------------------------------------------------------
|
| Ezt a végpontot az admin dashboard hívja meg akkor, amikor egy konkrét
| foglalási blokkot új dátumra vagy új napszakra szeretnénk áttenni.
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

handlePreflightRequest();
ensureRequestMethod('POST');
ensureAdminAuthenticated();

$body = getJsonRequestBody();
$bookingId = $body['booking_id'] ?? null;
$slotId = $body['slot_id'] ?? null;

if (!is_int($bookingId) && !is_numeric($bookingId)) {
    sendJson([
        'success' => false,
        'message' => 'A booking_id mező kötelező, és számnak kell lennie.',
    ], 422);
}

if (!is_int($slotId) && !is_numeric($slotId)) {
    sendJson([
        'success' => false,
        'message' => 'A slot_id mező kötelező, és számnak kell lennie.',
    ], 422);
}

$bookingId = (int) $bookingId;
$slotId = (int) $slotId;

if ($bookingId < 1 || $slotId < 1) {
    sendJson([
        'success' => false,
        'message' => 'A booking_id és slot_id mezőknek pozitív egész számnak kell lenniük.',
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
        'message' => 'Csak jövőbeli időpontra lehet áthelyezni a foglalást.',
    ], 422);
}

$slotStatement = $pdo->prepare('
    SELECT
        bs.id,
        bs.booking_id,
        bs.booking_date,
        bs.slot,
        b.status
    FROM booking_slots bs
    INNER JOIN bookings b ON b.id = bs.booking_id
    WHERE bs.id = :slot_id
      AND bs.booking_id = :booking_id
    LIMIT 1
');
$slotStatement->execute([
    'slot_id' => $slotId,
    'booking_id' => $bookingId,
]);
$existingSlot = $slotStatement->fetch();

if ($existingSlot === false) {
    sendJson([
        'success' => false,
        'message' => 'A kiválasztott foglalási blokk nem található.',
    ], 404);
}

if (!in_array($existingSlot['status'], ['pending', 'confirmed'], true)) {
    sendJson([
        'success' => false,
        'message' => 'Csak aktív foglalás időpontja helyezhető át.',
    ], 409);
}

$conflictStatement = $pdo->prepare("
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
      AND bs.id <> :slot_id
      AND b.status IN ('pending', 'confirmed')
    LIMIT 1
");
$conflictStatement->execute([
    'booking_date' => $bookingDate,
    'slot' => $slot,
    'slot_id' => $slotId,
]);
$conflictingSlot = $conflictStatement->fetch();

if ($conflictingSlot !== false) {
    sendJson([
        'success' => false,
        'message' => 'A célként választott idősáv már foglalt vagy függőben lévő kéréshez tartozik.',
        'data' => [
            'booking_date' => $conflictingSlot['booking_date'],
            'slot' => $conflictingSlot['slot'],
            'status' => $conflictingSlot['status'],
            'customer_name' => $conflictingSlot['customer_name'],
        ],
    ], 409);
}

$updateStatement = $pdo->prepare('
    UPDATE booking_slots
    SET booking_date = :booking_date,
        slot = :slot
    WHERE id = :slot_id
      AND booking_id = :booking_id
    LIMIT 1
');

try {
    $updateStatement->execute([
        'booking_date' => $bookingDate,
        'slot' => $slot,
        'slot_id' => $slotId,
        'booking_id' => $bookingId,
    ]);
} catch (Throwable $throwable) {
    sendJson([
        'success' => false,
        'message' => 'Nem sikerült áthelyezni a kiválasztott időpontot.',
    ], 500);
}

sendJson([
    'success' => true,
    'message' => 'A foglalási blokk új időpontra került.',
    'data' => [
        'booking_id' => $bookingId,
        'slot_id' => $slotId,
        'booking_date' => $bookingDate,
        'slot' => $slot,
    ],
]);
