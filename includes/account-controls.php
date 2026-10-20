<?php
require_once __DIR__ . '/auth.php';
$accountFullName = trim(getCurrentUserName());
$accountFirstName = preg_split('/\s+/u', $accountFullName, 2)[0] ?? '';
?>
<div class="account-controls">
    <button type="button" class="account-trigger" aria-expanded="false" aria-controls="accountMenu" aria-label="Account options for <?= htmlspecialchars($accountFirstName, ENT_QUOTES, 'UTF-8') ?>">
        <span class="account-avatar" aria-hidden="true"><?= e_icon('profile', 24) ?></span>
        <span class="account-details">
            <span class="account-name" title="<?= htmlspecialchars($accountFullName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($accountFirstName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="account-role"><?= htmlspecialchars(ucfirst(getCurrentUserRole()), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <span class="account-chevron" aria-hidden="true"><?= e_icon('chevron-right', 14) ?></span>
    </button>
    <div class="account-menu" id="accountMenu" hidden>
        <form method="POST" action="../api/auth/logout.php"><?= csrfField() ?><button type="submit" class="account-logout"><?= e_icon('logout', 18) ?><span>Logout</span></button></form>
    </div>
</div>
