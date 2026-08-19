-- Create the database
-- WARNING: this destroys any existing dfms database. Only run this for a fresh install.
DROP DATABASE IF EXISTS dfms;
CREATE DATABASE IF NOT EXISTS dfms;
USE dfms;

-- ------------------------------
-- Table: admin_users
-- ------------------------------
CREATE TABLE admin_users (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'veterinarian', 'staff') NOT NULL DEFAULT 'staff'
);

-- Demo accounts are NOT seeded here because passwords must be hashed with
-- PHP's password_hash(), which this plain SQL script cannot compute.
-- After importing this file, run: php database/seed_admin.php

-- ------------------------------
-- Table: cows
-- ------------------------------
CREATE TABLE cows (
    cow_id INT AUTO_INCREMENT PRIMARY KEY,
    unique_id VARCHAR(50) NOT NULL UNIQUE,
    date_of_birth DATE,
    notes TEXT
);

-- Insert query for cows
INSERT INTO cows (unique_id, date_of_birth, notes) VALUES ('COW123', '2020-01-01', 'Healthy cow');
INSERT INTO cows (unique_id, date_of_birth, notes) VALUES ('BD001', '2021-03-15', 'Local breed - Pabna');
INSERT INTO cows (unique_id, date_of_birth, notes) VALUES ('BD002', '2021-05-20', 'Cross breed - Sahiwal');
INSERT INTO cows (unique_id, date_of_birth, notes) VALUES ('BD003', '2022-01-10', 'Local breed - Red Chittagong');
INSERT INTO cows (unique_id, date_of_birth, notes) VALUES ('BD004', '2022-02-28', 'Cross breed - Holstein Friesian');
INSERT INTO cows (unique_id, date_of_birth, notes) VALUES ('BD005', '2022-04-15', 'Local breed - North Bengal Grey');

-- ------------------------------
-- Table: health_events
-- ------------------------------
CREATE TABLE health_events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    cow_id INT NOT NULL,
    event_date DATE NOT NULL,
    event_type VARCHAR(50),
    description TEXT,
    FOREIGN KEY (cow_id) REFERENCES cows(cow_id)
);

-- Insert query for health_events
INSERT INTO health_events (cow_id, event_date, event_type, description) VALUES (1, '2023-10-01', 'Vaccination', 'Annual vaccination');
INSERT INTO health_events (cow_id, event_date, event_type, description) VALUES (2, '2023-09-15', 'Deworming', 'Regular deworming treatment');
INSERT INTO health_events (cow_id, event_date, event_type, description) VALUES (3, '2023-09-20', 'Check-up', 'Routine health check-up');
INSERT INTO health_events (cow_id, event_date, event_type, description) VALUES (4, '2023-10-05', 'Treatment', 'Foot and Mouth Disease vaccination');

-- ------------------------------
-- Table: feeding_schedules
-- ------------------------------
CREATE TABLE feeding_schedules (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    feed_time TIME NOT NULL,
    feed_amount DECIMAL(10,2),
    feed_type VARCHAR(50)
);

-- Insert query for feeding_schedules
INSERT INTO feeding_schedules (feed_time, feed_amount, feed_type) VALUES ('08:00:00', 5.00, 'Hay');
INSERT INTO feeding_schedules (feed_time, feed_amount, feed_type) VALUES ('14:00:00', 4.00, 'Green Grass');
INSERT INTO feeding_schedules (feed_time, feed_amount, feed_type) VALUES ('19:00:00', 3.00, 'Concentrate Feed');
INSERT INTO feeding_schedules (feed_time, feed_amount, feed_type) VALUES ('06:00:00', 2.00, 'Wheat Bran');

-- ------------------------------
-- Table: feed_consumption
-- ------------------------------
CREATE TABLE feed_consumption (
    consumption_id INT AUTO_INCREMENT PRIMARY KEY,
    consumption_date DATE NOT NULL,
    total_feed_consumed DECIMAL(10,2)
);

-- Insert query for feed_consumption
INSERT INTO feed_consumption (consumption_date, total_feed_consumed) VALUES ('2023-10-01', 50.00);
INSERT INTO feed_consumption (consumption_date, total_feed_consumed) VALUES ('2023-10-02', 48.50);
INSERT INTO feed_consumption (consumption_date, total_feed_consumed) VALUES ('2023-10-03', 52.00);

