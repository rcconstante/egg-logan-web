-- EggLogan Egg Storage & Inventory Management System
-- Database Schema & Default Data
-- Run this file in phpMyAdmin or via mysql CLI to set up the database

CREATE DATABASE IF NOT EXISTS `egg_storage_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `egg_storage_db`;

-- ============ REFERENCE TABLES ============

CREATE TABLE IF NOT EXISTS `egg_sizes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `size_code` VARCHAR(5) NOT NULL UNIQUE,
    `size_label` VARCHAR(50) NOT NULL,
    `tray_capacity` INT NOT NULL DEFAULT 30,
    `price_per_tray` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `price_per_egg` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `shelf_life` INT NOT NULL DEFAULT 21,
    `category` ENUM('fresh','salted') NOT NULL DEFAULT 'fresh',
    `is_active` TINYINT(1) DEFAULT 1,
    `variant_qr_data` JSON DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `staff_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_approved` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT NOW(),
    `last_login_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ CORE DATA TABLES ============

CREATE TABLE IF NOT EXISTS `batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_number` VARCHAR(50) NOT NULL UNIQUE,
    `production_date` DATE NOT NULL,
    `supplier` VARCHAR(100) DEFAULT NULL,
    `egg_size` VARCHAR(5) NOT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    FOREIGN KEY (`egg_size`) REFERENCES `egg_sizes`(`size_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `egg_trays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tray_id` VARCHAR(36) NOT NULL UNIQUE,
    `qr_code_data` JSON DEFAULT NULL,
    `egg_size` VARCHAR(5) NOT NULL,
    `batch_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 30,
    `production_date` DATE NOT NULL,
    `expiry_date` DATE NOT NULL,
    `tray_sequence` INT DEFAULT NULL,
    `fifo_priority_score` DECIMAL(10,4) DEFAULT 0,
    `status` ENUM('in_stock','sold','damaged','discarded','deleted') DEFAULT 'in_stock',
    `last_scanned_at` DATETIME DEFAULT NULL,
    `last_scanned_by` INT DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `verified_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    `updated_at` DATETIME DEFAULT NOW() ON UPDATE NOW(),
    INDEX `idx_fifo` (`fifo_priority_score`, `status`, `egg_size`),
    INDEX `idx_expiry` (`expiry_date`, `status`),
    FOREIGN KEY (`egg_size`) REFERENCES `egg_sizes`(`size_code`),
    FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ TRANSACTION TABLES ============

CREATE TABLE IF NOT EXISTS `scan_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tray_id` VARCHAR(36) DEFAULT NULL,
    `staff_id` INT DEFAULT NULL,
    `scan_type` VARCHAR(50) DEFAULT NULL,
    `action_taken` TEXT DEFAULT NULL,
    `scanned_at` DATETIME DEFAULT NOW(),
    INDEX `idx_tray` (`tray_id`),
    INDEX `idx_staff` (`staff_id`),
    INDEX `idx_time` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sales_history` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `sale_date` DATE NOT NULL,
    `egg_size` VARCHAR(5) NOT NULL,
    `trays_sold` DECIMAL(10,2) DEFAULT 0,
    `individual_eggs_sold` INT DEFAULT 0,
    `total_amount` DECIMAL(10,2) DEFAULT 0,
    `customer_type` VARCHAR(50) DEFAULT 'retail',
    `day_of_week` INT DEFAULT NULL,
    `is_weekend` TINYINT(1) DEFAULT 0,
    `is_holiday` TINYINT(1) DEFAULT 0,
    `month` INT DEFAULT NULL,
    `year` INT DEFAULT NULL,
    `receipt_id` INT DEFAULT NULL,
    `data_quality` ENUM('exact','good_estimate','rough_estimate') DEFAULT 'exact',
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    INDEX `idx_date` (`sale_date`),
    INDEX `idx_size` (`egg_size`),
    INDEX `idx_size_date` (`egg_size`, `sale_date`),
    INDEX `idx_sales_receipt` (`receipt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `price_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `size_code` VARCHAR(5) NOT NULL,
    `old_price_per_tray` DECIMAL(10,2) DEFAULT NULL,
    `new_price_per_tray` DECIMAL(10,2) DEFAULT NULL,
    `old_price_per_egg` DECIMAL(10,2) DEFAULT NULL,
    `new_price_per_egg` DECIMAL(10,2) DEFAULT NULL,
    `changed_by` INT DEFAULT NULL,
    `changed_at` DATETIME DEFAULT NOW(),
    INDEX `idx_price_history_size` (`size_code`),
    INDEX `idx_price_history_date` (`changed_at`),
    FOREIGN KEY (`size_code`) REFERENCES `egg_sizes`(`size_code`),
    FOREIGN KEY (`changed_by`) REFERENCES `staff_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `receipt_number` VARCHAR(50) NOT NULL UNIQUE,
    `sale_data` JSON DEFAULT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'cash',
    `customer_info` JSON DEFAULT NULL,
    `staff_id` INT DEFAULT NULL,
    `amount_tendered` DECIMAL(10,2) DEFAULT NULL,
    `change_amount` DECIMAL(10,2) DEFAULT NULL,
    `is_fifo_compliant` TINYINT(1) DEFAULT 1,
    `is_voided` TINYINT(1) DEFAULT 0,
    `void_reason` TEXT DEFAULT NULL,
    `voided_by` INT DEFAULT NULL,
    `voided_at` DATETIME DEFAULT NULL,
    `printed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    INDEX `idx_receipt_date` (`created_at`),
    INDEX `idx_receipt_staff` (`staff_id`),
    FOREIGN KEY (`staff_id`) REFERENCES `staff_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `receipt_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `receipt_id` INT NOT NULL,
    `tray_id` VARCHAR(36) DEFAULT NULL,
    `egg_size` VARCHAR(5) NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `subtotal` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`receipt_id`) REFERENCES `receipts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ ML / PREDICTION TABLES ============

