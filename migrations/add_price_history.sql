-- Migration: Add price_history table for audit trail of egg price changes
-- Ensures historical transaction data is decoupled from live master prices

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
