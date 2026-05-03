# Cleaner Full Stack

Teljes fejlesztői környezet a takarítási projekt frontend és backend részéhez.

## Projektstruktúra

```text
Cleaner_Full_Stack/
├─ cleaner_frontend/   # Next.js frontend
├─ cleaner_backend/    # PHP backend
├─ docker/
│  └─ php/
│     └─ Dockerfile
├─ mysql/
│  └─ init.sql
└─ docker-compose.yml
```

## Mire való ez a setup?

- A `cleaner_frontend` külön futó Next.js projekt marad.
- A `cleaner_backend` PHP 8.4 + Apache alatt fut Dockerben.
- A MySQL külön konténerben fut.
- A phpMyAdmin külön konténerben fut.
- A backend mappa közvetlenül mountolva van a PHP konténerbe, ezért a PHP fájlmódosítások azonnal látszanak.

## Elérési címek fejlesztés közben

- Frontend: `http://localhost:3000`
- PHP API: `http://localhost:8080`
- Health endpoint: `http://localhost:8080/api/health.php`
- phpMyAdmin: `http://localhost:8081`
- MySQL host gépről: `127.0.0.1:3307`

## Előfeltételek

- Docker Desktop telepítve
- Docker Compose elérhető
- Node.js telepítve a frontendhez
- NPM elérhető

## Első indítás

Nyiss terminált a projekt gyökerében:

```bash
cd Cleaner_Full_Stack
```

Indítsd el a backendes Docker környezetet:

```bash
docker compose up -d
```

Ez elindítja:

- a PHP + Apache konténert
- a MySQL konténert
- a phpMyAdmin konténert

Ezután ellenőrzés:

```text
http://localhost:8080/api/health.php
```

Ha minden rendben, ezt kell visszakapnod:

```json
{
  "success": true,
  "message": "PHP backend is running",
  "database": "connected"
}
```

phpMyAdmin:

```text
http://localhost:8081
```

Frontend indítás külön terminálban:

```bash
cd cleaner_frontend
npm run dev
```

Frontend URL:

```text
http://localhost:3000
```

## Mit kell csinálni legközelebb?

Ha már egyszer létrehoztad a konténereket, a következő alkalommal általában ugyanazt kell futtatni:

```bash
docker compose up -d
```

Ez nem építi újra feleslegesen az egészet minden alkalommal, hanem:

- elindítja a már létező konténereket
- ha szükséges, újra létrehozza őket
- megtartja a MySQL adatokat a volume-ban

Utána újra elindítod külön a frontendet:

```bash
cd cleaner_frontend
npm run dev
```

Tehát a napi rutin általában:

1. `docker compose up -d`
2. `cd cleaner_frontend`
3. `npm run dev`

## Mit kell most csinálnod a PHP 8.4 átállás után?

Mivel a `docker/php/Dockerfile` megváltozott, a PHP konténert újra kell építened.

Lépések:

1. Menj a projekt gyökerébe:

```bash
cd Cleaner_Full_Stack
```

2. Állítsd le a futó konténereket:

```bash
docker compose down
```

3. Építsd újra és indítsd el a környezetet:

```bash
docker compose up -d --build
```

4. Ellenőrizd a health endpointot:

```text
http://localhost:8080/api/health.php
```

5. Ezután külön terminálban indítsd újra a frontendet:

```bash
cd cleaner_frontend
npm run dev
```

Fontos:

- a `docker compose down` nem törli az adatbázis volume-ot
- a MySQL adatok ettől még megmaradnak
- csak akkor vesznek el, ha `docker compose down -v` parancsot futtatsz

## Mikor kell újra buildelni a Docker környezetet?

Általában nem kell minden alkalommal.

Elég a sima:

```bash
docker compose up -d
```

Újra build akkor kell, ha például:

- módosítod a `docker/php/Dockerfile` fájlt
- új PHP extension kell
- Apache szintű változtatás történt

Ilyenkor:

```bash
docker compose up -d --build
```

## Leállítás

Ha csak meg akarod állítani a konténereket:

```bash
docker compose stop
```

Ha le akarod állítani és el is akarod távolítani a konténereket:

```bash
docker compose down
```

Fontos:

- a `down` nem törli automatikusan a MySQL volume adatokat
- a DB adatok megmaradnak, mert külön volume-ban vannak

## Mikor vesznek el az adatbázis adatok?

Normál `docker compose down` után nem vesznek el.

Csak akkor törlődnek, ha explicit volume törlést is kérsz:

```bash
docker compose down -v
```

Ezt csak akkor használd, ha teljesen tiszta új adatbázist akarsz.

