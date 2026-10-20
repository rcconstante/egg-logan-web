<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/QRManager.php';

requireAuth();

$db = Database::getInstance();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - QR Scanner</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <link rel="icon" type="image/png" href="../assets/images/favicon-icon.png">
</head>
<body class="app-shell">
    <div class="app-container">
        <nav class="sidebar" id="sidebar"><?php include 'sidebar.php'; ?></nav>
        <main class="main-content">
            <header class="top-bar">
                <?php include __DIR__ . '/../includes/utility-controls.php'; ?>
                <div class="top-bar-actions"><?php include __DIR__ . '/../includes/header-actions.php'; ?></div>
            </header>

            <div class="page-heading">
                <span class="page-eyebrow">Inventory tools</span>
                <h1>QR Scanner</h1>
                <p>Scan tray codes or look up a tray manually.</p>
            <div class="page-actions no-print" data-toolbar role="group" aria-label="Page actions">
                <span id="syncQueueBadge" class="badge-count" style="display:none;cursor:pointer;" onclick="syncQueuedScans()" title="Click to sync queued offline scans"></span>
                    <button onclick="switchMode()" id="modeToggle" class="btn btn-secondary">Switch to Manual Entry</button>
                    <button onclick="toggleTorch()" id="torchBtn" class="btn btn-secondary" style="display:none;">Flash</button>
            </div></div>

            

            <div class="scanner-container">
                <div class="card scanner-card" id="cameraScanner">
                    <div class="card-header"><h3>Camera Scanner</h3></div>
                    <div class="card-body">
                        <div class="scanner-idle" id="scannerIdle"><?= e_icon('scan', 40) ?><strong>Camera is off</strong><p>Start the camera to scan an egg QR code. Your browser will ask for camera permission if needed.</p></div>
                        <div class="video-container" id="scannerViewport" hidden>
                            <video id="scannerVideo" playsinline autoplay muted></video>
                            <canvas id="scannerCanvas" style="display:none;"></canvas>
                            <div class="scan-overlay">
                                <div class="scan-frame"></div>
                                <p class="scan-hint">Position QR code within the frame</p>
                            </div>
                        </div>
                        <div class="scanner-controls">
                            <button onclick="startScanner()" class="btn btn-primary" id="startScanBtn">Start Camera</button>
                            <button onclick="stopScanner()" class="btn btn-secondary" id="stopScanBtn" style="display:none;">Stop Camera</button>
                        </div>
                        <div id="cameraError" class="alert alert-error" style="display:none;"></div>
                    </div>
                </div>

                <div class="card manual-card" id="manualEntry" style="display:none;">
                    <div class="card-header"><h3>Manual QR Entry</h3></div>
                    <div class="card-body">
                        <form id="manualQRForm">
                            <div class="form-group">
                                <label>Paste QR Data or Tray ID</label>
                                <textarea name="qr_data" rows="3" placeholder="Paste QR code data or enter tray ID manually..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Look Up Tray</button>
                        </form>
                    </div>
                </div>

                <div class="card batch-mode-card">
                    <div class="card-header">
                        <h3>Batch Scan Mode</h3>
                        <label class="toggle">
                            <input type="checkbox" id="batchModeToggle" onchange="toggleBatchMode()">
                            <span class="toggle-slider"></span> Enable
                        </label>
                    </div>
                    <div class="card-body">
                        <div id="batchModePanel" style="display:none;">
                            <p>Scanned trays will be queued for bulk action.</p>
                            <div id="batchQueue">
                                <div class="empty-state"><strong>No data yet</strong><p>No trays scanned yet</p></div>
                            </div>
                            <div class="form-actions">
                                <button onclick="processBatch()" class="btn btn-success">Process All</button>
                                <button onclick="clearBatch()" class="btn btn-secondary">Clear</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" id="scanResult" style="display:none;">
                <div class="card-header"><h3>Scan Result</h3></div>
                <div class="card-body" id="scanResultContent"></div>
            </div>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    var scanning = false;
    var stream = null;
    var batchMode = false;
    var batchQueue = [];
    var torchSupported = false;
    var torchOn = false;

    function switchMode() {
        var camera = document.getElementById('cameraScanner');
        var manual = document.getElementById('manualEntry');
        var toggle = document.getElementById('modeToggle');

        if (camera.style.display !== 'none') {
            camera.style.display = 'none';
            manual.style.display = 'block';
            toggle.textContent = 'Switch to Camera Scanner';
            stopScanner();
        } else {
            camera.style.display = 'block';
            manual.style.display = 'none';
            toggle.textContent = 'Switch to Manual Entry';
        }
    }

    var scannerRequestId = 0;
    window.addEventListener('pagehide', stopScanner);

    async function startScanner() {
        if (scanning || document.getElementById('startScanBtn').disabled) return;
        var requestId = ++scannerRequestId;
        var startButton = document.getElementById('startScanBtn');
        startButton.disabled = true;
        startButton.textContent = 'Starting camera…';
        var errDiv = document.getElementById('cameraError');
        errDiv.style.display = 'none';
        try {
            if (typeof jsQR === 'undefined') {
                errDiv.textContent = 'QR scanner library not loaded. Check internet connection.';
                errDiv.style.display = 'block';
                return;
            }
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('Camera access is unavailable in this browser');
            var cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } }
            });
            if (requestId !== scannerRequestId) { cameraStream.getTracks().forEach(function(track) { track.stop(); }); return; }
            stream = cameraStream;
            var video = document.getElementById('scannerVideo');
            video.srcObject = stream;
            await video.play();
            if (requestId !== scannerRequestId) return;
            document.getElementById('scannerIdle').hidden = true;
            document.getElementById('scannerViewport').hidden = false;

            document.getElementById('startScanBtn').style.display = 'none';
            document.getElementById('stopScanBtn').style.display = 'inline-block';
            scanning = true;

            var track = stream.getVideoTracks()[0];
            torchSupported = track && track.getCapabilities && track.getCapabilities().torch;
            document.getElementById('torchBtn').style.display = torchSupported ? 'inline-block' : 'none';

            track?.addEventListener('ended', stopScanner, { once: true });
            scanFrame();
        } catch (err) {
            stopScanner();
            errDiv.textContent = 'Camera error: ' + err.message + '. Try manual entry.';
            errDiv.style.display = 'block';
        } finally {
            startButton.disabled = false;
            startButton.textContent = 'Start Camera';
        }
    }

    function stopScanner() {
        ++scannerRequestId;
        document.getElementById('scannerIdle').hidden = false;
        document.getElementById('scannerViewport').hidden = true;
        document.getElementById('scannerVideo').srcObject = null;
        if (stream) {
            stream.getTracks().forEach(function(t) { t.stop(); });
            stream = null;
        }
        scanning = false;
        torchOn = false;
        document.getElementById('startScanBtn').style.display = 'inline-block';
        document.getElementById('stopScanBtn').style.display = 'none';
        document.getElementById('torchBtn').style.display = 'none';
    }

    async function toggleTorch() {
        if (!stream) return;
        var track = stream.getVideoTracks()[0];
        if (track && track.applyConstraints) {
            torchOn = !torchOn;
            try {
                await track.applyConstraints({ advanced: [{ torch: torchOn }] });
                document.getElementById('torchBtn').textContent = torchOn ? 'Flash On' : 'Flash';
            } catch(e) {
                document.getElementById('torchBtn').textContent = 'Flash N/A';
            }
        }
    }

    function scanFrame() {
        if (!scanning) return;
        var video = document.getElementById('scannerVideo');
        var canvas = document.getElementById('scannerCanvas');
        if (!video || !canvas) { scanning = false; return; }
        var ctx = canvas.getContext('2d');

        if (video.readyState === video.HAVE_ENOUGH_DATA && video.videoWidth > 0) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            try {
                var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                if (typeof jsQR !== 'undefined') {
                    var code = jsQR(imageData.data, canvas.width, canvas.height, {
                        inversionAttempts: 'attemptBoth'
                    });
                    if (code && code.data) {
                        processQRData(code.data);
                        return;
                    }
                }
            } catch(e) {}
        }
        requestAnimationFrame(scanFrame);
    }

    function scanNext() {
        document.getElementById('scanResult').style.display = 'none';
        if (document.getElementById('manualEntry').style.display !== 'none') {
            document.getElementById('manualQRForm').reset();
            document.querySelector('#manualQRForm textarea').focus();
        } else {
            document.getElementById('cameraScanner').scrollIntoView({ behavior: 'smooth', block: 'start' });
            startScanner();
        }
    }

    function processQRData(data) {
        stopScanner();
        showResult('<div class="scan-loading" role="status">Looking up current trays…</div>');
        fetch('../api/scan/process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ qr_data: data })
        })
        .then(function(r) { if (!r.ok) { var error = new Error('Could not load trays. Please try again.'); error.httpStatus = r.status; throw error; } return r.json(); })
        .then(function(result) {
            if (result.error) {
                showResult('<div class="alert alert-error">' + escapeHtml(result.error) + '</div>');
                return;
            }
            if (result.variant_scan) {
                showVariantInfo(result);
                return;
            }
            if (batchMode) {
                batchQueue.push(result.tray);
                updateBatchQueue();
            }
            showTrayInfo(result);
        })
        .catch(function(err) {
            if (err.httpStatus) { showResult('<div class="alert alert-error">' + escapeHtml(err.message) + '</div>'); return; }
            queueOfflineScan(data);
            showResult('<div class="alert alert-warning">Offline - scan queued (' + getQueuedScanCount() + ' pending). It will sync automatically when connection returns.</div>');
        });
    }

    function queueOfflineScan(qrData) {
        var q = [];
        try { q = JSON.parse(localStorage.getItem('egglogan_scan_queue') || '[]'); } catch(e) {}
        q.push({ qr_data: qrData, queued_at: Date.now() });
        try { localStorage.setItem('egglogan_scan_queue', JSON.stringify(q)); } catch(e) {}
    }

    function getQueuedScanCount() {
        try { return JSON.parse(localStorage.getItem('egglogan_scan_queue') || '[]').length; } catch(e) { return 0; }
    }

    function syncQueuedScans() {
        var q = [];
        try { q = JSON.parse(localStorage.getItem('egglogan_scan_queue') || '[]'); } catch(e) {}
        if (!q.length) { showToast('No queued scans to sync.', 'info'); return; }
        var remaining = [];
        var processed = 0;
        var done = function() {
            try { localStorage.setItem('egglogan_scan_queue', JSON.stringify(remaining)); } catch(e) {}
            showToast('Synced ' + processed + ' scan(s).' + (remaining.length ? ' ' + remaining.length + ' still pending.' : ''), remaining.length ? 'warning' : 'success');
            updateSyncBadge();
            if (processed > 0 && !remaining.length) location.reload();
        };
        q.forEach(function(item, idx) {
            fetch('../api/scan/process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ qr_data: item.qr_data })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.error) { remaining.push(item); } else { processed++; }
                if (idx === q.length - 1) done();
            })
            .catch(function() { remaining.push(item); if (idx === q.length - 1) done(); });
        });
    }

    function updateSyncBadge() {
        var badge = document.getElementById('syncQueueBadge');
        if (!badge) return;
        var n = getQueuedScanCount();
        badge.style.display = n > 0 ? 'inline-block' : 'none';
        badge.textContent = n + ' queued';
    }

    window.addEventListener('online', function() { if (getQueuedScanCount() > 0) syncQueuedScans(); });

    function showTrayInfo(result) {
        var tray = result.tray;
        if (!tray) { showResult('<div class="alert alert-error">Invalid scan result</div>'); return; }
        var ageColor = result.age_color || 'green';
        var html = '<div class="tray-info ' + (result.is_expired ? 'expired' : result.is_expiring ? 'expiring' : '') + '">';
        html += '<h4>Tray Info</h4>';
        html += '<table class="table">';
        html += '<tr><td>Size</td><td>' + tray.size_label + ' (' + tray.egg_size + ')</td></tr>';
        html += '<tr><td>Batch</td><td>' + escapeHtml(tray.batch_number) + '</td></tr>';
        html += '<tr><td>Age</td><td><span class="age-label age-' + ageColor + '">' + (tray.days_in_storage || 0) + ' days</span></td></tr>';
        html += '<tr><td>Expiry</td><td>' + (tray.days_until_expiry >= 0 ? tray.days_until_expiry + ' days left' : '<span class="text-danger">EXPIRED</span>') + '</td></tr>';
        html += '<tr><td>Quantity</td><td>' + (tray.quantity || 0) + ' eggs</td></tr>';
        html += '</table>';
        html += '<div class="tray-actions">';
        html += '<button onclick="quickSell(\'' + tray.tray_id + '\')" class="btn btn-success">Sell</button>';
        html += '<button onclick="markDamaged(\'' + tray.tray_id + '\')" class="btn btn-warning">Damaged</button>';
        html += '<button onclick="verifyBatchCount(\'' + tray.tray_id + '\', ' + (tray.batch_id || 0) + ')" class="btn btn-secondary">Verify</button>';
        html += '<button onclick="scanNext()" class="btn btn-secondary">Scan Next</button>';
        html += '</div></div>';
        showResult(html);
    }

    function showVariantInfo(result) {
        var html = '<div class="variant-info">';
        html += '<h4>' + escapeHtml(result.size_label) + ' (' + result.egg_size + ') — Variant Overview</h4>';
        html += '<div class="stats-grid" style="margin:10px 0;">';
        html += '<div class="stat-card"><div class="stat-value">' + result.summary.batch_count + '</div><div class="stat-label">Batches</div></div>';
        html += '<div class="stat-card"><div class="stat-value">' + result.summary.total_trays + '</div><div class="stat-label">Total Trays</div></div>';
        html += '<div class="stat-card"><div class="stat-value">' + result.summary.total_eggs.toLocaleString() + '</div><div class="stat-label">Total Eggs</div></div>';
        html += '<div class="stat-card"><div class="stat-value">' + result.tray_capacity + '</div><div class="stat-label">Capacity/Tray</div></div>';
        html += '</div>';

        if (result.batches.length === 0) {
            html += '<div class="empty-state"><strong>No data yet</strong><p>No in-stock trays found for this variant. New batches will appear here automatically.</p></div>';
        } else {
            result.batches.forEach(function(batch) {
                html += '<div style="border:1px solid var(--gray-200);border-radius:var(--r-md);padding:10px;margin:8px 0;">';
                html += '<div style="font-weight:700;font-size:13px;">Batch #' + escapeHtml(batch.batch_number);
                html += ' <span class="age-label age-' + batch.age_color + '" style="font-size:11px;">' + batch.tray_count + ' trays</span>';
                html += '</div>';
                html += '<div style="font-size:11px;color:var(--gray-500);margin:4px 0;">';
                html += 'Produced: ' + escapeHtml(batch.production_date) + ' | Earliest expiry: ' + escapeHtml(batch.expiry_date || 'N/A');
                if (batch.supplier) html += ' | Supplier: ' + escapeHtml(batch.supplier);
                html += ' | Eggs: ' + batch.total_eggs.toLocaleString();
                html += '</div>';
                html += '<table class="table" style="margin:6px 0;font-size:12px;">';
                html += '<thead><tr><th>Tray</th><th>Eggs / tray</th><th>Produced</th><th>Expiry date</th><th>Days left</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                batch.trays.forEach(function(tray) {
                    var expiryClass = tray.days_until_expiry <= 3 ? 'text-danger' : '';
                    html += '<tr>';
                    html += '<td><strong>#' + escapeHtml(String(tray.tray_sequence || '-')) + '</strong></td>';
                    html += '<td>' + tray.quantity + '</td>';
                    html += '<td>' + escapeHtml(tray.production_date) + '</td>';
                    html += '<td>' + escapeHtml(tray.expiry_date) + '</td>';
                    html += '<td class="' + expiryClass + '">' + (tray.days_until_expiry >= 0 ? tray.days_until_expiry + 'd' : 'EXPIRED') + '</td>';
                    html += '<td>' + tray.status + '</td>';
                    html += '<td><button ' + (Number(tray.days_until_expiry) < 0 ? 'disabled ' : '') + 'onclick="quickSell(\'' + tray.tray_id + '\')" class="btn-small btn-success icon-action" aria-label="Sell from this tray" title="Sell from this tray">' + createUiIcon('cart', 18).outerHTML + '</button></td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            });
        }

        html += '<div style="margin-top:10px;"><button onclick="scanNext()" class="btn btn-primary">Scan Next</button></div>';
        html += '</div>';
        showResult(html);
    }

    function showResult(html) {
        var resultDiv = document.getElementById('scanResult');
        resultDiv.style.display = 'block';
        document.getElementById('scanResultContent').innerHTML = html;
        resultDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function quickSell(trayId) {
        window.location.href = 'sale.php?tray_id=' + encodeURIComponent(trayId);
    }

    async function markDamaged(trayId) {
        var qty = await requestInput({ title: 'Mark damaged eggs', label: 'Quantity affected', type: 'number', min: 1 });
        if (qty) {
            var issue = await requestInput({ title: 'Damage details', label: 'Issue type: leaking or other', value: 'other', pattern: 'leaking|other', confirmLabel: 'Save damage' });
            if (issue === null) return;
            fetch('../api/inventory/mark_damaged.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tray_id: trayId, quantity: parseInt(qty) || 0, issue_type: issue || 'other' })
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                showToast(d.success ? 'Updated successfully' : 'Error: ' + d.error, d.success ? 'success' : 'error');
                if (d.success) location.reload();
            });
        }
    }

    async function verifyBatchCount(trayId, batchId) {
        if (!batchId) { showToast('Batch information unavailable for this tray.', 'error'); return; }
        var count = await requestInput({ title: 'Verify batch count', label: 'Physical tray count for this batch', type: 'number', min: 0, confirmLabel: 'Verify count' });
        if (count === null) return;
        fetch('../api/inventory/verify_batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_id: batchId, physical_count: parseInt(count) || 0 })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var msg = 'System: ' + d.system_count + ' | Physical: ' + d.physical_count + '\\n' +
                      (d.match ? 'Counts MATCH.' : 'DIFFERENCE: ' + d.difference + ' tray(s). Investigate.');
            showToast('Batch count checked', d.match ? 'success' : 'warning', { message: msg, duration: 0 });
        });
    }

    function toggleBatchMode() {
        batchMode = document.getElementById('batchModeToggle').checked;
        document.getElementById('batchModePanel').style.display = batchMode ? 'block' : 'none';
    }

    function updateBatchQueue() {
        var container = document.getElementById('batchQueue');
        if (batchQueue.length === 0) {
            container.innerHTML = '<div class="empty-state"><strong>No data yet</strong><p>No trays scanned yet</p></div>';
            return;
        }
        var html = '<table class="table"><thead><tr><th>Tray ID</th><th>Size</th></tr></thead><tbody>';
        batchQueue.forEach(function(t) {
            html += '<tr><td>' + (t.tray_id || '').substring(0, 8) + '...</td><td>' + (t.size_label || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function processBatch() {
        if (batchQueue.length === 0) { showToast('No trays in queue', 'info'); return; }
        window.location.href = 'sale.php?trays=' + encodeURIComponent(batchQueue.map(function(t) { return t.tray_id; }).join(','));
    }

    function clearBatch() {
        batchQueue = [];
        updateBatchQueue();
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    document.getElementById('manualQRForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        var data = new FormData(this).get('qr_data');
        if (data) processQRData(data);
    });

    updateSyncBadge();
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
