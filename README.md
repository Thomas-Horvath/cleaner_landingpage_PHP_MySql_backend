# Cleaner Backend

A `cleaner_backend` a Tiszta Műhely foglalási rendszerének PHP + MySQL alapú backend része.

A projekt célja, hogy külön backend rétegben kezelje:

- az ajánlatkérések mentését
- a foglalási idősávok állapotát
- az admin hitelesítést és session kezelést
- a foglalások admin oldali szerkesztését
- a fejlesztői vagy éles levelezési logikát

Ez a mappa önálló backend egységként is használható, saját Dockeres fejlesztői környezettel.

## Technológiai alapok

- `PHP 8.4 + Apache`
- `MySQL 8.0`
- `phpMyAdmin`
- `PDO`
- `session` alapú admin hitelesítés

## Fő funkciók

### Publikus API

- egészségügyi ellenőrző végpont
- foglalási összesítés
- foglalt és függőben lévő slotok lekérdezése
- új ajánlatkérés létrehozása `pending` státusszal

### Admin API

- admin belépés és kijelentkezés
- session állapot lekérdezése
- admin foglaláslista lapozással
- státuszváltás: `pending -> confirmed` vagy `pending -> cancelled`
- meglévő foglalási blokk áthelyezése
- új időblokk hozzáadása meglévő foglaláshoz

### Levelezés

- fejlesztésben logolható levélfolyamat
- éles környezetben SMTP-re átállítható mailer réteg

## Mappastruktúra

```text
cleaner_backend/
├─ api/
├─ config/
├─ docker/
├─ docs/
├─ mysql/
├─ storage/
├─ .env
├─ .htaccess
└─ docker-compose.yml
```

### Röviden mit tartalmaznak

- `api/`
  A publikus és admin végpontok.

- `config/`
  Közös backend segédek, például:
  - környezeti változók betöltése
  - PDO kapcsolat
  - CORS és JSON segédfüggvények
  - mailer réteg

- `docker/`
  A PHP konténerhez tartozó Docker konfiguráció.

- `docs/`
  Backend-specifikus dokumentációk és tanulóanyagok.

- `mysql/`
  SQL inicializáló és sémafájlok.

- `storage/`
  Belső naplók és futás közben létrejövő fájlok.

## Fejlesztői indítás Dockerrel

A backend saját Docker Compose környezettel indítható.
A compose fájl a `cleaner_backend` mappában van, ezért a parancsokat ebből a mappából futtasd.

Indítás:

```bash
cd cleaner_backend
docker compose up -d
```

Leállítás:

```bash
cd cleaner_backend
docker compose down
```

Újraépítés:

```bash
cd cleaner_backend
docker compose up -d --build
```

Ha teljesen tiszta új adatbázis inicializálást is szeretnél:

```bash
cd cleaner_backend
docker compose down -v
docker compose up -d --build
```

## Lokális elérési címek

Ha a Docker környezet fut, ezek az alapértelmezett címek:

- PHP API: `http://localhost:8080`
- Health endpoint: `http://localhost:8080/api/health.php`
- phpMyAdmin: `http://localhost:8081`
- MySQL host gépről: `127.0.0.1:3307`

## Fontos végpontok

### Nyilvános végpontok

- `GET /api/health.php`
- `GET /api/bookings.php`
- `GET /api/bookings-summary.php`
- `GET /api/booking-slots.php`
- `POST /api/bookings-create.php`

### Admin végpontok

- `POST /api/admin-login.php`
- `GET /api/admin-session.php`
- `POST /api/admin-logout.php`
- `GET /api/admin-bookings.php`
- `POST /api/admin-booking-status.php`
- `POST /api/admin-booking-slot-add.php`
- `POST /api/admin-booking-slot-move.php`

## Admin működés röviden

Az admin rész session alapú hitelesítéssel működik.

A folyamat:

1. a frontend belépési adatokat küld az `admin-login.php` végpontra
2. a backend ellenőrzi az admin felhasználót az adatbázisban
3. siker esetén PHP session jön létre
4. a frontend `credentials: 'include'` beállítással használja a védett végpontokat
5. az admin lista és a foglalásszerkesztés már csak aktív sessionnel működik

## Foglalási modell

A rendszer két fő adatbázis táblára épül:

- `bookings`
- `booking_slots`

Ez azért fontos, mert egy foglaláshoz több időblokk is tartozhat.

Például:
- egy ügyfél kap egy délelőtti blokkot
- később ugyanahhoz a foglaláshoz hozzáadható egy délutáni blokk is

Ez a modell teszi lehetővé:
- több slot egy bookinghoz
- slot áthelyezést
- jövőbeli bővítéseket

## Admin foglalásszerkesztés

