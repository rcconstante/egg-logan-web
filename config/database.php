<?php
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');
class DatabaseConnection {
    private static $instance = null;
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $conn;

    private function __construct() {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->dbname = getenv('DB_NAME') ?: 'egg_storage_db';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        if ($this->conn === null) {
            try {
                $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
                $this->conn = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                if (!getenv('APP_TIMEZONE')) {
                    try {
                        $zone = $this->conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'timezone'")->fetchColumn();
                        if (is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true)) date_default_timezone_set($zone);
                    } catch (PDOException $error) { if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error; }
                }
                $this->conn->exec("SET time_zone = '" . date('P') . "'");
            } catch (PDOException $e) {
                die('Database connection failed: ' . $e->getMessage());
            }
        }
        return $this->conn;
    }

    public static function connect() {
        return self::getInstance()->getConnection();
    }
}