CREATE TABLE IF NOT EXISTS `knn_predictions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `prediction_date` DATE NOT NULL,
    `egg_size` VARCHAR(5) NOT NULL,
    `predicted_trays` DECIMAL(10,2) DEFAULT NULL,
    `predicted_sales` DECIMAL(10,2) DEFAULT NULL,
    `confidence_lower` DECIMAL(10,2) DEFAULT NULL,
    `confidence_upper` DECIMAL(10,2) DEFAULT NULL,
    `confidence_level` VARCHAR(10) DEFAULT 'low',
    `actual_trays_sold` DECIMAL(10,2) DEFAULT NULL,
    `is_override` TINYINT(1) DEFAULT 0,
    `overridden_by` INT DEFAULT NULL,
    `model_version` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    `updated_at` DATETIME DEFAULT NOW() ON UPDATE NOW(),
    UNIQUE KEY `idx_pred_unique` (`prediction_date`, `egg_size`),
    INDEX `idx_pred_date` (`prediction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `model_versions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `version_name` VARCHAR(50) NOT NULL,
    `training_date` DATETIME DEFAULT NULL,
    `training_samples` INT DEFAULT 0,
    `optimal_k_value` INT DEFAULT 5,
    `mape_score` DECIMAL(10,2) DEFAULT NULL,
    `features_used` JSON DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ SYSTEM TABLES ============

CREATE TABLE IF NOT EXISTS `system_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `alert_type` ENUM('expiring','low_stock','fifo_violation','system') DEFAULT 'system',
    `severity` ENUM('low','medium','high') DEFAULT 'medium',
    `message` TEXT NOT NULL,
    `reference_id` VARCHAR(50) DEFAULT NULL,
    `is_resolved` TINYINT(1) DEFAULT 0,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `legacy_data_import` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `import_type` VARCHAR(50) NOT NULL,
    `import_data` JSON DEFAULT NULL,
    `records_count` INT DEFAULT 0,
    `data_quality` VARCHAR(50) DEFAULT 'good_estimate',
    `imported_by` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT 0,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    INDEX `idx_activity_user` (`user_id`),
    INDEX `idx_activity_action` (`action`),
    INDEX `idx_activity_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `damaged_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tray_id` VARCHAR(36) DEFAULT NULL,
    `quantity` INT NOT NULL,
    `issue_type` VARCHAR(50) DEFAULT 'other',
    `reported_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    INDEX `idx_damage_tray` (`tray_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT NOW(),
    `updated_at` DATETIME DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ DEFAULT DATA ============

INSERT IGNORE INTO `egg_sizes` (`size_code`, `size_label`, `tray_capacity`, `price_per_tray`, `price_per_egg`, `shelf_life`, `category`) VALUES
('SS', 'Super Small', 30, 180.00, 6.00, 21, 'fresh'),
('SM', 'Small', 30, 210.00, 7.00, 21, 'fresh'),
('MD', 'Medium', 30, 240.00, 8.00, 21, 'fresh'),
('LG', 'Large', 30, 270.00, 9.00, 21, 'fresh'),
('XL', 'Extra Large', 24, 240.00, 10.00, 21, 'fresh'),
('JB', 'Jumbo', 24, 288.00, 12.00, 21, 'fresh'),
('SA', 'Salted', 30, 360.00, 12.00, 45, 'salted');

-- Create the first administrator through the signup/bootstrap steps in README.md.
-- No default accounts or passwords are distributed.

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('business_name', 'EggLogan Egg Storage'),
('business_address', 'Philippines'),
('alert_expiry_days', '3'),
('low_stock_threshold', '0.5'),
('auto_retrain', '1'),
('receipt_footer', 'Thank you for your patronage!'),
('timezone', 'Asia/Manila');

-- ============ VIEWS ============

CREATE OR REPLACE VIEW `vw_current_inventory` AS
SELECT s.size_code, s.size_label, s.category, s.tray_capacity,
       COUNT(t.id) AS tray_count, COALESCE(SUM(t.quantity), 0) AS total_eggs,
       MIN(t.production_date) AS oldest_batch,
       MAX(t.expiry_date) AS latest_expiry,
       MIN(DATEDIFF(t.expiry_date, NOW())) AS min_days_until_expiry,
       s.price_per_tray, s.price_per_egg
FROM egg_sizes s
LEFT JOIN egg_trays t ON s.size_code = t.egg_size AND t.status = 'in_stock'
GROUP BY s.size_code, s.size_label, s.category, s.tray_capacity, s.price_per_tray, s.price_per_egg;

CREATE OR REPLACE VIEW `vw_fifo_pick_order` AS
SELECT t.*, b.batch_number,
       s.size_label, s.tray_capacity, s.category,
       DATEDIFF(NOW(), t.production_date) AS days_in_storage,
       DATEDIFF(t.expiry_date, NOW()) AS days_until_expiry
FROM egg_trays t
JOIN batches b ON t.batch_id = b.id
JOIN egg_sizes s ON t.egg_size = s.size_code
WHERE t.status = 'in_stock'
ORDER BY
    t.fifo_priority_score DESC,
    t.expiry_date ASC;

CREATE OR REPLACE VIEW `vw_daily_sales_summary` AS
SELECT DATE(r.created_at) AS sale_date,
       COUNT(DISTINCT r.id) AS receipt_count,
       COALESCE(SUM(r.total_amount), 0) AS total_sales,
       COALESCE(AVG(r.total_amount), 0) AS avg_sale,
       COUNT(DISTINCT r.staff_id) AS active_staff
FROM receipts r
WHERE r.is_voided = 0
GROUP BY DATE(r.created_at);

-- ============ STORED PROCEDURES ============

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `sp_calculate_fifo_scores`()
BEGIN
    UPDATE egg_trays t
    JOIN egg_sizes s ON t.egg_size = s.size_code
    SET t.fifo_priority_score = (
        (0.5 * (DATEDIFF(NOW(), t.production_date) / GREATEST(s.shelf_life, 1))) +
        (0.3 * (1.0 / GREATEST((SELECT COUNT(*) FROM egg_trays WHERE batch_id = t.batch_id AND id <= t.id), 1))) +
        (0.2 * 0.5)
    )
    WHERE t.status = 'in_stock';
END //

CREATE PROCEDURE IF NOT EXISTS `sp_get_daily_distribution`(IN p_date DATE)
BEGIN
    SELECT
        s.size_code, s.size_label,
        COALESCE(SUM(ri.quantity), 0) AS eggs_sold,
        COALESCE(COUNT(DISTINCT r.id), 0) AS transaction_count,
        COALESCE(SUM(ri.subtotal), 0) AS total_revenue
    FROM egg_sizes s
    LEFT JOIN receipt_items ri ON s.size_code = ri.egg_size
    LEFT JOIN receipts r ON ri.receipt_id = r.id AND DATE(r.created_at) = p_date AND r.is_voided = 0
    GROUP BY s.size_code, s.size_label
    ORDER BY total_revenue DESC;
END //

DELIMITER ;

-- ============ SCHEDULED EVENTS ============

SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS `daily_fifo_update`
ON SCHEDULE EVERY 1 DAY STARTS CURRENT_TIMESTAMP + INTERVAL 1 DAY + INTERVAL 2 HOUR
DO
CALL sp_calculate_fifo_scores();

CREATE EVENT IF NOT EXISTS `daily_expiry_alerts`
ON SCHEDULE EVERY 1 DAY STARTS CURRENT_TIMESTAMP + INTERVAL 1 DAY + INTERVAL 3 HOUR
DO
INSERT INTO system_alerts (alert_type, severity, message, reference_id)
SELECT 'expiring',
       CASE WHEN DATEDIFF(t.expiry_date, NOW()) <= 0 THEN 'high' ELSE 'medium' END,
       CONCAT('Tray ', t.tray_id, ' (', s.size_label, ') expires in ', DATEDIFF(t.expiry_date, NOW()), ' days'),
       t.tray_id
FROM egg_trays t
JOIN egg_sizes s ON t.egg_size = s.size_code
WHERE t.status = 'in_stock'
  AND DATEDIFF(t.expiry_date, NOW()) BETWEEN 0 AND 3
  AND NOT EXISTS (
      SELECT 1 FROM system_alerts a
      WHERE a.alert_type = 'expiring' AND a.reference_id = t.tray_id AND a.is_resolved = 0
  );

