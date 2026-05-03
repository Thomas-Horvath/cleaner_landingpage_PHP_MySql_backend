<?php

/*
|--------------------------------------------------------------------------
| Közös levelező réteg
|--------------------------------------------------------------------------
|
| Ez a fájl azért készült, hogy a backend ugyanazon a helyen tudja kezelni
| az összes e-mail küldési feladatot.
|
| A fő ötlet:
| - az API végpontoknak ne kelljen SMTP részletekkel foglalkozniuk
| - fejlesztésben lehessen naplózni a leveleket valódi küldés helyett
| - később elegendő legyen a .env változókat átírni
|
| Támogatott driverek ebben a verzióban:
| - log  : fejlesztői mód, a levelek fájlba kerülnek
| - mail : PHP mail() használata
| - smtp : közvetlen SMTP kapcsolat env beállítások alapján
|
*/

require_once __DIR__ . '/env.php';

loadBackendEnv();

function getMailConfig(): array
{
    $driver = strtolower(trim((string) (getenv('MAIL_DRIVER') ?: 'log')));
    $allowedDrivers = ['log', 'mail', 'smtp'];

    if (!in_array($driver, $allowedDrivers, true)) {
        $driver = 'log';
    }

    $port = getenv('MAIL_PORT');
    $timeout = getenv('MAIL_TIMEOUT');

    return [
        'driver' => $driver,
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'Tiszta Műhely',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@example.com',
        'reply_to' => getenv('MAIL_REPLY_TO') ?: '',
        'admin_to' => getenv('MAIL_ADMIN_TO') ?: '',
        'log_path' => getenv('MAIL_LOG_PATH') ?: 'storage/logs/mailer.log',
        'host' => getenv('MAIL_HOST') ?: '',
        'port' => is_numeric($port) ? (int) $port : 465,
        'encryption' => strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'ssl'))),
        'username' => getenv('MAIL_USERNAME') ?: '',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'timeout' => is_numeric($timeout) ? (int) $timeout : 10,
    ];
}

function getAbsoluteMailLogPath(string $relativeOrAbsolutePath): string
{
    if (preg_match('/^[A-Za-z]:\\\\/', $relativeOrAbsolutePath) === 1) {
        return $relativeOrAbsolutePath;
    }

    if (str_starts_with($relativeOrAbsolutePath, DIRECTORY_SEPARATOR)) {
        return $relativeOrAbsolutePath;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeOrAbsolutePath);
}

function ensureMailLogDirectoryExists(string $logPath): void
{
    $directoryPath = dirname($logPath);

    if (is_dir($directoryPath)) {
        return;
    }

    if (!mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
        throw new RuntimeException('Nem sikerült létrehozni a mail log könyvtárat.');
    }
}

function normalizeMailRecipients(array|string $recipients): array
{
    $normalizedRecipients = is_array($recipients) ? $recipients : [$recipients];
    $validRecipients = [];

    foreach ($normalizedRecipients as $recipient) {
        if (!is_string($recipient)) {
            continue;
        }

        $candidate = trim($recipient);

        if ($candidate === '') {
            continue;
        }

        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }

        $validRecipients[] = $candidate;
    }

    return array_values(array_unique($validRecipients));
}

function encodeMimeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function buildMailHeaders(array $config, array $mailPayload): array
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . encodeMimeHeader($config['from_name']) . ' <' . $config['from_address'] . '>',
    ];

    if ($config['reply_to'] !== '' && filter_var($config['reply_to'], FILTER_VALIDATE_EMAIL) !== false) {
        $headers[] = 'Reply-To: ' . $config['reply_to'];
    }

    if (!empty($mailPayload['cc'])) {
        $ccRecipients = normalizeMailRecipients($mailPayload['cc']);

        if ($ccRecipients !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccRecipients);
        }
    }

    return $headers;
}

function buildEncodedMailBody(string $textBody): string
{
    return rtrim(chunk_split(base64_encode($textBody), 76, "\r\n"));
}

