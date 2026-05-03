<?php

/*
|--------------------------------------------------------------------------
| Egyszerű .env betöltő
|--------------------------------------------------------------------------
|
| Ez a fájl lehetővé teszi, hogy a backend egy helyi .env fájlból is tudjon
| beállításokat olvasni fejlesztés közben.
|
| Miért hasznos ez?
| - Docker vagy tárhely szintű env nélkül is tudunk konfigurálni
| - a levelezési és adatbázis adatok egy külön fájlba kerülhetnek
| - később elég a .env értékeket átírni, a PHP kódhoz nem kell nyúlni
|
*/

function loadBackendEnv(): void
{
    static $hasLoaded = false;

    if ($hasLoaded) {
        return;
    }

    $hasLoaded = true;

    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmedLine = trim($line);

        if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
            continue;
        }

        $separatorPosition = strpos($trimmedLine, '=');

        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($trimmedLine, 0, $separatorPosition));
        $value = trim(substr($trimmedLine, $separatorPosition + 1));

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
