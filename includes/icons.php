<?php

/** Shared local Hugeicons renderer for the storefront and authenticated pages. */
function e_icon(string $name, int $size = 18, string $class = ''): string
{
    static $icons = [];
    static $allowed = ['egg', 'tray', 'box', 'receipt', 'users', 'clock', 'chart', 'bell', 'trending-up', 'location', 'phone', 'facebook', 'leaf', 'cart', 'dashboard', 'scan', 'clipboard', 'settings', 'upload', 'logout', 'alert-triangle', 'banknote', 'check-circle', 'printer', 'calendar', 'map-pin', 'shield', 'chevron-right', 'chevron-left', 'profile', 'menu', 'close', 'eye', 'eye-off', 'info', 'plus', 'star', 'save', 'trash', 'key', 'more'];
    if (!in_array($name, $allowed, true)) {
        return '';
    }
    if (!isset($icons[$name])) {
        $svg = file_get_contents(__DIR__ . '/../assets/icons/hugeicons/' . $name . '.svg');
        if ($svg === false) {
            throw new RuntimeException('UI icon asset is unavailable.');
        }
        $icons[$name] = trim($svg);
    }
    $classAttr = htmlspecialchars('icon' . ($class !== '' ? ' ' . $class : ''), ENT_QUOTES, 'UTF-8');
    $size = max(1, min(256, $size));
    return str_replace('<svg ', sprintf('<svg class="%s" width="%d" height="%d" ', $classAttr, $size, $size), $icons[$name]);
}
