<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/validation.php';
class SettingsManager {
    private $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public function update(array $data, int $actor): void {
        $action = $data['action'] ?? '';
        if ($action === 'update_price' || $action === 'update_shelf_life') {
            $size = $data['size_code'] ?? '';
            if (!is_string($size) || !$this->db->fetchOne('SELECT size_code FROM egg_sizes WHERE size_code = ?', [$size])) throw new InvalidArgumentException('Unknown egg size.');
            if ($action === 'update_shelf_life') {
                $days = positiveInteger($data['shelf_life'] ?? null, 'Shelf life', 3650);
                $this->db->update('UPDATE egg_sizes SET shelf_life = ? WHERE size_code = ?', [$days, $size]); return;
            }
            $tray = nonNegativeAmount($data['price_per_tray'] ?? null, 'Price per tray');
            $egg = nonNegativeAmount($data['price_per_egg'] ?? null, 'Price per egg');
            $this->db->beginTransaction();
            try {
                $old = $this->db->fetchOne('SELECT price_per_tray, price_per_egg FROM egg_sizes WHERE size_code = ? FOR UPDATE', [$size]);
                $this->db->update('UPDATE egg_sizes SET price_per_tray = ?, price_per_egg = ? WHERE size_code = ?', [$tray, $egg, $size]);
                $this->db->insert('INSERT INTO price_history (size_code, old_price_per_tray, new_price_per_tray, old_price_per_egg, new_price_per_egg, changed_by) VALUES (?, ?, ?, ?, ?, ?)', [$size, $old['price_per_tray'], $tray, $old['price_per_egg'], $egg, $actor]);
                $this->db->commit(); return;
            } catch (Throwable $error) { if ($this->db->getPdo()->inTransaction()) $this->db->rollback(); throw $error; }
        }
        if ($action !== 'update_setting') throw new InvalidArgumentException('Unknown settings action.');
        $key = $data['setting_key'] ?? '';
        $value = $data['setting_value'] ?? null;
        if (!is_string($key) || !is_string($value) || !$this->db->fetchOne('SELECT setting_key FROM system_settings WHERE setting_key = ?', [$key])) throw new InvalidArgumentException('Unknown system setting.');
        if (strlen($value) > 500) throw new InvalidArgumentException('Setting value is too long.');
        switch ($key) {
            case 'alert_expiry_days': if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0 || (int)$value > 365) throw new InvalidArgumentException('Expiry warning must be 0–365 days.'); break;
            case 'low_stock_threshold': if (!is_numeric($value) || (float)$value < .1 || (float)$value > 1) throw new InvalidArgumentException('Stock threshold must be between 0.1 and 1.'); break;
            case 'auto_retrain': if (!in_array($value, ['0', '1'], true)) throw new InvalidArgumentException('Auto retrain must be 0 or 1.'); break;
            case 'timezone': if (!in_array($value, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Choose a valid timezone.'); break;
            case 'business_name': case 'business_address': case 'receipt_footer': if (trim($value) === '') throw new InvalidArgumentException('This setting cannot be empty.'); break;
            default: throw new InvalidArgumentException('This setting is not editable here.');
        }
        $this->db->update('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    }
}
