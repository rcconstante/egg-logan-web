<div class="utility-controls" data-csrf-token="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Open navigation" aria-controls="sidebar"><?= e_icon('menu', 24) ?></button>
    <time class="header-clock" data-header-clock datetime="<?= date('c') ?>"><span data-clock-date><?= date('M j, Y') ?></span><span data-clock-time><?= date('g:i A') ?></span></time>
</div>
