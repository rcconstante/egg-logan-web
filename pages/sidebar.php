<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
$isAdmin = isAdmin();

$sidebarDb = Database::getInstance();
$expiredCount = (int)($sidebarDb->fetchOne("SELECT COUNT(*) as cnt FROM egg_trays WHERE status = 'in_stock' AND expiry_date < CURDATE()")['cnt'] ?? 0);

$current = basename($_SERVER['PHP_SELF']);
function isActive($page, $current) { return $current === $page ? 'active' : ''; }
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar-header">
    <a href="../index.php?view=home" class="sidebar-brand" aria-label="Egglogan home">
        <img class="sidebar-wordmark" src="../assets/images/header-logo.png" alt="Egglogan — Quality eggs. Brighter days." width="2172" height="724">
        <img class="sidebar-brand-icon" src="../assets/images/favicon-icon.png" alt="" width="48" height="48">
    </a>
</div>
<ul class="nav-list">
<?php
$navigation = [
    ['dashboard.php', 'dashboard', 'Dashboard'],
    ['inventory.php', 'tray', 'Inventory'],
    ['scan.php', 'scan', 'Scan QR'],
    ['sale.php', 'cart', 'Quick Sale'],
    ['picklist.php', 'clipboard', 'Pick List'],
    ['predictions.php', 'trending-up', 'Predictions'],
    ['reports.php', 'chart', 'Reports'],
    ['receipts.php', 'receipt', 'Receipts'],
];
if ($isAdmin) {
    $navigation[] = ['staff.php', 'users', 'Staff'];
    $navigation[] = ['settings.php', 'settings', 'Settings'];
}
foreach ($navigation as [$page, $icon, $label]):
    if ($page === 'staff.php'):
?>
    <li class="nav-divider"><span class="divider-line"></span><span class="nav-label">Administration</span></li>
<?php endif; ?>
    <li><a href="<?= $page ?>" class="<?= isActive($page, $current) ?>" aria-label="<?= $label ?>" title="<?= $label ?>"<?= $page === $current ? ' aria-current="page"' : '' ?>><span class="nav-ico"><?= e_icon($icon, 20) ?></span><span class="nav-label"><?= $label ?></span><?php if ($page === 'inventory.php' && $expiredCount > 0): ?><span class="nav-badge nav-badge-alert" title="<?= $expiredCount ?> expired trays"><?= $expiredCount ?></span><?php endif; ?></a></li>
<?php endforeach; ?>
</ul>
<div class="sidebar-footer">
    <button type="button" id="sidebarResizeBtn" class="sidebar-resize" aria-label="Minimize sidebar" aria-controls="sidebar" aria-expanded="true" title="Minimize sidebar"><?= e_icon('chevron-left', 20) ?></button>
</div>
<button type="button" class="hamburger hamburger-close" id="sidebarCloseBtn" aria-label="Close navigation"><?= e_icon('close', 24) ?></button>
