<?php

/*
|--------------------------------------------------------------------------
| Közös API segédfüggvények
|--------------------------------------------------------------------------
|
| Ez a fájl nem egy "végpont", hanem egy közös eszköztár.
| Az API végpontok (pl. health.php, bookings.php, admin-login.php) ezt betöltik,
| és innen kapják meg a közös működéshez szükséges függvényeket.
|
| Fő feladatok:
| - JSON válasz küldése
| - CORS kezelés
| - OPTIONS / preflight kezelése
| - HTTP metódus ellenőrzése
| - query paraméterek és JSON body olvasása
| - admin session indítása és ellenőrzése
|
*/

require_once __DIR__ . '/env.php';

loadBackendEnv();

function resolveAllowedOrigin(): ?string
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

    if (!is_string($origin) || trim($origin) === '') {
        return null;
    }

    $trimmedOrigin = trim($origin);

    $allowedOrigins = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ];

    $configuredOrigins = getenv('FRONTEND_ORIGINS') ?: '';

    if ($configuredOrigins !== '') {
        foreach (explode(',', $configuredOrigins) as $configuredOrigin) {
            $candidate = trim($configuredOrigin);

            if ($candidate !== '') {
                $allowedOrigins[] = $candidate;
            }
        }
    }

    if (in_array($trimmedOrigin, $allowedOrigins, true)) {
        return $trimmedOrigin;
    }

    $parsedOrigin = parse_url($trimmedOrigin);
    $originHost = $parsedOrigin['host'] ?? '';

    // Vercel preview domainekhez engedjük a .vercel.app végződést.
    if (is_string($originHost) && str_ends_with($originHost, '.vercel.app')) {
        return $trimmedOrigin;
    }

    return null;
}

function applyCorsHeaders(): void
{
    $allowedOrigin = resolveAllowedOrigin();

    if ($allowedOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    applyCorsHeaders();

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handlePreflightRequest(): void
{
    applyCorsHeaders();

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function ensureRequestMethod(string $expectedMethod): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) {
        sendJson([
            'success' => false,
            'message' => 'Method not allowed',
        ], 405);
    }
}

function getQueryStringValue(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? $default;

    if (!is_string($value)) {
        return $default;
    }

    $trimmedValue = trim($value);

    return $trimmedValue === '' ? $default : $trimmedValue;
}

function getPositiveIntValue(string $key, int $default, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);

    if ($value === false || $value === null || $value < 1) {
        return $default;
    }

    return min($value, $max);
}

function validateDateValue(?string $dateValue, string $fieldName): ?string
{
    if ($dateValue === null) {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $dateValue);

    if (!$date || $date->format('Y-m-d') !== $dateValue) {
        sendJson([
            'success' => false,
            'message' => sprintf('Invalid date format for %s. Use YYYY-MM-DD.', $fieldName),
        ], 422);
    }

    return $dateValue;
}

function validateBookingStatus(?string $status): ?string
{
    if ($status === null) {
        return null;
    }

    $allowedStatuses = ['pending', 'confirmed', 'cancelled'];

    if (!in_array($status, $allowedStatuses, true)) {
        sendJson([
            'success' => false,
            'message' => 'Invalid booking status filter',
        ], 422);
    }

    return $status;
}

function getJsonRequestBody(): array
{
    $rawInput = file_get_contents('php://input');

    if ($rawInput === false || trim($rawInput) === '') {
        sendJson([
            'success' => false,
            'message' => 'Request body is required',
        ], 422);
    }

    $decodedBody = json_decode($rawInput, true);

    if (!is_array($decodedBody)) {
        sendJson([
            'success' => false,
            'message' => 'Invalid JSON body',
        ], 422);
    }

    return $decodedBody;
}

