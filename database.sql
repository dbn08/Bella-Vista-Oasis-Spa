CREATE DATABASE IF NOT EXISTS bella_vista_spa
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bella_vista_spa;

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 60,
  price DECIMAL(10,2) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_code VARCHAR(20) NOT NULL UNIQUE,
  customer_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  service_id INT NOT NULL,
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  guests INT NOT NULL DEFAULT 1,
  notes TEXT NULL,
  total_price DECIMAL(10,2) NOT NULL,
  status ENUM('Pending','Confirmed','Cancelled','Completed') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_appointment_service FOREIGN KEY (service_id) REFERENCES services(id)
);

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT 'Swedish Relaxation Massage', 'A soothing full-body massage designed to ease everyday tension and encourage relaxation.', 60, 1200.00, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Swedish Relaxation Massage');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT 'Deep Tissue Therapy', 'Focused massage using firmer pressure to target areas of muscle tightness.', 60, 1500.00, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Deep Tissue Therapy');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT 'Aromatherapy Massage', 'A relaxing massage paired with aromatic oils for a calming spa experience.', 75, 1650.00, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Aromatherapy Massage');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT 'Herbal Body Treatment', 'A restorative body treatment inspired by traditional herbal wellness practices.', 90, 1900.00, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Herbal Body Treatment');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT 'Private Lounge Experience', 'A peaceful private lounge session to complement your wellness visit.', 60, 800.00, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Private Lounge Experience');
