    var alertPollInterval = null;
    var alertCloseTimeout = null;

    function toggleAlerts(e) {
        if (e) e.preventDefault();
        var panel = document.getElementById('alertPanel');
        var isOpen = panel.classList.contains('open');
        if (isOpen) {
            closeAlerts();
        } else {
            clearTimeout(alertCloseTimeout);
            document.querySelector('.alert-badge').setAttribute('aria-expanded', 'true');
            panel.classList.add('open');
            panel.style.display = 'block';
            // force reflow then animate
            requestAnimationFrame(function() { panel.style.opacity = '1'; panel.style.transform = 'translateY(0)'; });
            refreshAlerts();
            alertPollInterval = setInterval(refreshAlerts, 30000);
        }
    }

    function closeAlerts() {
        var panel = document.getElementById('alertPanel');
        if (!panel || !panel.classList.contains('open')) return;
        document.querySelector('.alert-badge').setAttribute('aria-expanded', 'false');
        panel.style.opacity = '0';
        panel.style.transform = 'translateY(-8px)';
        alertCloseTimeout = setTimeout(function() {
            panel.classList.remove('open');
            panel.style.display = 'none';
        }, 200);
        clearInterval(alertPollInterval);
    }

    // close on outside click
    document.addEventListener('click', function(e) {
        var panel = document.getElementById('alertPanel');
        var badge = document.querySelector('.alert-badge-container');
        if (panel && panel.classList.contains('open') && !panel.contains(e.target) && !badge.contains(e.target)) {
            closeAlerts();
        }
    });

    // close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAlerts();
    });

    function refreshAlerts() {
        fetch('dashboard.php?ajax=alerts', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return;
                var list = document.getElementById('alertList');
                if (list) {
                    list.innerHTML = d.html;
                    bindAlertLinks(list, d.links || {});
                }
                var badge = document.querySelector('.alert-badge .badge-count');
                if (badge) {
                    badge.textContent = d.count;
                    badge.style.display = d.count > 0 ? '' : 'none';
                }
                // update sidebar badge if present
                var sideBadge = document.querySelector('.nav-badge-alert');
                if (d.expired_count > 0) {
                    if (!sideBadge) {
                        var invLink = document.querySelector('a[href="inventory.php"]');
                        if (invLink) {
                            var b = document.createElement('span');
                            b.className = 'nav-badge nav-badge-alert';
                            b.textContent = d.expired_count;
                            invLink.appendChild(b);
                        }
                    } else {
                        sideBadge.textContent = d.expired_count;
                    }
                } else if (sideBadge) {
                    sideBadge.remove();
                }
            })
            .catch(function() {});
    }

    function goToAlert(el, url) {
        var link = el.querySelector('a.alert-link');
        if (link) { window.location.href = link.href; return; }
        window.location.href = url;
    }

    function bindAlertLinks(root, links) {
        Object.keys(links).forEach(function(id) {
            var item = root.querySelector('[data-alert-id="' + id + '"]');
            if (item) item.setAttribute('data-href', links[id]);
        });
    }

    document.getElementById('alertList')?.addEventListener('click', function(e) {
        if (e.target.closest('button')) return;
        var item = e.target.closest('.alert-clickable[data-href]');
        if (item) window.location.href = item.getAttribute('data-href');
    });

    function dismissAlert(id) {
        fetch('../api/settings/resolve_alert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({alert_id: id})
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                var item = document.querySelector('[data-alert-id="' + id + '"]');
                if (item) {
                    item.style.transition = 'opacity 0.25s, max-height 0.25s, padding 0.25s, margin 0.25s';
                    item.style.opacity = '0';
                    item.style.maxHeight = '0';
                    item.style.paddingTop = '0';
                    item.style.paddingBottom = '0';
                    item.style.marginBottom = '0';
                    item.style.overflow = 'hidden';
                    setTimeout(function() { item.remove(); refreshAlerts(); }, 250);
                } else {
                    refreshAlerts();
                }
            }
        });
    }

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && document.getElementById('alertPanel')?.classList.contains('open')) refreshAlerts();
    });
