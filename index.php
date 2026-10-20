<?php
require_once __DIR__ . '/includes/auth.php';
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
startSession();

$authNotice = '';
if (!empty($_SESSION['auth_notice'])) {
    $authNotice = $_SESSION['auth_notice'];
    unset($_SESSION['auth_notice']);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfRequestIsValid()) { http_response_code(403); exit('Your form session expired. Reload the page and try again.'); }
$signup = false;

// Handle signup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $fullName = $_POST['full_name'] ?? '';
    if ($username && $password && $fullName) {
        try {
        $id = signupUser($username, $password, $fullName);
        $error = 'Account created pending admin approval. User ID: ' . $id . '. <a href="index.php">Return to home</a>';
        $signup = true;
        } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); } catch (PDOException $exception) { $error = 'The account could not be created. Try a different username.'; }
    } else {
        $error = 'All fields are required';
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['signup'])) {
    $user = loginUser($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($user) {
        header('Location: ' . consumeRedirectAfterLogin());
        exit;
    }
    if (($_SESSION['auth_notice'] ?? '') === 'pending_approval') { $authNotice = 'pending_approval'; unset($_SESSION['auth_notice']); } else { $error = 'Invalid username or password'; }
}

$showPublicHome = $_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['view'] ?? '') === 'home';
if (checkSession() && !$showPublicHome) {
    header('Location: ' . consumeRedirectAfterLogin());
    exit;
}

// ---- Live landing page data ----
$landing = [
    'trays'   => 0,
    'orders'  => 0,
    'eggs'    => 0,
    'sizes_count' => 0,
    'years'   => 1,
    'months'  => null,
    'popular_size' => 'LG',
    'sizes'   => [],
];
$sizeWeightNotes = [
    'SS' => 'Under 42g per egg',
    'SM' => '42&ndash;49g per egg',
    'MD' => '49&ndash;56g per egg',
    'LG' => '56&ndash;63g per egg',
    'XL' => '63&ndash;70g per egg',
    'JB' => '70g+ per egg',
    'SA' => 'Salt-cured specialty',
    'CR' => 'For cooking &amp; baking',
];
$sizeTags = [
    'SS' => 'Budget-Friendly',
    'SM' => 'Everyday Use',
    'MD' => 'Everyday Use',
    'LG' => 'Best Seller',
    'XL' => 'Premium',
    'JB' => 'Maximum Size',
    'SA' => 'Specialty',
    'CR' => 'Value Pick',
];
$sizeImages = ['SS' => 'super-small', 'SM' => 'small', 'MD' => 'medium', 'LG' => 'large', 'XL' => 'extra-large', 'JB' => 'jumbo', 'SA' => 'salted'];

