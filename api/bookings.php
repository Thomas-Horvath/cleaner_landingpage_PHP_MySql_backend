<?php

/*
|--------------------------------------------------------------------------
| Foglalások listázása
|--------------------------------------------------------------------------
|
| Ez a végpont a foglalások listáját adja vissza a frontendnek.
|
| Mire jó?
| - admin lista oldal
| - pending foglalások nézete
| - szűrés státusz, szolgáltatás vagy dátum alapján
| - lapozás
|
| Minta hívások:
| /api/bookings.php
| /api/bookings.php?status=pending
| /api/bookings.php?date_from=2026-05-01&date_to=2026-05-31
| /api/bookings.php?page=2&limit=10
|
*/

// Közös API helper függvények.
require_once __DIR__ . '/../config/api.php';

// Adatbázis kapcsolat ($pdo).
require_once __DIR__ . '/../config/database.php';

// OPTIONS kérés kezelése.
handlePreflightRequest();

// Ez a végpont csak GET kérésre válaszol.
ensureRequestMethod('GET');

/*
|--------------------------------------------------------------------------
| Query paraméterek beolvasása és validálása
|--------------------------------------------------------------------------
|
| Ezek a frontendből érkezhetnek a URL query string részeként.
| Mindegyik opcionális.
|
*/
$status = validateBookingStatus(getQueryStringValue('status'));
$serviceType = getQueryStringValue('service_type');
$dateFrom = validateDateValue(getQueryStringValue('date_from'), 'date_from');
$dateTo = validateDateValue(getQueryStringValue('date_to'), 'date_to');
$page = getPositiveIntValue('page', 1, 10000);
$limit = getPositiveIntValue('limit', 10, 100);

// Az SQL OFFSET azt mondja meg, hány rekordot ugorjunk át.
// Példa:
// page = 1, limit = 10  => offset = 0
// page = 2, limit = 10  => offset = 10
$offset = ($page - 1) * $limit;

// Ide gyűjtjük a WHERE feltételek SQL darabjait.
$filters = [];

// Ide gyűjtjük a prepared statement paramétereit.
$params = [];

if ($status !== null) {
    // Ne közvetlenül fűzzük be az értéket az SQL-be.
    // Helyette placeholdert használunk (:status), és a valódi értéket külön adjuk át.
    $filters[] = 'b.status = :status';
    $params['status'] = $status;
}

if ($serviceType !== null) {
    $filters[] = 'b.service_type = :service_type';
    $params['service_type'] = $serviceType;
}

if ($dateFrom !== null) {
    $filters[] = 'bs.booking_date >= :date_from';
    $params['date_from'] = $dateFrom;
}

if ($dateTo !== null) {
    $filters[] = 'bs.booking_date <= :date_to';
    $params['date_to'] = $dateTo;
}

// Ha van bármilyen szűrő, összerakjuk a WHERE részt.
// Ha nincs, üres string marad, tehát minden rekord jöhet.
$whereSql = $filters !== [] ? 'WHERE ' . implode(' AND ', $filters) : '';

/*
|--------------------------------------------------------------------------
| Darabszám lekérdezése lapozáshoz
|--------------------------------------------------------------------------
|
| Két lekérdezést használunk:
| 1. COUNT: hány találat van összesen?
| 2. SELECT: az aktuális oldal konkrét adatai
|
| Miért kell külön COUNT?
| Mert a frontendnek sokszor tudnia kell:
| - összes találat
| - hány oldal van
| - jelenlegi oldal
|
*/
$countSql = "
    SELECT COUNT(DISTINCT b.id)
    FROM bookings b
    LEFT JOIN booking_slots bs ON bs.booking_id = b.id
    $whereSql
";

// prepare():
// előkészít egy SQL lekérdezést, amibe biztonságosan tudunk paramétert adni
$countStatement = $pdo->prepare($countSql);

// execute($params):
// itt küldjük be a placeholder értékeket
$countStatement->execute($params);

// fetchColumn():
// az első sor első oszlopát adja vissza
$total = (int) $countStatement->fetchColumn();

/*
|--------------------------------------------------------------------------
| Fő adatok lekérdezése
|--------------------------------------------------------------------------
|
| Itt már a tényleges booking adatokat kérjük le.
|
| LEFT JOIN booking_slots:
| - egy foglaláshoz tartozhat slot
| - de ha éppen nincs, a booking attól még megjelenjen
|
| ORDER BY logika:
| - pending előre
| - utána confirmed
| - végül cancelled
| - ezen belül dátum szerint
|
*/
$dataSql = "
    SELECT
        b.id,
        b.customer_name,
        b.email,
        b.phone,
        b.address,
        b.service_type,
        b.message,
        b.status,
        b.created_at,
        b.updated_at,
        bs.booking_date,
        bs.slot
    FROM bookings b
    LEFT JOIN booking_slots bs ON bs.booking_id = b.id
    $whereSql
    ORDER BY
        CASE b.status
            WHEN 'pending' THEN 1
            WHEN 'confirmed' THEN 2
            ELSE 3
        END,
        bs.booking_date ASC,
        b.created_at DESC
    LIMIT :limit OFFSET :offset
";

$dataStatement = $pdo->prepare($dataSql);

// Az általános szűrő paramétereket egyesével kötjük a statementhez.
foreach ($params as $key => $value) {
    $dataStatement->bindValue(':' . $key, $value);
}

// LIMIT és OFFSET egész szám kell legyen, ezért explicit int-ként kötjük.
$dataStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
$dataStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStatement->execute();

// Az összes találati sort lekérjük.
$rows = $dataStatement->fetchAll();

// Itt fogjuk átalakítani a lapos SQL sorokat egy frontendbarát struktúrává.
$bookings = [];

/*
|--------------------------------------------------------------------------
| SQL sorok átalakítása összetettebb JSON struktúrává
|--------------------------------------------------------------------------
|
| Az SQL JOIN miatt ugyanaz a booking több sorban is megjelenhet,
| ha több slotja van.
|
| Példa:
| booking #12 + 2 külön slot => 2 SQL sor
|
| A frontendnek viszont inkább ez kell:
| {
|   id: 12,
|   ...,
|   slots: [
|     {...},
|     {...}
|   ]
| }
|
| Ezért a ciklusban összegyűjtjük slotonként ugyanahhoz a bookinghoz az adatokat.
|
*/
foreach ($rows as $row) {
    $bookingId = (int) $row['id'];

    // Ha ezt a bookingot még nem raktuk be a tömbbe, most hozzuk létre.
    if (!isset($bookings[$bookingId])) {
        $bookings[$bookingId] = [
            'id' => $bookingId,
            'customer_name' => $row['customer_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'service_type' => $row['service_type'],
            'message' => $row['message'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'slots' => [],
        ];
    }

    // Ha van tényleges slot adat, hozzáfűzzük a slots listához.
    if ($row['booking_date'] !== null && $row['slot'] !== null) {
        $bookings[$bookingId]['slots'][] = [
            'booking_date' => $row['booking_date'],
            'slot' => $row['slot'],
        ];
    }
}

// A bookingokat JSON-ként küldjük vissza.
// array_values():
// azért kell, mert a tömb kulcsai booking ID-k lettek, de a frontendnek
// általában sima indexelt lista kell.
sendJson([
    'success' => true,
    'filters' => [
        'status' => $status,
        'service_type' => $serviceType,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $page,
        'limit' => $limit,
    ],
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
    ],
    'data' => array_values($bookings),
]);