A backend jelenleg ezeket a szerkesztési műveleteket támogatja:

### 1. Státuszváltás

- `pending -> confirmed`
- `pending -> cancelled`

A `cancelled` művelet felszabadítja a kapcsolódó slotokat, ezért azok újra foglalhatóvá válnak.

### 2. Új időblokk hozzáadása

A `admin-booking-slot-add.php` végpont új slotot tud hozzáadni egy meglévő foglaláshoz.

Üzleti szabályok:
- csak jövőbeli időpont adható hozzá
- csak aktív foglaláshoz adható hozzá
- foglalt vagy függőben lévő slotra nem ment

### 3. Időblokk áthelyezése

A `admin-booking-slot-move.php` egy meglévő foglalási blokkot mozgat másik napra vagy napszakra.

Üzleti szabályok:
- csak jövőbeli időpontra mozgatható
- csak aktív foglalás blokkja mozgatható
- ütköző slotra nem ment

## Levelezési réteg

A backend külön mailer réteget használ.

Támogatott módok:
- `log`
- `mail`
- `smtp`

Fejlesztésben jellemzően a `log` mód a legbiztonságosabb.
Ilyenkor a levelek nem kerülnek kiküldésre, csak naplózódnak.

Éles környezetben a `.env` átállítható SMTP-re.

## Környezeti változók

A backend működéséhez szükséges változók tipikusan ezekből a csoportokból jönnek:

### Adatbázis

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `DB_CHARSET`

### Frontend kapcsolat

- `FRONTEND_ORIGINS`

### Mailer

- `MAIL_DRIVER`
- `MAIL_FROM_NAME`
- `MAIL_FROM_ADDRESS`
- `MAIL_REPLY_TO`
- `MAIL_ADMIN_TO`
- `MAIL_LOG_PATH`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_ENCRYPTION`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_TIMEOUT`

## Példa `.env` felépítés

Az alábbi minta csak szemléltetés, nem valódi értékeket tartalmaz:

```env
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
DB_CHARSET=utf8mb4

FRONTEND_ORIGINS=http://localhost:3000,https://your-frontend-domain.example

MAIL_DRIVER=log
MAIL_FROM_NAME=Your Project Name
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_REPLY_TO=hello@example.com
MAIL_ADMIN_TO=admin@example.com
MAIL_LOG_PATH=storage/logs/mailer.log
MAIL_HOST=mail.example.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=mailer@example.com
MAIL_PASSWORD=your_mail_password
MAIL_TIMEOUT=10
```

## phpMyAdmin és adatbázis

A Dockeres MySQL konténerhez phpMyAdmin is tartozik, így fejlesztés közben egyszerű a séma és az adatok ellenőrzése.

Ha új adatbázissal indulsz:
- importáld az SQL inicializáló fájlt
- ellenőrizd, hogy a szükséges táblák létrejöttek
- csak ezután próbáld ki a foglalási vagy admin végpontokat

## Apache védelem

A backend `.htaccess` alapú mappavédelmet is használ.

A cél:
- a `config` mappa ne legyen publikus
- a `storage` mappa ne legyen publikus
- a `.env` ne legyen közvetlenül elérhető
- az `api` mappa maradjon elérhető, de könyvtárlistázás nélkül

Ez különösen fontos megosztott tárhely vagy publikus deploy esetén.

## Nethelyes deploy rövid megjegyzés

A projektet már úgy alakítottuk, hogy Nethelyes Apache + MySQL környezetbe is kitehető legyen.

Gyakorlati tapasztalat alapján:
- a Nethelyen futó PHP backendnél az adatbázis host jellemzően `localhost`
- a távoli MySQL host nem ugyanaz, mint a szerveren belüli PHP kapcsolatnál használt host

A részletes deploy leírás a projekt dokumentációjában található.

## Fejlesztői ellenőrzés

Hasznos ellenőrzések módosítás után:

- PHP szintaxisellenőrzés az érintett végpontokra
- frontend oldali integráció tesztelése
- health endpoint ellenőrzése
- admin login és admin booking lista tesztelése

## Dokumentáció

A backendhez külön tanuló- és üzemeltetési dokumentációk is tartoznak.

A projekt gyökerében lévő `docs` mappában megtalálhatók:
- általános rendszerleírás
- PHP backend alapok
- backend fájlmagyarázat
- Nethely deploy dokumentáció

## Biztonsági megjegyzés

A repository-ban ne tárolj:
- valódi adatbázis jelszót
- valódi SMTP jelszót
- valódi admin belépési adatokat
- éles domainhez kötött titkos kulcsokat

A publikus repository-ba csak mintaértékek, dokumentációs példák és `.env` sablonok kerüljenek.


