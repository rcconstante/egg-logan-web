-- Migration: Add predicted_sales column to knn_predictions
-- This extends the prediction system to include Sales Prediction alongside Tray and Egg count predictions

ALTER TABLE `knn_predictions`
ADD COLUMN `predicted_sales` DECIMAL(10,2) DEFAULT NULL AFTER `predicted_trays`;