-- ------------------------------
-- Table: milk_production
-- ------------------------------
CREATE TABLE milk_production (
    production_id INT AUTO_INCREMENT PRIMARY KEY,
    production_date DATE NOT NULL,
    total_milk_yield DECIMAL(10,2)
);

-- Insert query for milk_production
INSERT INTO milk_production (production_date, total_milk_yield) VALUES ('2023-10-01', 100.00);
INSERT INTO milk_production (production_date, total_milk_yield) VALUES ('2023-10-02', 95.50);
INSERT INTO milk_production (production_date, total_milk_yield) VALUES ('2023-10-03', 98.75);
INSERT INTO milk_production (production_date, total_milk_yield) VALUES ('2023-10-04', 102.25);

-- ------------------------------
-- Table: inventory_items
-- ------------------------------
CREATE TABLE inventory_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(100) NOT NULL,
    item_type VARCHAR(50),
    unit VARCHAR(50)
);

-- Insert query for inventory_items
INSERT INTO inventory_items (item_name, item_type, unit) VALUES ('Antibiotic', 'Medicine', 'Bottle');
INSERT INTO inventory_items (item_name, item_type, unit) VALUES ('Vitamin Supplement', 'Medicine', 'Pack');
INSERT INTO inventory_items (item_name, item_type, unit) VALUES ('Cattle Feed', 'Feed', 'Kg');
INSERT INTO inventory_items (item_name, item_type, unit) VALUES ('Hay', 'Feed', 'Bundle');
INSERT INTO inventory_items (item_name, item_type, unit) VALUES ('Mineral Mix', 'Supplement', 'Kg');

-- ------------------------------
-- Table: inventory_levels
-- ------------------------------
CREATE TABLE inventory_levels (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id)
);

-- Insert query for inventory_levels
INSERT INTO inventory_levels (item_id, quantity) VALUES (1, 20.00);
INSERT INTO inventory_levels (item_id, quantity) VALUES (2, 50.00);
INSERT INTO inventory_levels (item_id, quantity) VALUES (3, 500.00);
INSERT INTO inventory_levels (item_id, quantity) VALUES (4, 100.00);
INSERT INTO inventory_levels (item_id, quantity) VALUES (5, 25.00);

-- ------------------------------
-- Table: temperature_readings
-- ------------------------------
CREATE TABLE temperature_readings (
    reading_id INT AUTO_INCREMENT PRIMARY KEY,
    reading_time DATETIME NOT NULL,
    temperature DECIMAL(5,2)
);

-- Insert query for temperature_readings
INSERT INTO temperature_readings (reading_time, temperature) VALUES ('2023-10-01 08:00:00', 36.5);
INSERT INTO temperature_readings (reading_time, temperature) VALUES ('2023-10-01 14:00:00', 37.2);
INSERT INTO temperature_readings (reading_time, temperature) VALUES ('2023-10-01 20:00:00', 36.8);
INSERT INTO temperature_readings (reading_time, temperature) VALUES ('2023-10-02 08:00:00', 36.7);

-- ------------------------------
-- Table: alerts
-- ------------------------------
CREATE TABLE alerts (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    alert_time DATETIME NOT NULL,
    alert_type VARCHAR(50),
    description TEXT,
    is_read TINYINT(1) NOT NULL DEFAULT 0
);

-- Insert query for alerts
INSERT INTO alerts (alert_time, alert_type, description) VALUES ('2023-10-01 09:00:00', 'Low Inventory', 'Antibiotic stock is low');
INSERT INTO alerts (alert_time, alert_type, description) VALUES ('2023-10-02 10:30:00', 'Health Check', 'Routine vaccination due for BD001');
INSERT INTO alerts (alert_time, alert_type, description) VALUES ('2023-10-02 15:45:00', 'Feed Stock', 'Cattle feed stock below threshold');
INSERT INTO alerts (alert_time, alert_type, description) VALUES ('2023-10-03 08:15:00', 'Temperature Alert', 'Cow BD003 showing high temperature');
