<?php

/*
|--------------------------------------------------------------------------
| Foglalás létrehozása
|--------------------------------------------------------------------------
|
| Ez a végpont egy új foglalást ment el az adatbázisba.
| Az első állapot mindig pending, mert az admin vagy a szolgáltató
| később még visszaigazolhatja / megerősítheti.
|
| A frontend JSON body-val küldi az adatokat.
|
| Ebben a verzióban plusz védelmek is bekerültek:
| - aznapi és múltbeli dátum tiltása
| - alap spamvédelem honeypot mezővel
| - alap spamvédelem időalapú ellenőrzéssel
| - fejlesztői / éles levelezési réteg meghívása
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

handlePreflightRequest();
ensureRequestMethod('POST');

$payload = getJsonRequestBody();

$website = getOptionalStringField($payload, 'website', 255);

if ($website !== null) {
    sendJson([
        'success' => true,
        'message' => 'Booking received',
    ], 202);
}

$formStartedAtRaw = $payload['form_started_at'] ?? null;

if (!is_int($formStartedAtRaw) && !is_numeric($formStartedAtRaw)) {
    sendJson([
        'success' => false,
        'message' => 'A form_started_at mező kötelező, és számnak kell lennie.',
    ], 422);
}

$formStartedAt = (int) $formStartedAtRaw;
$currentTimestampMs = (int) round(microtime(true) * 1000);
$elapsedMs = $currentTimestampMs - $formStartedAt;

if ($formStartedAt < 1 || $elapsedMs < 3000) {
    sendJson([
        'success' => true,
        'message' => 'Booking received',
    ], 202);
}

$customerName = getRequiredStringField($payload, 'customer_name', 120);
$email = validateEmailValue(getRequiredStringField($payload, 'email', 160));
$phone = getRequiredStringField($payload, 'phone', 60);
$address = getRequiredStringField($payload, 'address', 255);
$serviceType = getOptionalStringField($payload, 'service_type', 120);
$message = getOptionalStringField($payload, 'message', 3000);
$bookingDate = validateDateValue(getRequiredStringField($payload, 'booking_date', 10), 'booking_date');
$slot = validateSlotValue(getRequiredStringField($payload, 'slot', 20));

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
        'message' => 'Csak jövőbeli időpontra lehet foglalást küldeni.',
    ], 422);
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
        'message' => 'The selected slot is no longer available',
        'data' => [
            'booking_date' => $existingSlot['booking_date'],
            'slot' => $existingSlot['slot'],
            'status' => $existingSlot['status'],
            'customer_name' => $existingSlot['customer_name'],
        ],
    ], 409);
}

try {
    $pdo->beginTransaction();

    $insertBookingStatement = $pdo->prepare("
        INSERT INTO bookings (
            customer_name,
            email,
            phone,
            address,
            service_type,
            message,
            status
        ) VALUES (
            :customer_name,
            :email,
            :phone,
            :address,
            :service_type,
            :message,
            'pending'
        )
    ");

    $insertBookingStatement->execute([
        'customer_name' => $customerName,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'service_type' => $serviceType,
        'message' => $message,
    ]);

    $newBookingId = (int) $pdo->lastInsertId();

    $insertSlotStatement = $pdo->prepare("
        INSERT INTO booking_slots (
            booking_id,
            booking_date,
            slot
        ) VALUES (
            :booking_id,
            :booking_date,
            :slot
        )
    ");

    $insertSlotStatement->execute([
        'booking_id' => $newBookingId,
        'booking_date' => $bookingDate,
        'slot' => $slot,
    ]);

    $pdo->commit();
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sendJson([
        'success' => false,
        'message' => 'Failed to create booking',
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Mentett foglalási adatcsomag
|--------------------------------------------------------------------------
|
| A levelező rétegnek és a frontend válasznak is készítünk egy közös tömböt.
| Így ugyanazt az adatot tudjuk visszaküldeni, illetve az e-mail sablonokban
| is újra felhasználni.
|
*/
$createdBooking = [
    'id' => $newBookingId,
    'customer_name' => $customerName,
    'email' => $email,
    'phone' => $phone,
    'address' => $address,
    'service_type' => $serviceType,
    'message' => $message,
    'status' => 'pending',
    'booking_date' => $bookingDate,
    'slot' => $slot,
];

/*
|--------------------------------------------------------------------------
| Levelek kiküldése / naplózása
|--------------------------------------------------------------------------
|
| Fontos döntés:
| a foglalás mentése már sikeresen megtörtént, ezért innen kezdve nem
| szeretnénk visszagörgetni az adatbázist csak azért, mert az e-mail küldés
| esetleg hibát dob.
|
| Emiatt a notifyBookingCreated() eredményét csak eltároljuk és visszaadjuk,
| de a foglalás ettől még létrejöttnek számít.
|
*/
$mailNotifications = notifyBookingCreated($createdBooking);

sendJson([
    'success' => true,
    'message' => 'Booking created in pending status',
    'data' => $createdBooking,
    'meta' => [
        'mail_driver' => getMailConfig()['driver'],
        'notifications' => $mailNotifications,
    ],
], 201);
