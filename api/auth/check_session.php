<?php
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (checkSession()) {
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'name' => $_SESSION['full_name'],
            'role' => $_SESSION['role']
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
}
