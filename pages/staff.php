<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';

requirePageAdmin();

$db = Database::getInstance();
$action = $_GET['action'] ?? 'list';
$logPage = max(1, (int)($_GET['log_page'] ?? 1));
$logPerPage = 15;
$logOffset = ($logPage - 1) * $logPerPage;
$staffList = $db->fetchAll("SELECT id, username, full_name, role, is_active, is_approved, created_at FROM staff_users ORDER BY created_at DESC");

$staffActivityCount = $db->fetchOne("SELECT COUNT(*) as total FROM activity_logs");
$logTotal = $staffActivityCount['total'] ?? 0;
$logPagination = getPagination($logPage, $logPerPage, $logTotal);
$staffActivity = $db->fetchAll("SELECT a.*, s.full_name FROM activity_logs a LEFT JOIN staff_users s ON a.user_id = s.id ORDER BY a.created_at DESC LIMIT ? OFFSET ?", [$logPerPage, $logOffset]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Staff Management</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
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
                <span class="page-eyebrow">Administration</span>
                <h1>Staff Management</h1>
                <p>Manage staff accounts and review recent activity.</p>
            <div class="page-actions no-print" data-toolbar role="group" aria-label="Page actions">
                <button onclick="showAddStaff()" class="btn btn-primary"><?= e_icon('plus', 18) ?> Add Staff</button>
            </div></div>

            

            <dialog id="addStaffDialog" class="confirmation-dialog form-dialog" aria-labelledby="addStaffTitle">
                <div class="form-dialog-heading"><div><h2 id="addStaffTitle">Add New Staff</h2><p>Create an account and assign its access level.</p></div><button type="button" class="btn-small icon-action" onclick="closeAddStaff()" aria-label="Close add staff"><?= e_icon('close', 20) ?></button></div>
                <div>
                    <form id="staffForm" method="POST" action="../api/staff/create.php">
                        <?= csrfField() ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="staffFullName">Full Name</label>
                                <input id="staffFullName" type="text" name="full_name" minlength="2" maxlength="100" autocomplete="name" required>
                            </div>
                            <div class="form-group">
                                <label for="staffUsername">Username</label>
                                <input id="staffUsername" type="text" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.\-]+" autocomplete="off" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="staffPassword">Password</label>
                                <input id="staffPassword" type="password" name="password" autocomplete="new-password" required minlength="6" maxlength="72">
                            </div>
                            <div class="form-group">
                                <label for="staffRole">Role</label>
                                <select id="staffRole" name="role">
                                    <option value="staff">Staff</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-success">Create Staff</button>
                            <button type="button" onclick="closeAddStaff()" class="btn btn-secondary">Cancel</button>
                        </div>
                    </form>
                </div>
            </dialog>

            <div class="card">
                <div class="card-header"><h3>Staff Accounts (<?= count($staffList) ?>)</h3></div>
                <div class="card-body">
                    <table class="table">
                        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($staffList)): ?>
                            <tr><td colspan="6"><div class="empty-state"><strong>No data yet</strong><p>Staff accounts will appear here.</p></div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($staffList as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['full_name']) ?></td>
                                <td><?= htmlspecialchars($s['username']) ?></td>
                                <td><span class="role-badge role-<?= $s['role'] ?>"><?= ucfirst($s['role']) ?></span></td>
                                <td><?= !$s['is_approved'] ? '<span class="badge badge-warning">Pending approval</span>' : ($s['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>') ?></td>
                                <td><?= formatDate($s['created_at']) ?></td>
                                <td>
                                    <?php if (!$s['is_approved']): ?>
                                    <button onclick="approveStaff(<?= $s['id'] ?>)" class="btn-small icon-action" aria-label="Approve account" title="Approve account"><?= e_icon('check-circle', 18) ?></button>
                                    <?php elseif ((int)$s['id'] !== (int)getCurrentUserId()): ?>
                                    <button onclick="toggleStatus(<?= $s['id'] ?>, <?= $s['is_active'] ?>)" class="btn-small icon-action" aria-label="<?= $s['is_active'] ? 'Deactivate staff' : 'Activate staff' ?>" title="<?= $s['is_active'] ? 'Deactivate staff' : 'Activate staff' ?>"><?= e_icon('shield', 18) ?></button>
                                    <?php endif; ?>
                                    <button onclick="resetPassword(<?= $s['id'] ?>)" class="btn-small icon-action" aria-label="Reset password" title="Reset password"><?= e_icon('key', 18) ?></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Recent Activity</h3>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span style="color:#888;font-size:13px;"><?= number_format($logTotal) ?> entries</span>
                        <button onclick="clearActivityLogs()" class="btn btn-danger" style="font-size:12px;padding:4px 10px;">Clear Logs</button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($staffActivity)): ?>
                        <div class="empty-state"><strong>No data yet</strong><p>No activity logs found.</p></div>
                    <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Time</th><th>Staff</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                            <?php foreach ($staffActivity as $log): ?>
                            <tr>
                                <td><?= formatDateTime($log['created_at']) ?></td>
                                <td><?= htmlspecialchars($log['full_name'] ?? 'System') ?></td>
                                <td><?= htmlspecialchars($log['action']) ?></td>
                                <td><?= htmlspecialchars(substr($log['details'] ?? '', 0, 80)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($logPagination['total_pages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($logPagination['has_prev']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['log_page' => $logPagination['prev']])) ?>" class="btn btn-small btn-secondary">&laquo; Prev</a>
                        <?php endif; ?>
                        <span class="page-info">Page <?= $logPagination['current'] ?> of <?= $logPagination['total_pages'] ?></span>
                        <?php if ($logPagination['has_next']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['log_page' => $logPagination['next']])) ?>" class="btn btn-small btn-secondary">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    function showAddStaff() {
        var dialog = document.getElementById('addStaffDialog');
        if (!dialog.open) dialog.showModal();
    }
    function closeAddStaff() { document.getElementById('addStaffDialog').close(); }
    document.getElementById('addStaffDialog').addEventListener('click', function(event) {
        if (event.target !== this) return;
        var rect = this.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) this.close();
    });
    document.getElementById('addStaffDialog').addEventListener('close', function() {
        document.getElementById('staffPassword').value = '';
    });
    <?php if ($action === 'add'): ?>
    document.addEventListener('DOMContentLoaded', showAddStaff);
    <?php endif; ?>

    document.getElementById('staffForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var button = form.querySelector('button[type="submit"]');
        if (button.disabled) return;
        button.disabled = true;
        button.textContent = 'Creating…';
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form)
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                window.location.href = 'staff.php';
            } else {
                showToast('Error: ' + (d.error || 'Failed to create staff'), 'error');
            }
        }).catch(function() {
            showToast('Could not create staff', 'error', { message: 'Check your connection and try again.' });
        }).finally(function() {
            button.disabled = false;
            button.textContent = 'Create Staff';
        });
    });

    async function approveStaff(id) {
        if (!await confirmAction('Approve this staff account?', { confirmLabel: 'Approve account' })) return;
        try {
            var response = await fetch('../api/staff/toggle_status.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ user_id: id, approve: true }) });
            var data = await response.json();
            if (!data.success) throw new Error(data.error || 'Approval failed.');
            location.reload();
        } catch (error) { showToast('Could not approve account', 'error', { message: error.message }); }
    }

    async function toggleStatus(id, current) {
        var action = current ? 'deactivate' : 'activate';
        if (!await confirmAction('Are you sure you want to ' + action + ' this staff member?')) return;
        fetch('../api/staff/toggle_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ user_id: id })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) location.reload();
            else showToast('Error: ' + d.error, 'error');
        });
    }

    async function resetPassword(id) {
        var newPass = await requestInput({ title: 'Reset staff password', label: 'New password (at least 6 characters)', type: 'password', minLength: 6, confirmLabel: 'Reset password' });
        if (newPass === null) return;
        fetch('../api/staff/reset_password.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ user_id: id, password: newPass })
        }).then(function(r) { return r.json(); }).then(function(d) {
            showToast(d.success ? 'Password reset successfully' : 'Error: ' + d.error, d.success ? 'success' : 'error');
        });
    }

    async function clearActivityLogs() {
        if (!await confirmAction('Clear ALL activity logs? This cannot be undone.')) return;
        if (!await confirmAction('Are you absolutely sure? This permanently deletes all activity records.')) return;
        fetch('../api/settings/clear_activity_logs.php', { method: 'POST' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                showToast('Activity logs cleared. ' + (d.deleted || 0) + ' records removed.', 'success');
                location.reload();
            } else {
                showToast('Error: ' + (d.error || 'Failed to clear logs'), 'error');
            }
        });
    }
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
