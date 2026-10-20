<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AlertManager.php';

requireAuth();

if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json');

$alertManager = new AlertManager();

// Reconciles derived alerts (and prunes the resolved pile) in one step.
$alertManager->refresh();

$alerts = $alertManager->getActiveAlerts();
$count = $alertManager->getAlertCount();
$expiredCount = $alertManager->getExpiredCount();

$iconMap = [
    'expiring' => '&#9888;',
    'low_stock' => '&#9888;',
    'fifo_violation' => '&#9888;',
    'system' => '&#9881;'
];

$severityColor = [
    'high' => '#E74C3C',
    'medium' => '#F39C12',
    'low' => '#3498DB'
];

$html = '';
if (empty($alerts)) {
    $html = '<div class="alert-panel-empty">No active alerts</div>';
} else {
    foreach ($alerts as $alert) {
        $color = $severityColor[$alert['severity']] ?? '#888';
        $icon = $iconMap[$alert['alert_type']] ?? '&#9881;';
        $timeAgo = '';
        if ($alert['created_at']) {
            $diff = time() - strtotime($alert['created_at']);
            if ($diff < 60) $timeAgo = 'just now';
            elseif ($diff < 3600) $timeAgo = floor($diff / 60) . 'm ago';
            elseif ($diff < 86400) $timeAgo = floor($diff / 3600) . 'h ago';
            else $timeAgo = floor($diff / 86400) . 'd ago';
        }
        $link = $alertManager->getAlertLink($alert);
        $html .= '<div class="alert-item alert-' . $alert['severity'] . ($link ? ' alert-clickable' : '') . '" data-alert-id="' . $alert['id'] . '"'
               . ($link ? ' data-href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '') . '>';
        $html .= '<span class="alert-icon" style="color:' . $color . ';">' . $icon . '</span>';
        $html .= '<div class="alert-content">';
        $html .= '<div class="alert-message">' . htmlspecialchars($alert['message']) . '</div>';
        $html .= '<div class="alert-meta"><span class="alert-time">' . $timeAgo . '</span>';
        if ($alert['reference_id']) $html .= ' <span class="alert-ref">' . htmlspecialchars($alert['reference_id']) . '</span>';
        if ($link) $html .= '<div class="alert-meta"><a class="alert-link" href="' . htmlspecialchars($link, ENT_QUOTES) . '">View details</a></div>';
        $html .= '</div></div>';
        $html .= '<button class="alert-dismiss" onclick="event.stopPropagation(); dismissAlert(' . $alert['id'] . ')" title="Dismiss">&times;</button>';
        $html .= '</div>';
    }
}

echo json_encode([
    'success' => true,
    'count' => $count,
    'expired_count' => $expiredCount,
    'html' => $html
]);