try {
    $pdo = DatabaseConnection::connect();

    $landing['trays'] = (int) $pdo->query("SELECT COUNT(*) FROM egg_trays")->fetchColumn();
    $landing['orders'] = (int) $pdo->query("SELECT COUNT(*) FROM receipts WHERE is_voided = 0")->fetchColumn();
    $landing['eggs'] = (int) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM egg_trays WHERE status = 'in_stock'")->fetchColumn();
    $landing['sizes_count'] = (int) $pdo->query("SELECT COUNT(*) FROM egg_sizes WHERE is_active = 1")->fetchColumn();

    $firstDate = $pdo->query(
        "SELECT MIN(d) FROM (
            SELECT MIN(sale_date) AS d FROM sales_history
            UNION ALL SELECT MIN(DATE(created_at)) FROM receipts
            UNION ALL SELECT MIN(production_date) FROM egg_trays
        ) AS earliest"
    )->fetchColumn();
    if ($firstDate) {
        $daysSince = max(1, (int) floor((time() - strtotime($firstDate)) / 86400));
        $landing['years'] = (int) floor($daysSince / 365.25);
        $landing['months'] = (int) floor($daysSince / 30.44);
    }

    $popular = $pdo->query(
        "SELECT egg_size, COALESCE(SUM(trays_sold), 0) AS sold FROM sales_history GROUP BY egg_size ORDER BY sold DESC LIMIT 1"
    )->fetch();
    if (!empty($popular['sold'])) {
        $landing['popular_size'] = $popular['egg_size'];
    }

    $landing['sizes'] = $pdo->query(
        "SELECT size_code, size_label, category, tray_capacity, price_per_tray, price_per_egg, shelf_life
         FROM egg_sizes
         WHERE is_active = 1
         ORDER BY FIELD(category, 'fresh', 'salted'),
                  FIELD(size_code, 'SS', 'SM', 'MD', 'LG', 'XL', 'JB', 'SA'),
                  size_code"
    )->fetchAll();
} catch (Throwable $e) {
    // Database unavailable: keep the defaults above so the page still renders.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Egg Storage Management</title>
    <link rel="icon" type="image/png" href="assets/images/favicon-icon.png">
    <link rel="apple-touch-icon" href="assets/images/favicon-icon.png">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= filemtime(__DIR__ . '/assets/css/landing.css') ?>">
</head>
<body class="landing-page" id="home">

    <nav class="landing-nav" id="landingNav">
        <div class="landing-nav-inner">
            <a href="index.php?view=home" class="landing-brand">
                <img class="landing-logo" src="assets/images/header-logo.png" alt="Egglogan — Quality eggs. Brighter days." width="2172" height="724">
            </a>
            <button type="button" class="landing-nav-toggle" onclick="toggleNav()" aria-label="Menu" aria-controls="navLinks" aria-expanded="false"><?= e_icon('menu', 24) ?></button>
            <div class="landing-nav-links" id="navLinks">
                <a href="#about">About</a>
                <a href="#features">Features</a>
                <a href="#sizes">Sizes</a>
                <a href="#location">Location</a>
                <button type="button" class="btn btn-primary btn-sm" onclick="openLogin()">Sign In</button>
            </div>
        </div>
    </nav>

    <div class="landing-hero-backdrop">
    <section class="landing-hero">
        <div class="landing-hero-content">
            <span class="landing-eyebrow">Tray egg supplier in Naic, Cavite</span>
            <h1>Fresh Tray Eggs,<br><span>Delivered Daily.</span></h1>
            <p>High-quality, farm-fresh eggs for businesses and homes in Naic, Cavite and nearby areas. From small to jumbo, we&rsquo;ve got the right eggs for your needs.</p>
            <div class="landing-hero-actions">
                <a href="#sizes" class="landing-cta">Browse Egg Sizes <span aria-hidden="true"><?= e_icon('chevron-right', 16) ?></span></a>
                <a href="#about" class="landing-cta-ghost"><?= e_icon('egg', 18) ?> Our Story</a>
            </div>
            <div class="landing-hero-note"><?= e_icon('users', 22) ?><span>For family kitchens, neighborhood stores,<br>and growing local businesses.</span></div>
        </div>
        <div class="landing-hero-art">
            <div class="landing-fresh-badge"><strong>Fresh<br>Daily</strong><span>from Naic,<br>Cavite</span></div>
            <img src="assets/images/asset-3.png" alt="Fresh brown eggs packed in a cardboard tray" width="1708" height="904" fetchpriority="high">
            <span class="landing-handwritten">Good Eggs.<br>Brighter Days.</span>
            <div class="landing-quality-note"><?= e_icon('egg', 24) ?><span>Quality Eggs<br><strong>Stronger Communities</strong></span></div>
        </div>
    </section>

    </div>

    <section class="landing-stats">
        <div class="landing-stats-inner">
            <div class="landing-stat-item">
                <?= e_icon('tray', 30) ?>
                <span class="landing-stat-number"><?= number_format($landing['trays']) ?></span>
                <span class="landing-stat-label">Trays Managed</span>
            </div>
            <div class="landing-stat-item">
                <?= e_icon('receipt', 30) ?>
                <span class="landing-stat-number"><?= number_format($landing['orders']) ?></span>
                <span class="landing-stat-label">Orders Served</span>
            </div>
            <div class="landing-stat-item">
                <?= e_icon('egg', 30) ?>
                <span class="landing-stat-number"><?= number_format($landing['eggs']) ?></span>
                <span class="landing-stat-label">Eggs in Stock</span>
            </div>
            <div class="landing-stat-item">
                <?= e_icon('box', 30) ?>
                <span class="landing-stat-number"><?= number_format($landing['sizes_count']) ?></span>
                <span class="landing-stat-label">Sizes Available</span>
            </div>
        </div>
    </section>

    <section class="landing-about" id="about">
        <div class="landing-about-inner">
            <div class="landing-about-text">
                <span class="landing-eyebrow">Our story</span>
                <h2>Local Eggs.<br>A Stronger Community.</h2>
                <p>EggLogan proudly serves the Naic, Cavite community with quality tray eggs &mdash; bulk orders for restaurants and bakeries plus tray purchases for home cooks.</p>
                <p>Every tray is tracked from delivery to sale using FIFO (First In, First Out) rotation so the eggs you get are always the freshest we have.</p>
                <p>Our system helps us cut waste, keep supply steady, and deliver service you can count on every single day.</p>
                <div class="landing-story-values"><span><?= e_icon('users', 22) ?> Local businesses</span><span><?= e_icon('egg', 22) ?> Fresh, quality eggs</span></div>
                <a href="#location" class="landing-cta-ghost">Get to Know Egglogan <span aria-hidden="true"><?= e_icon('chevron-right', 16) ?></span></a>
            </div>
            <div class="landing-about-image">
                <img class="landing-supplier-photo" src="assets/images/asset-1.png" alt="A smiling supplier holding a tray of brown eggs" width="1372" height="1148" loading="lazy">
                <div class="landing-story-photos">
                    <img src="assets/images/asset-3.png" alt="A tray of fresh brown eggs" width="1708" height="904" loading="lazy">
                    <img src="assets/images/asset-23.png" alt="Green countryside in Naic, Cavite" width="1600" height="1000" loading="lazy">
                </div>
                <div class="landing-about-badge">
                    <?php if ($landing['years'] >= 1): ?>
                        <span class="landing-about-badge-number"><?= $landing['years'] ?>+</span>
                        <span class="landing-about-badge-text">Years of<br>Experience</span>
                    <?php else: ?>
                        <span class="landing-about-badge-number"><?= max(1, $landing['months']) ?>+</span>
                        <span class="landing-about-badge-text">Months<br>Serving Naic</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="landing-features" id="features">
        <div class="landing-features-inner">
            <span class="landing-eyebrow">Why choose Egglogan</span>
            <h2 class="landing-section-title">Everything Your Egg Business Needs</h2>
            <p class="landing-section-subtitle">One simple system for stock, sales, and insight &mdash; built for daily egg operations</p>
            <div class="landing-features-grid">
                <div class="landing-feature-card">
                    <div class="landing-feature-icon"><?= e_icon('box', 24) ?></div>
                    <h3>Live Inventory</h3>
                    <p>Know exactly how many trays and eggs are in stock at any moment, tracked by size and category.</p>
                </div>
                <div class="landing-feature-card">
                    <div class="landing-feature-icon"><?= e_icon('leaf', 24) ?></div>
                    <h3>FIFO Freshness</h3>
                    <p>Oldest stock sells first, so every customer always gets the freshest eggs we have on hand.</p>
                </div>
                <div class="landing-feature-card">
                    <div class="landing-feature-icon"><?= e_icon('cart', 24) ?></div>
                    <h3>Fast Sales &amp; Receipts</h3>
                    <p>Ring up orders in seconds and hand over a clean receipt &mdash; no more manual tracking.</p>
                </div>
                <div class="landing-feature-card">
                    <div class="landing-feature-icon"><?= e_icon('chart', 24) ?></div>
                    <h3>Smart Reports</h3>
                    <p>See what is selling, daily sales trends, and revenue at a glance with easy-to-read charts.</p>
                </div>
                <div class="landing-feature-card">
                    <div class="landing-feature-icon"><?= e_icon('bell', 24) ?></div>
                    <h3>Low-Stock Alerts</h3>
                    <p>Get warned before a size runs out, so you can restock in time and never miss a sale.</p>
                </div>
                <div class="landing-feature-card">
                    <div class="landing-feature-icon"><?= e_icon('trending-up', 24) ?></div>
                    <h3>Demand Forecasting</h3>
                    <p>Predict upcoming egg demand so you can prepare the right stock and cut waste.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="landing-products" id="sizes">
        <div class="landing-products-inner">
            <h2 class="landing-section-title">Egg Sizes Available</h2>
            <p class="landing-section-subtitle">We carry a full range of egg sizes to match every budget and recipe</p>
            <div class="landing-sizes-grid">
                <?php if (empty($landing['sizes'])): ?>
                    <div class="landing-size-card">
                        <div class="landing-size-label landing-size-image" data-size="SM"><img src="assets/images/egg-sizes/small.png" alt="" width="100" height="100" loading="lazy"></div>
                        <h3>Small</h3>
                        <p>42&ndash;49g per egg</p>
                        <span class="landing-size-tag">Budget-Friendly</span>
                    </div>
                    <div class="landing-size-card">
                        <div class="landing-size-label landing-size-image" data-size="MD"><img src="assets/images/egg-sizes/medium.png" alt="" width="100" height="100" loading="lazy"></div>
                        <h3>Medium</h3>
                        <p>49&ndash;56g per egg</p>
                        <span class="landing-size-tag">Everyday Use</span>
                    </div>
                    <div class="landing-size-card landing-size-featured">
                        <div class="landing-size-label landing-size-image" data-size="LG"><img src="assets/images/egg-sizes/large.png" alt="" width="100" height="100" loading="lazy"></div>
                        <h3>Large</h3>
                        <p>56&ndash;63g per egg</p>
                        <span class="landing-size-tag">Most Popular</span>
                    </div>
                    <div class="landing-size-card">
                        <div class="landing-size-label landing-size-image" data-size="XL"><img src="assets/images/egg-sizes/extra-large.png" alt="" width="100" height="100" loading="lazy"></div>
                        <h3>Extra Large</h3>
                        <p>63&ndash;70g per egg</p>
                        <span class="landing-size-tag">Premium</span>
                    </div>
                    <div class="landing-size-card">
                        <div class="landing-size-label landing-size-image" data-size="JB"><img src="assets/images/egg-sizes/jumbo.png" alt="" width="100" height="100" loading="lazy"></div>
                        <h3>Jumbo</h3>
                        <p>70g+ per egg</p>
                        <span class="landing-size-tag">Maximum Size</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($landing['sizes'] as $size): ?>
                        <?php
                            $code = $size['size_code'];
                            $isFeatured = ($code === $landing['popular_size']);
                            $tag = $isFeatured ? 'Most Popular' : ($sizeTags[$code] ?? ucfirst($size['category']));
                        ?>
                        <div class="landing-size-card<?= $isFeatured ? ' landing-size-featured' : '' ?>">
                            <div class="landing-size-label landing-size-image" data-size="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if (isset($sizeImages[$code])): ?>
                                    <img src="assets/images/egg-sizes/<?= $sizeImages[$code] ?>.png" alt="" width="100" height="100" loading="lazy">
                                <?php else: ?>
                                    <?= e_icon('egg', 40) ?>
                                <?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($size['size_label']) ?></h3>
                            <p><?= $sizeWeightNotes[$code] ?? ((int) $size['tray_capacity'] . ' eggs per tray') ?></p>
                            <div class="landing-size-price">
                                &#8369;<?= number_format((float) $size['price_per_egg'], 2) ?> / egg
                                <span>&middot; &#8369;<?= number_format((float) $size['price_per_tray'], 2) ?> / tray</span>
                            </div>
                            <span class="landing-size-tag"><?= htmlspecialchars($tag) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="landing-how" aria-labelledby="order-heading">
        <div class="landing-how-inner">
            <div><span class="landing-eyebrow">How to order</span><h2 id="order-heading">Get Your Eggs<br>in 3 Easy Steps.</h2><p>Simple, fast, and hassle-free.</p></div>
            <div class="landing-order-step"><?= e_icon('egg', 28) ?><div><span>01</span><h3>Choose Your Sizes</h3><p>Check our available egg sizes and current prices above.</p></div></div>
            <div class="landing-order-step"><?= e_icon('receipt', 28) ?><div><span>02</span><h3>Place Your Order</h3><p>Message us or call to confirm your order.</p></div></div>
            <div class="landing-order-step"><?= e_icon('box', 28) ?><div><span>03</span><h3>Arrange Collection</h3><p>Confirm pickup or delivery details with our team.</p></div></div>
        </div>
    </section>

    <section class="landing-location" id="location">
        <div class="landing-location-inner">
            <div class="landing-location-info">
                <span class="landing-eyebrow">Visit or get in touch</span>
                <h2>We&rsquo;re in Naic, Cavite.</h2>
                <p class="landing-location-subtitle">Visit our store or reach out anytime</p>
                <div class="landing-location-details">
                    <div class="landing-location-item">
                        <div class="landing-location-item-icon"><?= e_icon('location', 24) ?></div>
                        <div>
                            <h4>Address</h4>
                            <p>Villa Apolonia Subdivision<br>Blk 2 Lot 4 Phase 1<br>Naic, Cavite 4110<br>Philippines</p>
                        </div>
                    </div>
                    <div class="landing-location-item">
                        <div class="landing-location-item-icon"><?= e_icon('phone', 24) ?></div>
                        <div>
                            <h4>Phone</h4>
                            <p>0938 078 8830</p>
                        </div>
                    </div>
                    <div class="landing-location-item">
                        <div class="landing-location-item-icon"><?= e_icon('facebook', 24) ?></div>
                        <div>
                            <h4>Facebook</h4>
                            <a href="https://www.facebook.com/egglogancavite/" target="_blank" rel="noopener noreferrer">facebook.com/egglogancavite</a>
                        </div>
                    </div>
                    <div class="landing-location-item">
                        <div class="landing-location-item-icon"><?= e_icon('clock', 24) ?></div>
                        <div>
                            <h4>Business Hours</h4>
                            <p>Monday &ndash; Sunday<br><strong>8:00 AM &ndash; 8:00 PM</strong></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="landing-location-map">
                <div class="landing-location-map-placeholder">
                    <iframe src="https://maps.google.com/maps?q=14.325991%2C120.775719&t=&z=15&ie=UTF8&iwloc=&output=embed" width="100%" height="100%" style="border:0; border-radius: 12px;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="EggLogan Location"></iframe>
                </div>
            </div>
        </div>
    </section>

    <section class="landing-cta-banner" aria-labelledby="cta-heading">
        <div class="landing-cta-banner-inner">
            <span class="landing-eyebrow">Fresh eggs. Brighter days.</span>
            <h2 id="cta-heading">Ready to Place an Order?</h2>
            <p>Get fresh, high-quality tray eggs for your business or home.</p>
            <div class="landing-hero-actions"><a class="landing-cta-banner-btn" href="tel:09380788830">Call to Order <span aria-hidden="true"><?= e_icon('chevron-right', 16) ?></span></a><a class="landing-cta-ghost" href="https://www.facebook.com/egglogancavite/" target="_blank" rel="noopener noreferrer">Visit Our Facebook <span aria-hidden="true"><?= e_icon('chevron-right', 16) ?></span></a></div>
        </div>
    </section>

    <footer class="landing-footer">
        <div class="landing-footer-grid">
            <div class="landing-footer-brand">
                <a class="landing-footer-logo" href="#home"><img src="assets/images/header-logo.png" alt="Egglogan — Quality eggs. Brighter days." width="2172" height="724" loading="lazy"></a>
                <p>Cavite Tray Egg Dealer</p>
            </div>
            <div class="landing-footer-col">
                <h4>Contact</h4>
                <p><?= e_icon('phone', 16) ?> 0938 078 8830</p>
                <a href="https://www.facebook.com/egglogancavite/" target="_blank" rel="noopener noreferrer" class="landing-footer-social" aria-label="Facebook">
                    <?= e_icon('facebook', 20) ?>
                </a>
            </div>
            <div class="landing-footer-col">
                <h4>Address</h4>
                <p>Villa Apolonia Subdivision<br>Blk 2 Lot 4 Phase 1<br>Naic, Cavite 4110<br>Philippines</p>
            </div>
            <div class="landing-footer-col">
                <h4>Hours</h4>
                <p>Monday &ndash; Sunday</p>
                <p class="landing-hours-time">8:00 AM &ndash; 8:00 PM</p>
            </div>
        </div>
        <div class="landing-footer-bottom">
            <p>&copy; <?= date('Y') ?> EggLogan. All rights reserved.</p>
            <button type="button" class="privacy-link" data-open-privacy>Privacy &amp; cookies</button>
        </div>
    </footer>

    <aside class="cookie-notice" id="cookieNotice" aria-label="Cookie notice" hidden>
        <strong>Cookies &amp; privacy</strong>
        <p>We use session cookies to keep you signed in and browser storage for your preferences.</p>
        <div><button type="button" class="privacy-link" data-open-privacy>Privacy &amp; cookies</button><button type="button" class="btn btn-primary" id="dismissCookieNotice">Got it</button></div>
    </aside>
    <dialog class="privacy-dialog" id="privacyDialog" aria-labelledby="privacyTitle">
        <div class="privacy-dialog-header"><h2 id="privacyTitle">Privacy &amp; cookies</h2><button type="button" class="btn-small" id="closePrivacy" aria-label="Close privacy notice"><?= e_icon('close', 20) ?></button></div>
        <p>Egglogan uses account, inventory and sales information to operate the egg management system.</p>
        <h3>Information used</h3><p>Account names and usernames identify staff. Stock, receipts, payment records and activity logs support inventory and sales operations.</p>
        <h3>Cookies and browser storage</h3><p>Session cookies keep signed-in users connected to their account. Browser storage remembers interface preferences, this notice, and scans queued while offline.</p>
        <h3>External services</h3><p>The location map is provided by Google Maps. Embedded services and external links may use their own cookies and privacy practices.</p>
        <p>For questions about your account or business records, contact the store using the details on this page.</p>
        <button type="button" class="btn btn-secondary" id="showCookieNotice">Show cookie notice</button>
    </dialog>

    <div class="login-overlay" id="loginOverlay" onclick="closeLogin()"></div>
    <div class="login-popup" id="loginPopup" role="dialog" aria-modal="true" aria-label="Sign in to EggLogan">
        <button type="button" class="login-popup-close" onclick="closeLogin()" aria-label="Close"><?= e_icon('close', 18) ?></button>
        <div class="login-logo">
            <h1><img class="login-brand-logo" src="assets/images/header-logo.png" alt="Egglogan — Quality eggs. Brighter days." width="2172" height="724"></h1>
            <p>Fresh Eggs, Delivered Daily</p>
            <p class="login-subtitle">Always Fresh &bull; Cavite Tray Egg Dealer</p>
        </div>

        <!-- Tabs: Login / Sign Up -->
        <div class="login-tabs">
            <button class="login-tab login-tab-active" data-tab="login" onclick="showTab('login', event)">Sign In</button>
            <button class="login-tab" data-tab="signup" onclick="showTab('signup', event)">Sign Up</button>
        </div>

        <form method="POST" class="login-form" id="form-login">
                        <?= csrfField() ?>
            <?php if ($signup): ?>
                <div class="alert alert-success"><?= $error ?></div>
            <?php elseif ($authNotice === 'pending_approval'): ?>
                <div class="alert alert-error">Your account is awaiting admin approval. Please try again later.</div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!$signup): ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-field"><input type="password" id="password" name="password" required><button type="button" class="password-toggle" aria-label="Show password" aria-controls="password" aria-pressed="false" onclick="togglePasswordVisibility(this)"><span class="password-show-icon"><?= e_icon('eye', 20) ?></span><span class="password-hide-icon" hidden><?= e_icon('eye-off', 20) ?></span></button></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block login-signin-submit">Sign In</button>
            <?php else: ?>
                <div class="form-group">
                    <label for="full_name_login">Full Name</label>
                    <input type="text" id="full_name_login" name="full_name" required>
                </div>
                <input type="hidden" name="signup" value="1">
                <div class="form-group">
                    <label for="username_login">Username</label>
                    <input type="text" id="username_login" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password_login">Password</label>
                    <div class="password-field"><input type="password" id="password_login" name="password" required><button type="button" class="password-toggle" aria-label="Show password" aria-controls="password_login" aria-pressed="false" onclick="togglePasswordVisibility(this)"><span class="password-show-icon"><?= e_icon('eye', 20) ?></span><span class="password-hide-icon" hidden><?= e_icon('eye-off', 20) ?></span></button></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Create Account</button>
            <?php endif; ?>
        </form>

        <form method="POST" class="login-form signup-form" id="form-signup" style="display:none;">
                        <?= csrfField() ?>
            <input type="hidden" name="signup" value="1">
            <div class="form-group">
                <label for="full_name_signup">Full Name</label>
                <input type="text" id="full_name_signup" name="full_name" required>
            </div>
            <div class="form-group">
                <label for="username_signup">Username</label>
                <input type="text" id="username_signup" name="username" required>
            </div>
            <div class="form-group">
                <label for="password_signup">Password</label>
                <div class="password-field"><input type="password" id="password_signup" name="password" required><button type="button" class="password-toggle" aria-label="Show password" aria-controls="password_signup" aria-pressed="false" onclick="togglePasswordVisibility(this)"><span class="password-show-icon"><?= e_icon('eye', 20) ?></span><span class="password-hide-icon" hidden><?= e_icon('eye-off', 20) ?></span></button></div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>
    </div>

    <script>
    function togglePasswordVisibility(button) {
        var input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        var visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.querySelector('.password-show-icon').hidden = visible;
        button.querySelector('.password-hide-icon').hidden = !visible;
    }
    function switchTab(tab) {
        document.querySelectorAll('.login-tab').forEach(function(b) {
            b.classList.toggle('login-tab-active', b.dataset.tab === tab);
        });
        document.querySelectorAll('.login-form').forEach(function(f) { f.style.display = 'none'; });
        var form = document.getElementById('form-' + tab);
        if (form) form.style.display = 'block';
    }
    function showTab(tab, event) {
        if (event) event.preventDefault();
        switchTab(tab);
    }

    function toggleNav() {
        var expanded = document.getElementById('navLinks').classList.toggle('open');
        document.querySelector('.landing-nav-toggle').setAttribute('aria-expanded', String(expanded));
    }
    function openLogin(tab) {
        tab = tab === 'signup' ? 'signup' : 'login';
        document.body.classList.add('auth-open');
        document.getElementById('loginOverlay').classList.add('show');
        document.getElementById('loginPopup').classList.add('show');
        switchTab(tab);
        var field = document.getElementById(tab === 'signup' ? 'full_name_signup' : 'username');
        if (field) field.focus();
    }
    function closeLogin() {
        document.body.classList.remove('auth-open');
        document.querySelectorAll('.password-toggle[aria-pressed="true"]').forEach(togglePasswordVisibility);
        document.getElementById('loginOverlay').classList.remove('show');
        document.getElementById('loginPopup').classList.remove('show');
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogin();
    });
    document.querySelectorAll('.landing-nav-links a').forEach(function(link) {
        link.addEventListener('click', function() {
            document.getElementById('navLinks').classList.remove('open');
            document.querySelector('.landing-nav-toggle').setAttribute('aria-expanded', 'false');
        });
    });
    var cookieNotice = document.getElementById('cookieNotice');
    try { cookieNotice.hidden = localStorage.getItem('eggloganCookieNotice') === 'dismissed'; } catch (error) { cookieNotice.hidden = false; }
    document.getElementById('dismissCookieNotice').addEventListener('click', function() {
        cookieNotice.hidden = true;
        try { localStorage.setItem('eggloganCookieNotice', 'dismissed'); } catch (error) {}
    });
    var privacyDialog = document.getElementById('privacyDialog');
    document.querySelectorAll('[data-open-privacy]').forEach(function(button) { button.addEventListener('click', function() { privacyDialog.showModal(); }); });
    document.getElementById('showCookieNotice').addEventListener('click', function() { cookieNotice.hidden = false; try { localStorage.removeItem('eggloganCookieNotice'); } catch (error) {} privacyDialog.close(); });
    document.getElementById('closePrivacy').addEventListener('click', function() { privacyDialog.close(); });
    <?php if ($error !== '' || $authNotice !== ''): ?>openLogin();<?php endif; ?>
    </script>

</body>
</html>
