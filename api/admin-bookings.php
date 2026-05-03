<?php

/*
|--------------------------------------------------------------------------
| Védett admin foglaláslista
|--------------------------------------------------------------------------
|
| Ez a végpont csak bejelentkezett admin sessionnel érhető el.
| A frontend admin dashboard innen tölti be az oldalszámozott foglalásokat.
|
| Fontos részlet:
| egy foglaláshoz több slot is tartozhat, ezért a lapozást booking szinten
| kell elvégezni, nem a JOIN által visszaadott sorok szintjén.
|
*/

require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/database.php';

handlePreflightRequest();
ensureRequestMethod('GET');
ensureAdminAuthenticated();

$status = validateBookingStatus(getQueryStringValue('status'));
$dateFrom = validateDateValue(getQueryStringValue('date_from'), 'date_from');
$dateTo = validateDateValue(getQueryStringValue('date_to'), 'date_to');
$page = getPositiveIntValue('page', 1, 10000);
$limit = getPositiveIntValue('limit', 10, 100);
$offset = ($page - 1) * $limit;

$filters = [];
$params = [];

if ($status !== null) {
    $filters[] = 'b.status = :status';
    $params['status'] = $status;
}

if ($dateFrom !== null) {
    $filters[] = 'bs.booking_date >= :date_from';
    $params['date_from'] = $dateFrom;
}

if ($dateTo !== null) {
    $filters[] = 'bs.booking_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$whereSql = $filters !== [] ? 'WHERE ' . implode(' AND ', $filters) : '';

$summaryStatement = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM bookings
");
$summaryRow = $summaryStatement->fetch() ?: [];

$countSql = "
    SELECT COUNT(DISTINCT b.id)
    FROM bookings b
    LEFT JOIN booking_slots bs ON bs.booking_id = b.id
    $whereSql
";

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();

$idSql = "
    SELECT
        b.id,
        CASE b.status
            WHEN 'pending' THEN 1
            WHEN 'confirmed' THEN 2
            ELSE 3
        END AS status_order,
        MIN(bs.booking_date) AS first_booking_date,
        b.created_at
    FROM bookings b
    LEFT JOIN booking_slots bs ON bs.booking_id = b.id
    $whereSql
    GROUP BY b.id, b.status, b.created_at
    ORDER BY status_order ASC, first_booking_date ASC, b.created_at DESC
    LIMIT :limit OFFSET :offset
";

$idStatement = $pdo->prepare($idSql);

foreach ($params as $key => $value) {
    $idStatement->bindValue(':' . $key, $value);
}

$idStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
$idStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$idStatement->execute();

$idRows = $idStatement->fetchAll();
$bookingIds = array_map(static fn (array $row): int => (int) $row['id'], $idRows);
$bookings = [];

if ($bookingIds !== []) {
    $idPlaceholders = [];
    $detailParams = [];

    foreach ($bookingIds as $index => $bookingId) {
        $placeholder = ':booking_id_' . $index;
        $idPlaceholders[] = $placeholder;
        $detailParams['booking_id_' . $index] = $bookingId;
    }

    $detailsSql = "
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
            bs.id AS slot_id,
            bs.booking_date,
            bs.slot
        FROM bookings b
        LEFT JOIN booking_slots bs ON bs.booking_id = b.id
        WHERE b.id IN (" . implode(', ', $idPlaceholders) . ")
        ORDER BY b.id ASC, bs.booking_date ASC, bs.slot ASC
    ";

    $detailsStatement = $pdo->prepare($detailsSql);

    foreach ($detailParams as $key => $value) {
        $detailsStatement->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }

    $detailsStatement->execute();
    $detailRows = $detailsStatement->fetchAll();

    foreach ($detailRows as $row) {
        $bookingId = (int) $row['id'];

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

        if ($row['slot_id'] !== null && $row['booking_date'] !== null && $row['slot'] !== null) {
            $bookings[$bookingId]['slots'][] = [
                'id' => (int) $row['slot_id'],
                'booking_date' => $row['booking_date'],
                'slot' => $row['slot'],
            ];
        }
    }

    $orderedBookings = [];

    foreach ($bookingIds as $bookingId) {
        if (isset($bookings[$bookingId])) {
            $orderedBookings[] = $bookings[$bookingId];
        }
    }

    $bookings = $orderedBookings;
}

sendJson([
    'success' => true,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
    ],
    'summary' => [
        'total' => (int) ($summaryRow['total'] ?? 0),
        'pending' => (int) ($summaryRow['pending'] ?? 0),
        'confirmed' => (int) ($summaryRow['confirmed'] ?? 0),
        'cancelled' => (int) ($summaryRow['cancelled'] ?? 0),
    ],
    'filters' => [
        'status' => $status,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ],
    'data' => $bookingIds === [] ? [] : $bookings,
]);
