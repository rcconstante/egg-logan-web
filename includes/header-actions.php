<?php
require_once __DIR__ . '/../classes/AlertManager.php';
$utilityAlertManager = isset($alertManager) && $alertManager instanceof AlertManager ? $alertManager : new AlertManager();
$utilityAlerts = $utilityAlertManager->getActiveAlerts();
?>

                    
                    <div class="alert-badge-container">
                        <a href="#" class="alert-badge" onclick="toggleAlerts(event)" aria-label="Notifications" aria-controls="alertPanel" aria-expanded="false">
                            <?= e_icon('bell', 20) ?> <span class="badge-count"><?= $utilityAlertManager->getAlertCount() ?></span>
                        </a>
                        <div class="alert-panel" id="alertPanel">
                            <div class="alert-panel-header">
                                <h3>Active Alerts</h3>
                                <span class="alert-live-dot" title="Auto-updating"></span>
                            </div>
                            <div id="alertList">
                            <?php if (empty($utilityAlerts)): ?>
                                <div class="empty-state"><strong>No data yet</strong><p>No active alerts</p></div>
                            <?php else: ?>
                                <?php foreach ($utilityAlerts as $utilityAlert): ?>
                                    <?php
                                    $sevIcon = ['high' => 'alert-triangle', 'medium' => 'clock', 'low' => 'check-circle'][$utilityAlert['severity']] ?? 'bell';
                                    ?>
                                    <?php $alertLink = $utilityAlertManager->getAlertLink($utilityAlert); ?>
                                    <div class="alert-item alert-<?= $utilityAlert['severity'] ?><?= $alertLink ? ' alert-clickable' : '' ?>" data-alert-id="<?= $utilityAlert['id'] ?>"
                                         <?php if ($alertLink): ?>onclick="goToAlert(this, '<?= htmlspecialchars($alertLink, ENT_QUOTES) ?>')"<?php endif; ?>>
                                        <span class="alert-icon"><?= e_icon($sevIcon, 16) ?></span>
                                        <span class="alert-message">
                                            <?php if ($alertLink): ?>
                                            <a href="<?= htmlspecialchars($alertLink, ENT_QUOTES) ?>" class="alert-link"><?= htmlspecialchars($utilityAlert['message']) ?></a>
                                            <?php else: ?>
                                            <?= htmlspecialchars($utilityAlert['message']) ?>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($alertLink): ?>
                                        <span class="alert-go" title="Go to related page"><?= e_icon('chevron-right', 14) ?></span>
                                        <?php endif; ?>
                                        <button onclick="event.stopPropagation(); dismissAlert(<?= $utilityAlert['id'] ?>)" class="btn-small">Dismiss</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
<?php include __DIR__ . '/account-controls.php'; ?>
