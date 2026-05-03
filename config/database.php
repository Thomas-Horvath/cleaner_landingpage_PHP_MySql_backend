<?php

/*
|--------------------------------------------------------------------------
| Adatbázis kapcsolat
|--------------------------------------------------------------------------
|
| Ez a fájl azért felel, hogy létrejöjjön egy PDO kapcsolat a MySQL adatbázissal.
|
| Nagyon fontos ötlet:
| - ez a fájl NEM küld választ a böngészőnek
| - csak előkészíti a $pdo változót
| - a többi API fájl ezt betölti require_once segítségével
|
| Ha sikerül a kapcsolat, lesz egy használható $pdo objektumunk.
| Ha nem sikerül, kivétel (exception) keletkezik.
|
*/

require_once __DIR__ . '/env.php';

loadBackendEnv();

// A kapcsolat alap adatait egy tömbben tartjuk.
// Ez átláthatóbb, mint külön változókban szétszórni őket.
//
// getenv('...'):
//   környezeti változót olvas ki
// ?: 'alapérték'
//   ha nincs környezeti változó, a jobb oldali default értéket használja
//
// Így fejlesztéshez működik a Dockeres mysql hosttal,
// később Nethelynél viszont könnyen átírható.
$databaseConfig = [
    'host' => getenv('DB_HOST') ?: 'mysql',
    'database' => getenv('DB_NAME') ?: 'cleaner_booking',
    'user' => getenv('DB_USER') ?: 'cleaner_user',
    'password' => getenv('DB_PASSWORD') ?: 'cleaner_pass',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];

// A DSN (Data Source Name) egy speciális kapcsolatleíró szöveg.
// Ebben mondjuk meg a PDO-nak:
// - milyen adatbázishoz csatlakozzon
// - melyik hoston
// - milyen karakterkódolással
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $databaseConfig['host'],
    $databaseConfig['database'],
    $databaseConfig['charset']
);

try {
    // Létrehozzuk a PDO objektumot.
    //
    // Paraméterek:
    // 1. DSN
    // 2. felhasználónév
    // 3. jelszó
    // 4. opciók tömbje
    $pdo = new PDO(
        $dsn,
        $databaseConfig['user'],
        $databaseConfig['password'],
        [
            // Hiba esetén ne csendben fusson tovább, hanem dobjon kivételt.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // A fetch műveletek alapból asszociatív tömböt adjanak vissza.
            // Így $row['customer_name'] formában érjük el az adatokat.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Az emulált prepare kikapcsolása biztonságosabb és kiszámíthatóbb.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    // Itt nem küldünk még JSON választ.
    // Csak továbbdobjuk a hibát egy olvashatóbb üzenettel.
    //
    // A végpontok majd saját maguk döntik el, hogyan kezelik ezt a hibát.
    throw new PDOException('Database connection failed: ' . $exception->getMessage(), (int) $exception->getCode());
}
