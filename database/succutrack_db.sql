DROP DATABASE IF EXISTS succutrackv3;
CREATE DATABASE succutrackv3;
USE succutrackv3;

-- TABLES

CREATE TABLE users (
  user_id    INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(50)  UNIQUE NOT NULL,
  password   VARCHAR(255) NOT NULL,
  email      VARCHAR(100) UNIQUE NOT NULL,
  role       ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE plants (
  plant_id   INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  plant_name VARCHAR(100) NOT NULL,
  city       VARCHAR(100) NOT NULL DEFAULT 'Unknown',
  latitude   DECIMAL(10,7) NULL,
  longitude  DECIMAL(10,7) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE humidity (
  humidity_id      INT AUTO_INCREMENT PRIMARY KEY,
  plant_id         INT NULL,
  humidity_percent DECIMAL(5,2) NOT NULL,
  status           VARCHAR(20)  NOT NULL,
  recorded_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (plant_id) REFERENCES plants(plant_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE user_logs (
  log_id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  humidity_id INT NOT NULL,
  FOREIGN KEY (user_id)     REFERENCES users(user_id)     ON DELETE CASCADE,
  FOREIGN KEY (humidity_id) REFERENCES humidity(humidity_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- USERS  (all passwords = "password")

INSERT INTO users (user_id, username, password, email, role, created_at) VALUES
(1, 'admin',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@succutrack.com',   'admin',   '2026-01-01 08:00:00'),
(2, 'juan',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'juan@succutrack.com',    'user',    '2026-01-02 09:00:00'),
(3, 'maria',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'maria@succutrack.com',   'user',    '2026-01-03 10:00:00'),
(4, 'pedro',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pedro@succutrack.com',   'user',    '2026-01-04 11:00:00'),
(5, 'manager1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager@succutrack.com', 'manager', '2026-01-05 08:00:00');

-- PLANTS  (1 plant per user = 1 IoT device)

INSERT INTO plants (plant_id, user_id, plant_name, city, latitude, longitude, created_at) VALUES
(1, 2, 'Aloe Vera',  'Manolo Fortich', 8.3720000, 124.8580000, '2026-01-05 08:00:00'),
(2, 3, 'Cactus',     'Manolo Fortich', 8.3650000, 124.8700000, '2026-01-06 09:00:00'),
(3, 4, 'Jade Plant', 'Manolo Fortich', 8.3580000, 124.8490000, '2026-01-07 10:00:00');

-- HUMIDITY READINGS  (10 per device)

INSERT INTO humidity (humidity_id, plant_id, humidity_percent, status, recorded_at) VALUES
-- Device 1: Aloe Vera (juan)
(1,  1, 15.00, 'Dry',   '2026-03-01 07:00:00'),
(2,  1, 18.50, 'Dry',   '2026-03-02 07:15:00'),
(3,  1, 35.00, 'Ideal', '2026-03-03 08:00:00'),
(4,  1, 45.00, 'Ideal', '2026-03-04 08:30:00'),
(5,  1, 72.00, 'Humid', '2026-03-05 09:00:00'),
(6,  1, 55.00, 'Ideal', '2026-03-06 08:00:00'),
(7,  1, 12.00, 'Dry',   '2026-03-07 07:45:00'),
(8,  1, 40.00, 'Ideal', '2026-03-08 08:10:00'),
(9,  1, 80.00, 'Humid', '2026-03-09 09:30:00'),
(10, 1, 50.00, 'Ideal', '2026-03-10 08:00:00'),
-- Device 2: Cactus (maria)
(11, 2,  8.00, 'Dry',   '2026-03-01 08:00:00'),
(12, 2, 14.00, 'Dry',   '2026-03-02 07:45:00'),
(13, 2, 38.00, 'Ideal', '2026-03-03 08:30:00'),
(14, 2, 52.00, 'Ideal', '2026-03-04 09:00:00'),
(15, 2, 70.00, 'Humid', '2026-03-05 09:15:00'),
(16, 2, 16.00, 'Dry',   '2026-03-06 07:30:00'),
(17, 2, 44.00, 'Ideal', '2026-03-07 08:00:00'),
(18, 2, 33.00, 'Ideal', '2026-03-08 08:30:00'),
(19, 2, 19.00, 'Dry',   '2026-03-09 07:00:00'),
(20, 2, 61.00, 'Humid', '2026-03-10 09:00:00'),
-- Device 3: Jade Plant (pedro)
(21, 3, 20.00, 'Ideal', '2026-03-01 09:00:00'),
(22, 3, 35.00, 'Ideal', '2026-03-02 08:30:00'),
(23, 3, 90.00, 'Humid', '2026-03-03 10:00:00'),
(24, 3, 62.00, 'Humid', '2026-03-04 09:15:00'),
(25, 3, 15.00, 'Dry',   '2026-03-05 07:30:00'),
(26, 3, 48.00, 'Ideal', '2026-03-06 08:00:00'),
(27, 3, 10.00, 'Dry',   '2026-03-07 07:00:00'),
(28, 3, 55.00, 'Ideal', '2026-03-08 08:30:00'),
(29, 3, 77.00, 'Humid', '2026-03-09 09:00:00'),
(30, 3, 42.00, 'Ideal', '2026-03-10 08:15:00');

-- USER LOGS

INSERT INTO user_logs (user_id, humidity_id) VALUES
(2,1),(2,2),(2,3),(2,4),(2,5),(2,6),(2,7),(2,8),(2,9),(2,10),
(3,11),(3,12),(3,13),(3,14),(3,15),(3,16),(3,17),(3,18),(3,19),(3,20),
(4,21),(4,22),(4,23),(4,24),(4,25),(4,26),(4,27),(4,28),(4,29),(4,30);