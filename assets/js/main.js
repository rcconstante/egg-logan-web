var UI_ICON_SPRITE = new URL('../icons/hugeicons/sprite.svg', document.currentScript.src).href;

function createUiIcon(name, size) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('width', String(size || 18));
    svg.setAttribute('height', String(size || 18));
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', UI_ICON_SPRITE + '#' + name);
    svg.appendChild(use);
    return svg;
}

var csrfTokenValue = document.querySelector('[data-csrf-token]')?.dataset.csrfToken;
if (csrfTokenValue) {
    var nativeAppFetch = window.fetch.bind(window);
    window.fetch = function(input, options) {
        var init = Object.assign({}, options || {});
        var method = (init.method || input?.method || 'GET').toUpperCase();
        var url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, location.href);
        if (url.origin === location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            var headers = new Headers(init.headers || input?.headers || {});
            headers.set('X-CSRF-Token', csrfTokenValue); init.headers = headers;
        }
        return nativeAppFetch(input, init);
    };
}

document.addEventListener('DOMContentLoaded', function() {
    var rowActionMenus = new Map();
    var activeRowMenu = null;
    var actionMenuSequence = 0;
    function closeRowMenu(returnFocus) {
        if (!activeRowMenu) return;
        var control = activeRowMenu; activeRowMenu = null;
        control.menu.hidden = true; control.trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus && control.trigger.isConnected) control.trigger.focus();
    }
    function openRowMenu(control) {
        if (activeRowMenu === control) { closeRowMenu(false); return; }
        closeRowMenu(false);
        var rect = control.trigger.getBoundingClientRect();
        var width = Math.min(220, innerWidth - 24);
        control.menu.style.width = width + 'px';
        control.menu.style.left = Math.max(12, Math.min(rect.right - width, innerWidth - width - 12)) + 'px';
        var up = innerHeight - rect.bottom < 160 && rect.top > 160;
        control.menu.style.top = up ? 'auto' : (rect.bottom + 6) + 'px';
        control.menu.style.bottom = up ? (innerHeight - rect.top + 6) + 'px' : 'auto';
        control.menu.hidden = false; control.trigger.setAttribute('aria-expanded', 'true'); activeRowMenu = control;
        control.menu.querySelector('button:not(:disabled), a')?.focus({ preventScroll: true });
    }
    function enhanceRowActions(root) {
        var cells = root.matches?.('td') ? [root] : [];
        cells = cells.concat(Array.from(root.querySelectorAll('td')));
        cells.forEach(function(cell) {
            if (cell.dataset.rowActions || !cell.closest('table.table')) return;
            var actions = Array.from(cell.querySelectorAll('button, a')).filter(function(action) { return !action.closest('form') && !action.hasAttribute('data-row-menu-trigger'); });
            if (actions.length < 2) return;
            cell.dataset.rowActions = '1';
            var trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'btn-small icon-action row-menu-trigger';
            trigger.setAttribute('data-row-menu-trigger', ''); trigger.setAttribute('aria-haspopup', 'menu'); trigger.setAttribute('aria-expanded', 'false');
            var record = cell.closest('tr').querySelector('td')?.textContent.trim() || 'record';
            trigger.setAttribute('aria-label', 'Actions for ' + record); trigger.title = 'Actions'; trigger.appendChild(createUiIcon('more', 20));
            var menu = document.createElement('div'); menu.className = 'row-action-menu'; menu.id = 'rowActionMenu' + (++actionMenuSequence); menu.hidden = true; menu.setAttribute('role', 'menu'); menu.setAttribute('aria-label', 'Actions for ' + record);
            trigger.setAttribute('aria-controls', menu.id);
            actions.forEach(function(action) {
                var label = action.getAttribute('aria-label') || action.title || action.textContent.trim();
                action.classList.remove('icon-action'); action.classList.add('row-action-item'); action.setAttribute('role', 'menuitem');
                if (!action.textContent.trim()) { var caption = document.createElement('span'); caption.textContent = label; action.appendChild(caption); }
                menu.appendChild(action);
            });
            cell.querySelector('.rc-actions-group')?.remove(); cell.appendChild(trigger); document.body.appendChild(menu);
            var control = { trigger: trigger, menu: menu }; rowActionMenus.set(trigger, control);
            trigger.addEventListener('click', function() { openRowMenu(control); });
            trigger.addEventListener('keydown', function(event) { if (event.key === 'ArrowDown') { event.preventDefault(); openRowMenu(control); } });
            menu.addEventListener('keydown', function(event) {
                var items = Array.from(menu.querySelectorAll('[role="menuitem"]:not(:disabled)')); var current = items.indexOf(document.activeElement); var next;
                if (event.key === 'ArrowDown') next = (current + 1) % items.length;
                if (event.key === 'ArrowUp') next = (current - 1 + items.length) % items.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = items.length - 1;
                if (items[next]) { event.preventDefault(); items[next].focus(); }
            });
        });
        rowActionMenus.forEach(function(control, trigger) { if (!trigger.isConnected) { if (activeRowMenu === control) closeRowMenu(false); control.menu.remove(); rowActionMenus.delete(trigger); } });
    }
    document.addEventListener('click', function(event) {
        if (!activeRowMenu || event.target.closest('[data-row-menu-trigger]')) return;
        if (event.target.closest('[role="menuitem"]') || !activeRowMenu.menu.contains(event.target)) closeRowMenu(false);
    });
    document.addEventListener('keydown', function(event) { if (!activeRowMenu) return; if (event.key === 'Escape') { event.preventDefault(); closeRowMenu(true); } else if (event.key === 'Tab') closeRowMenu(false); });
    document.addEventListener('focusin', function(event) { if (activeRowMenu && !activeRowMenu.menu.contains(event.target) && event.target !== activeRowMenu.trigger) closeRowMenu(false); });
    window.addEventListener('resize', function() { closeRowMenu(false); });
    window.addEventListener('scroll', function(event) { if (activeRowMenu && !activeRowMenu.menu.contains(event.target)) closeRowMenu(false); }, true);

    var tableObserver = new ResizeObserver(function(entries) {
        entries.forEach(function(entry) { entry.target.style.setProperty('--table-viewport', Math.max(0, entry.contentRect.width - 28) + 'px'); });
    });
    function enhanceTables(root) {
        enhanceRowActions(root);
        var tables = root.matches?.('table.table') ? [root] : [];
        tables = tables.concat(Array.from(root.querySelectorAll('table.table')));
        tables.forEach(function(table) {
            if (table.parentElement.classList.contains('table-scroll')) return;
            var wrapper = document.createElement('div');
            wrapper.className = 'table-scroll';
            wrapper.tabIndex = 0;
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', (table.closest('.card')?.querySelector('h3, h4')?.textContent.trim() || 'Records') + ' table');
            table.before(wrapper);
            wrapper.appendChild(table);
            tableObserver.observe(wrapper);
        });
    }
    enhanceTables(document);
    new MutationObserver(function(records) {
        records.forEach(function(record) {
            record.addedNodes.forEach(function(node) { if (node.nodeType === 1 && !node.classList.contains('table-scroll')) enhanceTables(node); });
            record.removedNodes.forEach(function(node) {
                if (node.nodeType !== 1 || node.isConnected) return;
                if (node.classList.contains('table-scroll')) tableObserver.unobserve(node);
                node.querySelectorAll('.table-scroll').forEach(function(wrapper) { tableObserver.unobserve(wrapper); });
            });
        });
        rowActionMenus.forEach(function(control, trigger) { if (!trigger.isConnected) { if (activeRowMenu === control) closeRowMenu(false); control.menu.remove(); rowActionMenus.delete(trigger); } });
    }).observe(document.querySelector('.app-container') || document.body, { childList: true, subtree: true });

    function updateHeaderClock() {
        var now = new Date();
        document.querySelectorAll('[data-header-clock]').forEach(function(clock) {
            clock.dateTime = now.toISOString();
            clock.querySelector('[data-clock-date]').textContent = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(now);
            clock.querySelector('[data-clock-time]').textContent = new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(now);
        });
    }
    updateHeaderClock();
    setInterval(updateHeaderClock, 30000);

    var settingsNav = document.querySelector('.settings-navigation');
    if (settingsNav) {
        var sectionLinks = Array.from(settingsNav.querySelectorAll('a'));
        function selectSettingsSection(hash) {
            var selected = sectionLinks.find(function(link) { return link.hash === hash; }) || sectionLinks[0];
            sectionLinks.forEach(function(link) {
                var active = link === selected;
                link.setAttribute('aria-current', active ? 'location' : 'false');
                document.getElementById(link.hash.slice(1)).hidden = !active;
            });
        }
        sectionLinks.forEach(function(link) { link.addEventListener('click', function(event) { event.preventDefault(); history.replaceState(null, '', link.hash); selectSettingsSection(link.hash); }); });
        window.addEventListener('hashchange', function() { selectSettingsSection(location.hash); });
        selectSettingsSection(location.hash);
    }

    document.addEventListener('click', function(event) {
        var trigger = event.target.closest('[data-qr-preview]');
        if (!trigger) return;
        var dialog = document.createElement('dialog');
        dialog.className = 'confirmation-dialog qr-print-dialog';
        var header = document.createElement('div'); header.className = 'qr-modal-header';
        var title = document.createElement('h2'); title.id = 'qrPrintTitle'; title.textContent = 'Print QR labels';
        dialog.setAttribute('aria-labelledby', title.id);
        var close = document.createElement('button'); close.type = 'button'; close.className = 'btn-small icon-action'; close.setAttribute('aria-label', 'Close print selection'); close.appendChild(createUiIcon('close', 20)); close.onclick = function() { dialog.close(); };
        header.append(title, close);
        var description = document.createElement('p'); description.textContent = 'Select the reusable labels to print on A4 paper. New batches will appear when staff scan the same code.';
        var tools = document.createElement('div'); tools.className = 'qr-selection-tools';
        var all = document.createElement('button'); all.type = 'button'; all.className = 'btn-small'; all.textContent = 'Select all';
        var none = document.createElement('button'); none.type = 'button'; none.className = 'btn-small'; none.textContent = 'Clear selection';
        var count = document.createElement('span'); count.setAttribute('aria-live', 'polite');
        tools.append(all, none, count);
        var grid = document.createElement('div'); grid.className = 'qr-options-grid';
        var options = [];
        document.querySelectorAll('.variant-qr-card').forEach(function(card) {
            var image = card.querySelector('img'); if (!image) return;
            var label = document.createElement('label'); label.className = 'qr-print-option';
            var check = document.createElement('input'); check.type = 'checkbox'; check.checked = !trigger.dataset.qrPreview || trigger.dataset.qrPreview === card.dataset.qrCode;
            var name = card.querySelector('div').textContent.trim(); check.setAttribute('aria-label', 'Print ' + name);
            var text = document.createElement('strong'); text.textContent = name;
            var preview = image.cloneNode(); preview.width = 96; preview.height = 96; preview.removeAttribute('style');
            label.append(check, preview, text); grid.appendChild(label);
            options.push({ check: check, image: image, name: name });
        });
        var footer = document.createElement('div'); footer.className = 'confirmation-actions';
        var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn-secondary'; cancel.textContent = 'Cancel'; cancel.onclick = function() { dialog.close(); };
        var print = document.createElement('button'); print.type = 'button'; print.className = 'btn btn-primary'; print.append(createUiIcon('printer', 18), document.createTextNode('Print selected labels'));
        footer.append(cancel, print);
        function updateCount() { var selected = options.filter(function(option) { return option.check.checked; }).length; count.textContent = selected + ' selected'; print.disabled = selected === 0; }
        grid.addEventListener('change', updateCount);
        all.onclick = function() { options.forEach(function(option) { option.check.checked = true; }); updateCount(); };
        none.onclick = function() { options.forEach(function(option) { option.check.checked = false; }); updateCount(); };
        print.addEventListener('click', async function() {
            var selected = options.filter(function(option) { return option.check.checked; }); if (!selected.length) return;
            print.disabled = true;
            try {
                await Promise.all(selected.map(function(option) { return option.image.decode(); }));
                document.getElementById('qrPrintOutput')?.remove();
                var output = document.createElement('section'); output.id = 'qrPrintOutput';
                selected.forEach(function(option) {
                    var label = document.createElement('article'); label.className = 'qr-print-label';
                    var name = document.createElement('strong'); name.textContent = option.name;
                    var image = option.image.cloneNode(); image.removeAttribute('style');
                    label.append(name, image); output.appendChild(label);
                });
                document.body.appendChild(output);
                document.body.classList.add('qr-printing');
                window.print();
            } catch (error) { showToast('Could not prepare QR labels', 'error', { message: 'Please reload the page and try again.' }); }
            finally { updateCount(); }
        });
        dialog.append(header, description, tools, grid, footer);
        dialog.addEventListener('close', function() { dialog.remove(); if (trigger.isConnected) trigger.focus({ preventScroll: true }); }, { once: true });
        document.body.appendChild(dialog); updateCount(); dialog.showModal();
    });
    window.addEventListener('afterprint', function() { document.getElementById('qrPrintOutput')?.remove(); document.body.classList.remove('qr-printing'); });
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('../service-worker.js').then(function(reg) {
            console.log('SW registered:', reg.scope);
        }).catch(function(err) {
            console.log('SW registration failed:', err);
        });
    }

    var hamburger = document.getElementById('hamburgerBtn');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var resizeButton = document.getElementById('sidebarResizeBtn');

    function setSidebarMinimized(minimized) {
        document.documentElement.classList.toggle('sidebar-minimized', minimized);
        if (!resizeButton) return;
        var label = minimized ? 'Maximize sidebar' : 'Minimize sidebar';
        resizeButton.setAttribute('aria-expanded', String(!minimized));
        resizeButton.setAttribute('aria-label', label);
        resizeButton.title = label;
    }

    try {
        setSidebarMinimized(localStorage.getItem('sidebarMinimized') === 'true');
    } catch (error) {
        setSidebarMinimized(false);
    }
    if (resizeButton) resizeButton.addEventListener('click', function() {
        var minimized = !document.documentElement.classList.contains('sidebar-minimized');
        setSidebarMinimized(minimized);
        try { localStorage.setItem('sidebarMinimized', String(minimized)); } catch (error) { /* Storage is optional. */ }
        window.dispatchEvent(new Event('resize'));
    });

    var accountTrigger = document.querySelector('.account-trigger');
    var accountMenu = document.getElementById('accountMenu');
    function closeAccountMenu(returnFocus) {
        if (!accountTrigger || !accountMenu) return;
        accountMenu.hidden = true;
        accountTrigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) accountTrigger.focus();
    }
    if (accountTrigger && accountMenu) {
        accountTrigger.addEventListener('click', function() {
            var open = accountMenu.hidden;
            accountMenu.hidden = !open;
            accountTrigger.setAttribute('aria-expanded', String(open));
        });
        accountTrigger.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                accountMenu.hidden = false;
                accountTrigger.setAttribute('aria-expanded', 'true');
                accountMenu.querySelector('a').focus();
            }
        });
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.account-controls')) closeAccountMenu(false);
        });
        document.addEventListener('focusin', function(event) {
            if (!event.target.closest('.account-controls')) closeAccountMenu(false);
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !accountMenu.hidden) closeAccountMenu(true);
        });
    }


    function toggleSidebar() {
        if (!sidebar) return;
        var open = sidebar.classList.toggle('open');
        if (overlay) overlay.classList.toggle('show', open);
        if (hamburger) hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeSidebar() {
        if (!sidebar && !overlay) return;
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
        if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
    }

    if (hamburger) {
        hamburger.setAttribute('aria-expanded', 'false');
        hamburger.addEventListener('click', toggleSidebar);
    }
    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });

    var closeBtn = document.querySelector('.hamburger-close');
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

    var sidebarLinks = document.querySelectorAll('.sidebar .nav-list a');
    sidebarLinks.forEach(function(link) {
        link.addEventListener('click', closeSidebar);
    });
});

