-- =====================================================================
--  Bella Vista Oasis Spa : full updated database (replaces the old one)
--  WARNING: this DELETES the existing "bella_vista_spa" database and
--  all old records so you can start completely fresh.
-- =====================================================================
DROP DATABASE IF EXISTS bella_vista_spa;
CREATE DATABASE bella_vista_spa
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bella_vista_spa;

-- ---------------------------------------------------------------------
--  Admins
-- ---------------------------------------------------------------------
CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default login:  admin / Admin@12345   (change it after your first login)
INSERT INTO admins (username, password_hash)
VALUES ('admin', '$6$bvspa2026salt$FkAdqX5qzeL4mwbY8Lw70kSAQPMDQYHsBJoUopoFfiZdU0r2zMR9uQnBt79J/nKmB1zOv9L6GN/25Ec/OBObO1');

-- ---------------------------------------------------------------------
--  Rooms (Suites & Spaces)
-- ---------------------------------------------------------------------
CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  min_guests INT NOT NULL DEFAULT 1,
  max_guests INT NOT NULL DEFAULT 1,
  duration_minutes INT NOT NULL DEFAULT 60,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  image VARCHAR(255) NULL,                -- e.g. assets/images/rooms/1.jpg
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
--  Services (Treatments)
-- ---------------------------------------------------------------------
CREATE TABLE services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 60,
  price DECIMAL(10,2) NOT NULL,
  image VARCHAR(255) NULL,                -- e.g. assets/images/services/1.jpg
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
--  Packages (Bundles)
-- ---------------------------------------------------------------------
CREATE TABLE packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  room_type VARCHAR(30) NOT NULL DEFAULT 'all', -- 'all', 'single', 'couples', 'family'
  duration_minutes INT NOT NULL DEFAULT 60,
  price DECIMAL(10,2) NOT NULL,
  image VARCHAR(255) NULL,                -- e.g. assets/images/packages/1.jpg
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
--  Optional Add-ons
-- ---------------------------------------------------------------------
CREATE TABLE addons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
--  Appointments
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_code VARCHAR(20) NOT NULL UNIQUE,
  customer_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  service_id INT NULL,                    -- first selected service (NULL if only packages)
  room_id INT NULL,                       -- selected room/suite
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  guests INT NOT NULL DEFAULT 1,
  notes TEXT NULL,
  total_price DECIMAL(10,2) NOT NULL,

  payment_method   ENUM('cash','ewallet','bank') NOT NULL DEFAULT 'cash',
  payment_provider VARCHAR(20) NULL,      -- gcash, maya, bdo, bpi, unionbank
  payment_status   ENUM('unpaid','pending','verified','refunded') NOT NULL DEFAULT 'unpaid',
  amount_due       DECIMAL(10,2) NOT NULL DEFAULT 0,   -- reservation fee / down payment
  reference_no     VARCHAR(40) NULL,
  proof_image      VARCHAR(255) NULL,

  status ENUM('Pending','Confirmed','Cancelled','Completed') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_slot (appointment_date, appointment_time),
  CONSTRAINT fk_appointment_service FOREIGN KEY (service_id)
    REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointment_room FOREIGN KEY (room_id)
    REFERENCES rooms(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------
--  Appointment items (treatments, packages, room fee, add-ons)
-- ---------------------------------------------------------------------
CREATE TABLE appointment_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT NOT NULL,
  item_type ENUM('service','package','room','addon') NOT NULL,
  item_id INT NOT NULL,
  item_name VARCHAR(150) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 0,
  line_total DECIMAL(10,2) NOT NULL,
  guest_no INT NULL,                      -- guest number (1..6) for per-guest treatments
  INDEX idx_appointment (appointment_id),
  CONSTRAINT fk_item_appointment FOREIGN KEY (appointment_id)
    REFERENCES appointments(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------
--  Sample Rooms
-- ---------------------------------------------------------------------
INSERT INTO rooms (name, description, min_guests, max_guests, duration_minutes, price, active) VALUES
('Single Room', 'Private tranquil suite designed for solo relaxation and mindfulness.', 1, 1, 60, 0.00, 1),
('Couples Suite', 'Intimate dual-bed sanctuary with warm ambient lighting for two.', 2, 2, 90, 500.00, 1),
('Family Lounge', 'Spacious private wellness lounge accommodating 3 to 6 guests together.', 3, 6, 120, 1200.00, 1);

-- ---------------------------------------------------------------------
--  Sample Services (Treatments)
-- ---------------------------------------------------------------------
INSERT INTO services (name, description, duration_minutes, price, active) VALUES
('Swedish Relaxation Massage', 'A soothing full-body massage designed to ease everyday tension and encourage relaxation.', 60, 1200.00, 1),
('Deep Tissue Therapy', 'Focused massage using firmer pressure to target areas of muscle tightness.', 60, 1500.00, 1),
('Aromatherapy Massage', 'A relaxing massage paired with aromatic oils for a calming spa experience.', 75, 1650.00, 1),
('Herbal Body Treatment', 'A restorative body treatment inspired by traditional herbal wellness practices.', 90, 1900.00, 1),
('Private Lounge Experience', 'A peaceful private lounge session to complement your wellness visit.', 60, 800.00, 1);

-- ---------------------------------------------------------------------
--  Sample Packages
-- ---------------------------------------------------------------------
INSERT INTO packages (name, description, room_type, duration_minutes, price, active) VALUES
('Relaxation Package', 'Swedish Relaxation Massage and Aromatherapy Massage, a calming duo at a bundled price.', 'all', 135, 2500.00, 1),
('Ultimate Wellness Package', 'Deep Tissue Therapy, Herbal Body Treatment and the Private Lounge Experience for a complete reset.', 'all', 210, 3600.00, 1);

-- ---------------------------------------------------------------------
--  Sample Optional Add-ons
-- ---------------------------------------------------------------------
INSERT INTO addons (name, description, price, active) VALUES
('Hot Stone Enhancement', 'Warm basalt stones placed along key tension points to deepen muscle relaxation.', 350.00, 1),
('Essential Oil Upgrade', 'Custom blend of therapeutic-grade lavender, eucalyptus, or peppermint oils.', 200.00, 1),
('Herbal Foot Soak', 'Soothing warm herbal bath with sea salts and botanical extracts prior to your session.', 250.00, 1);