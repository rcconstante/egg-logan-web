-- Migration: Add verification fields to egg_trays
-- Run this SQL on your existing database to add the verification feature

ALTER TABLE `egg_trays`
    ADD COLUMN `verified_at` DATETIME DEFAULT NULL AFTER `last_scanned_by`,
    ADD COLUMN `verified_by` INT DEFAULT NULL AFTER `verified_at`;
