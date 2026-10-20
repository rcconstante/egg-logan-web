-- Migration: Link sales_history rows to the receipt that produced them
-- Lets a voided receipt remove its sales rows from reporting and KNN training data

ALTER TABLE sales_history
    ADD COLUMN receipt_id INT DEFAULT NULL AFTER `year`,
    ADD INDEX `idx_sales_receipt` (`receipt_id`),
    ADD CONSTRAINT `fk_sales_receipt`
        FOREIGN KEY (`receipt_id`) REFERENCES `receipts`(`id`) ON DELETE SET NULL;