var confirmationOpen = false;

function confirmAction(message, options) {
    options = options || {};
    if (confirmationOpen) return Promise.resolve(false);
    confirmationOpen = true;
    var opener = document.activeElement;
    return new Promise(function(resolve) {
        var dialog = document.createElement('dialog');
        dialog.className = 'confirmation-dialog';
        dialog.setAttribute('aria-labelledby', 'confirmationTitle');
        dialog.setAttribute('aria-describedby', 'confirmationMessage');
        var heading = document.createElement('h2');
        heading.id = 'confirmationTitle';
        heading.textContent = options.title || 'Confirm action';
        var detail = document.createElement('p');
        detail.id = 'confirmationMessage';
        detail.textContent = message;
        var actions = document.createElement('form');
        actions.method = 'dialog';
        actions.className = 'confirmation-actions';
        var cancel = document.createElement('button');
        cancel.type = 'submit';
        cancel.value = 'cancel';
        cancel.className = 'btn btn-secondary';
        cancel.textContent = 'Cancel';
        cancel.autofocus = true;
        var confirmButton = document.createElement('button');
        confirmButton.type = 'submit';
        confirmButton.value = 'confirm';
        confirmButton.className = 'btn btn-primary';
        confirmButton.textContent = options.confirmLabel || 'Continue';
        actions.append(cancel, confirmButton);
        dialog.append(heading, detail, actions);
        dialog.addEventListener('close', function() {
            var confirmed = dialog.returnValue === 'confirm';
            dialog.remove();
            confirmationOpen = false;
            if (opener && opener.isConnected) opener.focus({ preventScroll: true });
            resolve(confirmed);
        }, { once: true });
        dialog.addEventListener('click', function(event) {
            if (event.target !== dialog) return;
            var rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close('cancel');
        });
        document.body.appendChild(dialog);
        dialog.showModal();
    });
}

