<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/QRManager.php';
require_once __DIR__ . '/../../includes/qr_generator.php';

requireAuth();

header('Content-Type: application/json');

$db = Database::getInstance();
$qrManager = new QRManager();
$qrGenerator = new QRGenerator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $sizeCode = $input['size_code'] ?? '';

    if (!$sizeCode) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing size_code']);
        exit;
    }

    $size = $db->fetchOne("SELECT size_code, size_label, variant_qr_data FROM egg_sizes WHERE size_code = ?", [$sizeCode]);
    if (!$size) {
        http_response_code(404);
        echo json_encode(['error' => 'Variant not found']);
        exit;
    }

    if ($size['variant_qr_data'] && empty($input['regenerate'])) {
        $qrData = json_decode($size['variant_qr_data'], true);
    } else {
        $qrData = $qrManager->generateVariantQR($sizeCode);
    }

    $qrUri = $qrGenerator->variantDataUri($sizeCode);

    echo json_encode([
        'success' => true,
        'size_code' => $sizeCode,
        'size_label' => $size['size_label'],
        'qr_data' => $qrData,
        'qr_data_uri' => $qrUri,
        'json_string' => is_string($qrData) ? $qrData : json_encode($qrData)
    ]);
    exit;
}

$sizeCode = $_GET['size_code'] ?? '';

if ($sizeCode) {
    $size = $db->fetchOne("SELECT size_code, size_label, variant_qr_data FROM egg_sizes WHERE size_code = ?", [$sizeCode]);
    if (!$size) {
        http_response_code(404);
        echo json_encode(['error' => 'Variant not found']);
        exit;
    }

    if (!$size['variant_qr_data']) {
        $qrManager->generateVariantQR($sizeCode);
    }

    $qrUri = $qrGenerator->variantDataUri($sizeCode);
    $qrData = $db->fetchOne("SELECT variant_qr_data FROM egg_sizes WHERE size_code = ?", [$sizeCode]);

    echo json_encode([
        'size_code' => $sizeCode,
        'size_label' => $size['size_label'],
        'qr_data_uri' => $qrUri,
        'qr_data' => json_decode($qrData['variant_qr_data'] ?? '{}', true)
    ]);
    exit;
}

$sizes = $db->fetchAll("SELECT size_code, size_label, variant_qr_data, is_active FROM egg_sizes ORDER BY size_code");
$results = [];
foreach ($sizes as $s) {
    if (!$s['variant_qr_data']) {
        $qrManager->generateVariantQR($s['size_code']);
        $s['variant_qr_data'] = $db->fetchOne("SELECT variant_qr_data FROM egg_sizes WHERE size_code = ?", [$s['size_code']])['variant_qr_data'];
    }
    $results[] = [
        'size_code' => $s['size_code'],
        'size_label' => $s['size_label'],
        'is_active' => $s['is_active'],
        'qr_data_uri' => $qrGenerator->variantDataUri($s['size_code']),
        'qr_data' => json_decode($s['variant_qr_data'] ?? '{}', true)
    ];
}

echo json_encode(['sizes' => $results]);