function getRequiredStringField(array $data, string $fieldName, int $maxLength): string
{
    $value = $data[$fieldName] ?? null;

    if (!is_string($value)) {
        sendJson([
            'success' => false,
            'message' => sprintf('Field %s is required.', $fieldName),
        ], 422);
    }

    $trimmedValue = trim($value);

    if ($trimmedValue === '') {
        sendJson([
            'success' => false,
            'message' => sprintf('Field %s cannot be empty.', $fieldName),
        ], 422);
    }

    if (mb_strlen($trimmedValue) > $maxLength) {
        sendJson([
            'success' => false,
            'message' => sprintf('Field %s is too long.', $fieldName),
        ], 422);
    }

    return $trimmedValue;
}

function getOptionalStringField(array $data, string $fieldName, int $maxLength): ?string
{
    $value = $data[$fieldName] ?? null;

    if ($value === null) {
        return null;
    }

    if (!is_string($value)) {
        sendJson([
            'success' => false,
            'message' => sprintf('Field %s must be a string.', $fieldName),
        ], 422);
    }

    $trimmedValue = trim($value);

    if ($trimmedValue === '') {
        return null;
    }

    if (mb_strlen($trimmedValue) > $maxLength) {
        sendJson([
            'success' => false,
            'message' => sprintf('Field %s is too long.', $fieldName),
        ], 422);
    }

    return $trimmedValue;
}

function validateEmailValue(string $email): string
{
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        sendJson([
            'success' => false,
            'message' => 'Invalid email address',
        ], 422);
    }

    return $email;
}

function validateSlotValue(?string $slot): string
{
    $allowedSlots = ['morning', 'afternoon', 'evening'];

    if ($slot === null || !in_array($slot, $allowedSlots, true)) {
        sendJson([
            'success' => false,
            'message' => 'Invalid slot value',
        ], 422);
    }

    return $slot;
}

function getAdminDisplayName(): string
{
    return getenv('ADMIN_DISPLAY_NAME') ?: 'Taki_Admin';
}



function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isSecureRequest =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_name('cleaner_admin_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isSecureRequest,
        'samesite' => $isSecureRequest ? 'None' : 'Lax',
    ]);

    session_start();
}

function markAdminAuthenticated(string $username, ?string $displayName): array
{
    startAdminSession();

    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $username;
    $_SESSION['admin_display_name'] = $displayName;
    $_SESSION['admin_logged_in_at'] = date('c');

    return [
        'username' => $username,
        'display_name' => $displayName,
        'logged_in_at' => $_SESSION['admin_logged_in_at'],
    ];
}

function getAdminSessionData(): ?array
{
    startAdminSession();

    $isAuthenticated = $_SESSION['admin_authenticated'] ?? false;

    if ($isAuthenticated !== true) {
        return null;
    }

    return [
        'username' => $_SESSION['admin_username'] ?? '',
        'display_name' => $_SESSION['admin_display_name'] ?? getAdminDisplayName(),
        'logged_in_at' => $_SESSION['admin_logged_in_at'] ?? null,
    ];
}

function ensureAdminAuthenticated(): array
{
    $sessionData = getAdminSessionData();

    if ($sessionData === null) {
        sendJson([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    return $sessionData;
}

function destroyAdminSession(): void
{
    startAdminSession();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        // Az IDE-k egy része a tömbös setcookie szignatúrát még hibásnak jelzi,
        // ezért itt egy szélesebben kompatibilis megoldást használunk.
        $cookiePath = $params['path'] ?? '/';
        $cookieDomain = $params['domain'] ?? '';
        $cookieSecure = $params['secure'] ?? false;
        $cookieHttpOnly = $params['httponly'] ?? true;
        $cookieSameSite = $params['samesite'] ?? 'Lax';
        $cookiePathWithSameSite = sprintf('%s; samesite=%s', $cookiePath, $cookieSameSite);

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookiePathWithSameSite,
            $cookieDomain,
            $cookieSecure,
            $cookieHttpOnly
        );
    }

    session_destroy();
}


