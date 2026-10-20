<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/BackupManager.php';
header('Content-Type: application/json');
requireAdmin();
requirePostRequest();
try {
    $filename = (new BackupManager())->create();
    logActivity(getCurrentUserId(), 'backup', 'Created private database backup: ' . $filename);
    echo json_encode(['success' => true, 'filename' => $filename]);
} catch (Throwable $error) { error_log('Backup failed: ' . $error->getMessage()); http_response_code(500); echo json_encode(['error' => 'Backup could not be created. Check the private backup directory.']); }