function requestInput(options) {
    return new Promise(function(resolve) {
        var opener = document.activeElement;
        var dialog = document.createElement('dialog');
        dialog.className = 'confirmation-dialog input-dialog';
        var form = document.createElement('form');
        var heading = document.createElement('h2');
        heading.id = 'inputDialogTitle';
        heading.textContent = options.title;
        dialog.setAttribute('aria-labelledby', heading.id);
        var label = document.createElement('label');
        label.htmlFor = 'actionInput';
        label.textContent = options.label;
        var fieldGroup = document.createElement('div');
        fieldGroup.className = 'dialog-field';
        var field = document.createElement('input');
        field.id = label.htmlFor;
        field.type = options.type || 'text';
        field.required = true;
        if (options.min !== undefined) field.min = options.min;
        if (options.minLength) field.minLength = options.minLength;
        if (options.pattern) field.pattern = options.pattern;
        if (options.value !== undefined) field.value = options.value;
        if (field.type === 'password') field.autocomplete = 'new-password';
        fieldGroup.appendChild(field);
        if (field.type === 'password') {
            var reveal = document.createElement('button');
            reveal.type = 'button';
            reveal.className = 'dialog-reveal';
            reveal.setAttribute('aria-label', 'Show password');
            reveal.appendChild(createUiIcon('eye', 18));
            reveal.addEventListener('click', function() {
                var show = field.type === 'password';
                field.type = show ? 'text' : 'password';
                reveal.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                reveal.replaceChildren(createUiIcon(show ? 'eye-off' : 'eye', 18));
            });
            fieldGroup.appendChild(reveal);
        }
        var actions = document.createElement('div');
        actions.className = 'confirmation-actions';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-secondary';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', function() { dialog.close(); });
        var submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'btn btn-primary';
        submit.textContent = options.confirmLabel || 'Continue';
        actions.append(cancel, submit);
        form.append(heading, label, fieldGroup, actions);
        form.addEventListener('submit', function(event) { event.preventDefault(); dialog.close('submit'); });
        dialog.appendChild(form);
        dialog.addEventListener('close', function() {
            var value = dialog.returnValue === 'submit' ? field.value : null;
            field.value = '';
            dialog.remove();
            if (opener?.isConnected) opener.focus({ preventScroll: true });
            resolve(value);
        }, { once: true });
        document.body.appendChild(dialog);
        dialog.showModal();
        field.focus();
    });
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount || 0).toFixed(2);
}

