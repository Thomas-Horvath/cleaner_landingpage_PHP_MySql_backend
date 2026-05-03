-- SET NAMES utf8mb4;

-- CREATE DATABASE IF NOT EXISTS cleaner_booking
--   CHARACTER SET utf8mb4
--   COLLATE utf8mb4_unicode_ci;

USE cleaner_booking;

CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  phone VARCHAR(60) NOT NULL,
  address VARCHAR(255) NOT NULL,
  service_type VARCHAR(120) NULL,
  message TEXT NULL,
  status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  booking_date DATE NOT NULL,
  slot ENUM('morning', 'afternoon', 'evening') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_booking_slots_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
    ON DELETE CASCADE,
  UNIQUE KEY unique_slot (booking_date, slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO admin_users (id, username, password_hash, display_name, is_active)
VALUES
  (1, 'muhelygazda', '$2y$12$8S0UryU8GTiSQ.sgOVus9eg9V0NJ.O06P4ns2v7WtTwiqUS8ExExm', 'Taki_Admin', 1)
ON DUPLICATE KEY UPDATE
  username = VALUES(username),
  password_hash = VALUES(password_hash),
  display_name = VALUES(display_name),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

-- Minta foglalások az aktuális és a következő hétre,
-- hogy a frontend naptárban egyszerre látszódjanak a szabad, függőben és foglalt állapotok.
INSERT INTO bookings (id, customer_name, email, phone, address, service_type, message, status, created_at, updated_at)
VALUES
  (1, 'Kiss Anna', 'anna@example.com', '+36301230001', '1117 Budapest, Minta utca 12.', 'Lakástakarítás', 'Heti rendszerességgel érdeklődöm.', 'confirmed', '2026-04-28 10:15:00', '2026-04-28 10:15:00'),
  (2, 'Szabó Petra', 'petra@example.com', '+36301230002', '1136 Budapest, Duna köz 4.', 'Nagytakarítás', 'Költözés előtti takarítás kellene.', 'pending', '2026-04-28 11:20:00', '2026-04-28 11:20:00'),
  (3, 'Tóth Réka', 'reka@example.com', '+36301230003', '1024 Budapest, Rózsahegy tér 8.', 'Irodatakarítás', 'Pénteki időpont érdekel.', 'confirmed', '2026-04-28 12:35:00', '2026-04-28 12:35:00'),
  (4, 'Varga Luca', 'luca@example.com', '+36301230004', '1142 Budapest, Jázmin utca 2.', 'Ablaktisztítás', 'Egyelőre csak érdeklődés.', 'pending', '2026-04-28 13:50:00', '2026-04-28 13:50:00'),
  (5, 'Molnár Eszter', 'eszter@example.com', '+36301230005', '1111 Budapest, Bartók köz 9.', 'Lakástakarítás', 'Az aktuális hétre keresek délelőtti időpontot.', 'pending', '2026-04-29 08:10:00', '2026-04-29 08:10:00'),
  (6, 'Fekete Nóra', 'nora@example.com', '+36301230006', '1037 Budapest, Füge utca 17.', 'Nagytakarítás', 'Csütörtök délelőtt lenne ideális.', 'confirmed', '2026-04-29 09:25:00', '2026-04-29 09:25:00'),
  (7, 'Balogh Dóra', 'dora@example.com', '+36301230007', '1221 Budapest, Levendula sor 5.', 'Lakástakarítás', 'Péntek estére érdeklődöm.', 'pending', '2026-04-29 10:40:00', '2026-04-29 10:40:00'),
  (8, 'Papp Júlia', 'julia@example.com', '+36301230008', '1028 Budapest, Gesztenye lejtő 21.', 'Irodatakarítás', 'Szombat reggelre fix időpont kellene.', 'confirmed', '2026-04-29 11:55:00', '2026-04-29 11:55:00')
ON DUPLICATE KEY UPDATE
  customer_name = VALUES(customer_name),
  email = VALUES(email),
  phone = VALUES(phone),
  address = VALUES(address),
  service_type = VALUES(service_type),
  message = VALUES(message),
  status = VALUES(status),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO booking_slots (id, booking_id, booking_date, slot, created_at)
VALUES
  (1, 1, '2026-05-04', 'morning', '2026-04-28 10:15:00'),
  (2, 2, '2026-05-05', 'afternoon', '2026-04-28 11:20:00'),
  (3, 3, '2026-05-07', 'evening', '2026-04-28 12:35:00'),
  (4, 4, '2026-05-09', 'morning', '2026-04-28 13:50:00'),
  (5, 5, '2026-04-29', 'afternoon', '2026-04-29 08:10:00'),
  (6, 6, '2026-04-30', 'morning', '2026-04-29 09:25:00'),
  (7, 7, '2026-05-01', 'evening', '2026-04-29 10:40:00'),
  (8, 8, '2026-05-02', 'morning', '2026-04-29 11:55:00')
ON DUPLICATE KEY UPDATE
  booking_id = VALUES(booking_id),
  booking_date = VALUES(booking_date),
  slot = VALUES(slot);