## Backend fejlesztés használat közben

A `cleaner_backend` mappa be van mountolva ide:

```text
/var/www/html
```

Ez azt jelenti, hogy ha helyben módosítasz egy PHP fájlt, azt a konténer azonnal látja.

Például:

- `cleaner_backend/api/health.php`
- `cleaner_backend/config/database.php`
- `cleaner_backend/admin/index.php`

PHP fájlmódosítás után általában nem kell konténert újraindítani.

## Frontend és backend kapcsolat

A frontend külön fut, nem Dockerben.

A backend API alap URL fejlesztéshez:

```text
http://localhost:8080/api
```

Ehhez készült példa env fájl:

`cleaner_frontend/.env.local.example`

Tartalma:

```env
NEXT_PUBLIC_API_URL=http://localhost:8080/api
```

Fejlesztéshez ebből majd készíthetsz saját `.env.local` fájlt.

Fontos:

- csak publikus API URL kerüljön frontend env-be
- adatbázis jelszó vagy érzékeny backend adat ne kerüljön frontend env változóba

## Adatbázis adatok fejlesztéshez

Dockeres backendből:

- host: `mysql`
- database: `cleaner_booking`
- user: `cleaner_user`
- password: `cleaner_pass`
- charset: `utf8mb4`

Host gépről, ha külső klienssel csatlakoznál:

- host: `127.0.0.1`
- port: `3307`
- database: `cleaner_booking`
- user: `cleaner_user`
- password: `cleaner_pass`

## phpMyAdmin belépés

URL:

```text
http://localhost:8081
```

Belépési adatok:

- szerver: `mysql`
- user: `cleaner_user`
- password: `cleaner_pass`

## Hasznos parancsok

Konténerek állapota:

```bash
docker compose ps
```

Logok megtekintése:

```bash
docker compose logs
```

Csak a PHP logok:

```bash
docker compose logs php
```

Csak a MySQL logok:

```bash
docker compose logs mysql
```

Folyamatos log követés:

```bash
docker compose logs -f
```

Konténer újraindítás:

```bash
docker compose restart
```

Csak a PHP konténer újraindítása:

```bash
docker compose restart php
```

## Gyakori helyzetek

Ha a backend nem érhető el:

1. nézd meg: `docker compose ps`
2. nézd meg: `docker compose logs php`
3. ellenőrizd: `http://localhost:8080/api/health.php`

Ha az adatbázis kapcsolat hibás:

1. nézd meg: `docker compose logs mysql`
2. ellenőrizd a `cleaner_backend/config/database.php` fájlt
3. ellenőrizd, hogy a MySQL konténer fut-e

Ha az `init.sql` módosult, de nem látszik:

Az `init.sql` csak az adatbázis első létrehozásakor fut le automatikusan.

Ha teljesen új inicializálást akarsz:

```bash
docker compose down -v
docker compose up -d
```

Figyelem:

Ez törli a meglévő fejlesztői adatbázis adatokat.

## Backend fájlok szerepe

- `cleaner_backend/api/health.php`
  Egyszerű health endpoint JSON válasszal és DB ellenőrzéssel.

- `cleaner_backend/config/database.php`
  PDO adatbázis kapcsolat. Később Nethely környezethez könnyen átírható vagy env-alapúvá tehető.

- `cleaner_backend/admin/index.php`
  Placeholder admin oldal.

- `cleaner_backend/.htaccess`
  Apache rewrite és későbbi route szabályok helye.

- `mysql/init.sql`
  Fejlesztői adatbázis struktúra inicializálása.

- `docker/php/Dockerfile`
  PHP 8.4 Apache image, rewrite modul és MySQL/PDO extensionök.

- `docker-compose.yml`
  A teljes fejlesztői környezet indítása.

## Ajánlott napi workflow

1. Nyisd meg a projekt gyökerét.
2. Futtasd: `docker compose up -d`
3. Ellenőrizd: `http://localhost:8080/api/health.php`
4. Indítsd a frontendet:

```bash
cd cleaner_frontend
npm run dev
```

5. Dolgozz a frontend és backend részen párhuzamosan.
6. Ha végeztél, opcionálisan állítsd le:

```bash
docker compose stop
```

## Későbbi éles környezet

Ez a setup fejlesztői környezethez készült.

Később Nethely vagy más hoszting esetén valószínűleg:

- a frontend külön lesz buildelve és deployolva
- a PHP backend külön tárhelyre kerül
- a `database.php` éles DB adatokkal fog működni
- a Docker Compose nem feltétlenül lesz része az éles futtatásnak
