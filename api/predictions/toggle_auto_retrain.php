<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';

header('Content-Type: application/json');
requireAdmin();
requirePostRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$db = Database::getInstance();
$current = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_retrain'");

// Seed with the default first so there is always a row to read back, otherwise a
// plain UPDATE would touch 0 rows and report success while nothing was stored.
$db->insert(
    "INSERT INTO system_settings (setting_key, setting_value) VALUES ('auto_retrain', '1')
     ON DUPLICATE KEY UPDATE setting_key = setting_key"
);

$newValue = ($current['setting_value'] ?? '1') == '1' ? '0' : '1';

$db->update(
    "UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'auto_retrain'",
    [$newValue]
);

// Read the stored value back rather than trusting the toggle, so the button can
// never report a state the database disagrees with.
$stored = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_retrain'");
$isOn = ($stored['setting_value'] ?? '0') == '1';

logActivity(getCurrentUserId(), 'toggle_auto_retrain', 'Auto-retrain set to ' . ($isOn ? 'ON' : 'OFF'));

echo json_encode(['success' => true, 'auto_retrain' => $isOn, 'setting' => $stored['setting_value'] ?? '0']);