<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/validation.php';
class StaffManager {
    private $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public function create(array $data): int {
        [$username, $password, $name] = validateAccountFields($data['username'] ?? null, $data['password'] ?? null, $data['full_name'] ?? null);
        $role = $data['role'] ?? 'staff';
        if (!in_array($role, ['admin', 'staff'], true)) throw new InvalidArgumentException('Invalid account role.');
        if ($this->db->fetchOne('SELECT id FROM staff_users WHERE username = ?', [$username])) throw new InvalidArgumentException('Username already exists.');
        return (int)$this->db->insert('INSERT INTO staff_users (username, password_hash, full_name, role, is_active, is_approved, created_at) VALUES (?, ?, ?, ?, 1, 1, NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), $name, $role]);
    }
    public function toggleStatus(int $id, int $actor, bool $approve = false): int {
        if ($id === $actor) throw new InvalidArgumentException('You cannot deactivate your own account.');
        $this->db->beginTransaction();
        try {
            $admins = $this->db->fetchAll("SELECT id FROM staff_users WHERE role = 'admin' AND is_active = 1 AND is_approved = 1 ORDER BY id FOR UPDATE");
            $user = $this->db->fetchOne('SELECT id, role, is_active, is_approved FROM staff_users WHERE id = ? FOR UPDATE', [$id]);
            if (!$user) throw new InvalidArgumentException('User not found.');
            if (!$user['is_approved'] && !$approve) throw new InvalidArgumentException('Approve this account before activating it.');
            $active = $approve ? 1 : ($user['is_active'] ? 0 : 1);
            if (!$active && $user['role'] === 'admin' && count($admins) <= 1) throw new InvalidArgumentException('Keep at least one active administrator.');
            $this->db->update('UPDATE staff_users SET is_active = ?, is_approved = ? WHERE id = ?', [$active, $approve ? 1 : $user['is_approved'], $id]);
            $this->db->commit(); return $active;
        } catch (Throwable $error) { if ($this->db->getPdo()->inTransaction()) $this->db->rollback(); throw $error; }
    }
    public function resetPassword(int $id, $password): void {
        if (!is_string($password) || strlen($password) < 6 || strlen($password) > 72) throw new InvalidArgumentException('Password must be 6–72 characters.');
        if (!$this->db->fetchOne('SELECT id FROM staff_users WHERE id = ?', [$id])) throw new InvalidArgumentException('User not found.');
        $this->db->update('UPDATE staff_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }
}
