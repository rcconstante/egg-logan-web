-- Migration: Add variant_qr_data column to egg_sizes for reusable variant-level QR codes
-- Each egg variant (SS, SM, MD, LG, XL, JB, CR, SA) gets one permanent QR code

ALTER TABLE `egg_sizes`
ADD COLUMN `variant_qr_data` JSON DEFAULT NULL AFTER `is_active`;
