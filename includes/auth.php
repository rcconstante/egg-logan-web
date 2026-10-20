<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/../classes/KNNPredictor.php';

define('SESSION_TIMEOUT', 3600);
define('POST_LOGIN_DEFAULT', '/pages/dashboard.php');

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'use_strict_mode' => true]);
    }
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">'; }
function csrfRequestIsValid(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    return is_string($token) && hash_equals(csrfToken(), $token);
}
function requirePostRequest(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405); header('Allow: POST'); header('Content-Type: application/json'); echo json_encode(['error' => 'Use POST for this action']); exit;
    }
}

function appBaseUrl() {
    static $base = null;
    if ($base === null) {
        $base = '';
        $docRoot = str_replace('\\', '/', rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '', '/'));
        $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
        if ($docRoot !== '' && $appRoot !== '' && strpos($appRoot, $docRoot) === 0) {
            $base = rtrim(substr($appRoot, strlen($docRoot)), '/');
        }
    }
    return $base;
}

function appRedirect($path = '') {
    header('Location: ' . appBaseUrl() . $path);
    exit;
}

function rememberRequestedPage() {
    startSession();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || empty($_SERVER['REQUEST_URI'])) {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'];
    if (stripos($uri, 'logout') !== false) {
        return;
    }
    $_SESSION['redirect_after_login'] = $uri;
}

function consumeRedirectAfterLogin($defaultPath = POST_LOGIN_DEFAULT) {
    startSession();
    if (!empty($_SESSION['redirect_after_login'])) {
        $target = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
        if (is_string($target) && $target !== '' && $target[0] === '/' && strpos($target, '//') !== 0 && strpos($target, '\\') === false) {
            return $target;
        }
    }
    return appBaseUrl() . $defaultPath;
}

function loginUser($username, $password) {
    if (!is_string($username) || !is_string($password)) return false;
    $pdo = DatabaseConnection::connect();
    $stmt = $pdo->prepare("SELECT * FROM staff_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        startSession();
        if (empty($user['is_approved'])) { $_SESSION['auth_notice'] = 'pending_approval'; return false; }
        session_regenerate_id(true);
        unset($_SESSION['auth_notice']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['credential_version'] = hash('sha256', $user['password_hash']);
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        logActivity($user['id'], 'login', 'User logged in');

        // Auto-retrain check on login (non-blocking)
        try {
            $predictor = new KNNPredictor();
            $predictor->runScheduledRetrainIfDue();
        } catch (Throwable $e) {
            error_log('Auto-retrain on login skipped: ' . $e->getMessage());
        }

        return $user;
    }
    return false;
}

function signupUser($username, $password, $fullName) {
    [$username, $password, $fullName] = validateAccountFields($username, $password, $fullName);
    $pdo = DatabaseConnection::connect();
    $existing = $pdo->prepare('SELECT id FROM staff_users WHERE username = ?');
    $existing->execute([$username]);
    if ($existing->fetch()) throw new InvalidArgumentException('Username already exists.');
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO staff_users (username, password_hash, full_name, role, is_active, is_approved) VALUES (?, ?, ?, 'staff', 1, 0)");
    $stmt->execute([$username, $hashed, $fullName]);
    return $pdo->lastInsertId();
}

function checkSession() {
    startSession();

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        logoutUser();
        return false;
    }

    // Check if user is approved
    $pdo = DatabaseConnection::connect();
    $stmt = $pdo->prepare("SELECT is_approved, is_active, role, username, full_name, password_hash FROM staff_users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_approved'] || !$user['is_active']) {
        $pendingApproval = ($user && !$user['is_approved']);
        logoutUser();
        if ($pendingApproval) {
            startSession();
            $_SESSION['auth_notice'] = 'pending_approval';
        }
        return false;
    }

    $version = hash('sha256', $user['password_hash']);
    if (isset($_SESSION['credential_version']) && !hash_equals($_SESSION['credential_version'], $version)) { logoutUser(); return false; }
    $_SESSION['credential_version'] = $version;
    $_SESSION['role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['last_activity'] = time();
    return true;
}

function logoutUser() {
    startSession();
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'User logged out');
    }
    $_SESSION = [];
    session_destroy();
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function requireAuth() {
    if (!checkSession()) {
        $apiRequest = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['print_receipt.php', 'print_labels.php'], true);
        $isAjax = $apiRequest || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
        } else {
            rememberRequestedPage();
            appRedirect('/index.php?next=1');
        }
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrfRequestIsValid()) {
        http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'Your form session expired. Reload the page and try again.']); exit;
    }
}

function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: Admin only']);
        exit;
    }
}

function requirePageAdmin() {
    requireAuth();
    if (!isAdmin()) {
        appRedirect('/pages/dashboard.php');
    }
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function getCurrentUserName() {
    return $_SESSION['full_name'] ?? 'Unknown';
}

function logActivity($userId, $action, $details = '') {
    try {
        $pdo = DatabaseConnection::connect();
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (Exception $e) {
        error_log("Activity log failed: " . $e->getMessage());
    }
}