var TOAST_ICONS = { success: 'check-circle', error: 'alert-triangle', warning: 'alert-triangle', info: 'info' };

function toastStack() {
    var stack = document.querySelector('.toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        stack.setAttribute('role', 'region');
        stack.setAttribute('aria-label', 'Notifications');
        document.body.appendChild(stack);
    }
    return stack;
}

function dismissToast(toast) {
    if (!toast || toast.dataset.leaving) return;
    toast.dataset.leaving = '1';
    toast.classList.add('is-leaving');
    // Remove only after the exit animation finishes, with a fallback in case the
    // animation never fires (reduced motion, background tab).
    var removed = false;
    var drop = function() {
        if (removed) return;
        removed = true;
        toast.remove();
    };
    toast.addEventListener('animationend', drop);
    setTimeout(drop, 400);
}

/**
 * Pops a transient notification into the top-right corner.
 * @param {string} title    headline text
 * @param {string} [type]   success | error | warning | info
 * @param {object} [opts]   { message, duration } -- duration 0 keeps it up until dismissed
 */
function showToast(title, type, opts) {
    opts = opts || {};
    type = TOAST_ICONS[type] ? type : 'info';
    var duration = opts.duration === undefined ? 4000 : opts.duration;

    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    // Errors interrupt, everything else is announced politely.
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

    var icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.appendChild(createUiIcon(TOAST_ICONS[type], 20));

    var body = document.createElement('div');
    body.className = 'toast-body';

    var heading = document.createElement('div');
    heading.className = 'toast-title';
    heading.textContent = title;
    body.appendChild(heading);

    if (opts.message) {
        var detail = document.createElement('div');
        detail.className = 'toast-message';
        detail.textContent = opts.message;
        body.appendChild(detail);
    }

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.appendChild(createUiIcon('close', 16));
    close.addEventListener('click', function() { dismissToast(toast); });

    toast.appendChild(icon);
    toast.appendChild(body);
    toast.appendChild(close);
    toastStack().appendChild(toast);

    if (duration > 0) setTimeout(function() { dismissToast(toast); }, duration);
    return toast;
}

/**
 * Back-compat wrapper for the old (message, type) signature.
 */
function showNotification(message, type) {
    return showToast(message, type || 'info');
}

function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
}

(function() {
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }
})();