function buildRawMailMessage(array $config, array $mailPayload): string
{
    $toRecipients = normalizeMailRecipients($mailPayload['to'] ?? []);

    if ($toRecipients === []) {
        throw new RuntimeException('Nincs érvényes címzett az e-mail küldéshez.');
    }

    $subject = trim((string) ($mailPayload['subject'] ?? 'Értesítés'));
    $textBody = trim((string) ($mailPayload['text'] ?? ''));

    if ($textBody === '') {
        throw new RuntimeException('Az e-mail törzse nem lehet üres.');
    }

    $headers = buildMailHeaders($config, $mailPayload);
    $headers[] = 'To: ' . implode(', ', $toRecipients);
    $headers[] = 'Subject: ' . encodeMimeHeader($subject);
    $headers[] = 'Date: ' . date(DATE_RFC2822);

    return implode("\r\n", $headers)
        . "\r\n\r\n"
        . buildEncodedMailBody($textBody);
}

function appendMailLog(array $config, array $mailPayload, array $result): void
{
    $logPath = getAbsoluteMailLogPath($config['log_path']);
    ensureMailLogDirectoryExists($logPath);

    $logEntry = [
        'logged_at' => date('c'),
        'driver' => $config['driver'],
        'result' => $result,
        'mail' => [
            'to' => normalizeMailRecipients($mailPayload['to'] ?? []),
            'subject' => $mailPayload['subject'] ?? '',
            'text' => $mailPayload['text'] ?? '',
            'metadata' => $mailPayload['metadata'] ?? [],
        ],
    ];

    file_put_contents(
        $logPath,
        json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function readSmtpResponse($stream): string
{
    $response = '';

    while (!feof($stream)) {
        $line = fgets($stream, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }

    return trim($response);
}

function assertSmtpResponseCode(string $response, array $expectedCodes): void
{
    $responseCode = (int) substr($response, 0, 3);

    if (!in_array($responseCode, $expectedCodes, true)) {
        throw new RuntimeException('SMTP hiba: ' . $response);
    }
}

function sendSmtpCommand($stream, string $command, array $expectedCodes): string
{
    fwrite($stream, $command . "\r\n");
    $response = readSmtpResponse($stream);
    assertSmtpResponseCode($response, $expectedCodes);

    return $response;
}

function sendMailWithSmtp(array $config, array $mailPayload): array
{
    if ($config['host'] === '') {
        throw new RuntimeException('SMTP driver esetén a MAIL_HOST kötelező.');
    }

    $scheme = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $socketAddress = $scheme . $config['host'] . ':' . $config['port'];

    $stream = @stream_socket_client(
        $socketAddress,
        $errorNumber,
        $errorMessage,
        $config['timeout']
    );

    if ($stream === false) {
        throw new RuntimeException('Nem sikerült csatlakozni az SMTP szerverhez: ' . $errorMessage);
    }

    stream_set_timeout($stream, $config['timeout']);

    try {
        $greeting = readSmtpResponse($stream);
        assertSmtpResponseCode($greeting, [220]);

        sendSmtpCommand($stream, 'EHLO localhost', [250]);

        if ($config['encryption'] === 'tls') {
            sendSmtpCommand($stream, 'STARTTLS', [220]);

            $cryptoEnabled = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Nem sikerült TLS titkosításra váltani.');
            }

            sendSmtpCommand($stream, 'EHLO localhost', [250]);
        }

        if ($config['username'] !== '' || $config['password'] !== '') {
            sendSmtpCommand($stream, 'AUTH LOGIN', [334]);
            sendSmtpCommand($stream, base64_encode($config['username']), [334]);
            sendSmtpCommand($stream, base64_encode($config['password']), [235]);
        }

        $fromAddress = trim($config['from_address']);
        $toRecipients = normalizeMailRecipients($mailPayload['to'] ?? []);

        if ($toRecipients === []) {
            throw new RuntimeException('Nincs érvényes címzett az SMTP küldéshez.');
        }

        sendSmtpCommand($stream, 'MAIL FROM:<' . $fromAddress . '>', [250]);

        foreach ($toRecipients as $recipient) {
            sendSmtpCommand($stream, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        }

        sendSmtpCommand($stream, 'DATA', [354]);

        $rawMessage = buildRawMailMessage($config, $mailPayload);
        $dotStuffedMessage = preg_replace('/(?m)^\./', '..', $rawMessage) ?: $rawMessage;

        fwrite($stream, $dotStuffedMessage . "\r\n.\r\n");

        $dataResponse = readSmtpResponse($stream);
        assertSmtpResponseCode($dataResponse, [250]);

        sendSmtpCommand($stream, 'QUIT', [221]);

        return [
            'success' => true,
            'driver' => 'smtp',
            'message' => 'Az e-mail sikeresen elküldve SMTP kapcsolaton keresztül.',
        ];
    } finally {
        fclose($stream);
    }
}

function sendMailWithPhpMail(array $config, array $mailPayload): array
{
    $toRecipients = normalizeMailRecipients($mailPayload['to'] ?? []);

    if ($toRecipients === []) {
        throw new RuntimeException('Nincs érvényes címzett a mail() küldéshez.');
    }

    $subject = encodeMimeHeader(trim((string) ($mailPayload['subject'] ?? 'Értesítés')));
    $body = buildEncodedMailBody(trim((string) ($mailPayload['text'] ?? '')));
    $headers = implode("\r\n", buildMailHeaders($config, $mailPayload));

    $sent = mail(implode(', ', $toRecipients), $subject, $body, $headers);

    if ($sent !== true) {
        throw new RuntimeException('A PHP mail() függvény nem tudta elküldeni az e-mailt.');
    }

    return [
        'success' => true,
        'driver' => 'mail',
        'message' => 'Az e-mail elküldve a PHP mail() függvénnyel.',
    ];
}

function dispatchMail(array $mailPayload): array
{
    $config = getMailConfig();

    try {
        if ($config['driver'] === 'smtp') {
            $result = sendMailWithSmtp($config, $mailPayload);
        } elseif ($config['driver'] === 'mail') {
            $result = sendMailWithPhpMail($config, $mailPayload);
        } else {
            $result = [
                'success' => true,
                'driver' => 'log',
                'message' => 'Fejlesztői módban a levél naplózva lett.',
            ];
        }
    } catch (Throwable $throwable) {
        $result = [
            'success' => false,
            'driver' => $config['driver'],
            'message' => $throwable->getMessage(),
        ];
    }

    if ($config['driver'] === 'log' || $result['success'] === false) {
        appendMailLog($config, $mailPayload, $result);
    }

    return $result;
}

function getSlotLabelForMail(string $slot): string
{
    return match ($slot) {
        'morning' => 'Délelőtt (8:00-11:00)',
        'afternoon' => 'Délután (12:00-15:00)',
        'evening' => 'Este (16:00-19:00)',
        default => $slot,
    };
}

function formatDateForMail(string $dateValue): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateValue);

    if (!$date) {
        return $dateValue;
    }

    $weekdays = [
        'Monday' => 'hétfő',
        'Tuesday' => 'kedd',
        'Wednesday' => 'szerda',
        'Thursday' => 'csütörtök',
        'Friday' => 'péntek',
        'Saturday' => 'szombat',
        'Sunday' => 'vasárnap',
    ];

    $englishWeekday = $date->format('l');
    $weekday = $weekdays[$englishWeekday] ?? $englishWeekday;

    return $date->format('Y. m. d.') . ' (' . $weekday . ')';
}

function buildBookingSummaryLines(array $booking): array
{
    return [
        'Név: ' . ($booking['customer_name'] ?? '-'),
        'E-mail: ' . ($booking['email'] ?? '-'),
        'Telefon: ' . ($booking['phone'] ?? '-'),
        'Cím: ' . ($booking['address'] ?? '-'),
        'Szolgáltatás: ' . (($booking['service_type'] ?? null) ?: 'Nincs megadva'),
        'Dátum: ' . formatDateForMail((string) ($booking['booking_date'] ?? '')), 
        'Idősáv: ' . getSlotLabelForMail((string) ($booking['slot'] ?? '')),
        'Üzenet: ' . (($booking['message'] ?? null) ?: 'Nincs külön megjegyzés'),
    ];
}

function buildMailText(string $introText, array $summaryLines, array $closingLines = []): string
{
    $lines = [$introText, '', 'Foglalási adatok:'];

    foreach ($summaryLines as $summaryLine) {
        $lines[] = '- ' . $summaryLine;
    }

    if ($closingLines !== []) {
        $lines[] = '';

        foreach ($closingLines as $closingLine) {
            $lines[] = $closingLine;
        }
    }

    return implode(PHP_EOL, $lines);
}

function sendCustomerBookingPendingEmail(array $booking): array
{
    return dispatchMail([
        'to' => [$booking['email'] ?? ''],
        'subject' => 'Megkaptuk az ajánlatkérésedet',
        'text' => buildMailText(
            'Szia! Köszönöm, megkaptam az ajánlatkérésedet. Rövidesen átnézem, és legfeljebb 24 órán belül visszajelzek.',
            buildBookingSummaryLines($booking),
            [
                'Ha addig bármit pontosítanál, nyugodtan válaszolj erre a levélre.',
                '',
                'Tiszta Műhely',
            ]
        ),
        'metadata' => [
            'event' => 'booking_created_customer',
            'booking_id' => $booking['id'] ?? null,
        ],
    ]);
}

function sendAdminNewBookingEmail(array $booking): array
{
    $config = getMailConfig();
    $adminRecipient = trim($config['admin_to']);

    if (filter_var($adminRecipient, FILTER_VALIDATE_EMAIL) === false) {
        return [
            'success' => false,
            'driver' => $config['driver'],
            'message' => 'A MAIL_ADMIN_TO cím nincs beállítva vagy érvénytelen.',
        ];
    }

    return dispatchMail([
        'to' => [$adminRecipient],
        'subject' => 'Új ajánlatkérés érkezett',
        'text' => buildMailText(
            'Új foglalási kérés érkezett a weboldalról. Az alábbi részleteket érdemes átnézni az admin felületen.',
            buildBookingSummaryLines($booking),
            [
                'Admin teendő: jelöld confirmed vagy cancelled állapotra a kérés feldolgozása után.',
            ]
        ),
        'metadata' => [
            'event' => 'booking_created_admin',
            'booking_id' => $booking['id'] ?? null,
        ],
    ]);
}

function sendCustomerBookingStatusEmail(array $booking, string $status): array
{
    $subject = $status === 'confirmed'
        ? 'Visszaigazoltuk az időpontodat'
        : 'Az ajánlatkérésed állapota frissült';

    $introText = $status === 'confirmed'
        ? 'Jó hír, a kért időpontot visszaigazoltam. Az alábbi foglalási adatokkal várlak.'
        : 'Az ajánlatkérésedet átnéztem, de ezt az időpontot most nem tudom vállalni. Az alábbi kérést zártam le.';

    $closingLines = $status === 'confirmed'
        ? [
            'Ha a foglalás részleteiben változás történik, kérlek időben jelezd.',
            '',
            'Tiszta Műhely',
        ]
        : [
            'Ha szeretnél másik napot kérni, kérlek küldj új ajánlatkérést vagy írj közvetlenül.',
            '',
            'Tiszta Műhely',
        ];

    return dispatchMail([
        'to' => [$booking['email'] ?? ''],
        'subject' => $subject,
        'text' => buildMailText($introText, buildBookingSummaryLines($booking), $closingLines),
        'metadata' => [
            'event' => 'booking_status_changed_customer',
            'booking_id' => $booking['id'] ?? null,
            'status' => $status,
        ],
    ]);
}

function notifyBookingCreated(array $booking): array
{
    return [
        'customer' => sendCustomerBookingPendingEmail($booking),
        'admin' => sendAdminNewBookingEmail($booking),
    ];
}

function notifyBookingStatusChanged(array $booking, string $status): array
{
    return [
        'customer' => sendCustomerBookingStatusEmail($booking, $status),
    ];
}
