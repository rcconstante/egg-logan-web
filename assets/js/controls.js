(function() {
    'use strict';
    var controls = new Map();
    var active = null;
    var sequence = 0;

    function closeControl(focus) {
        if (!active) return;
        var control = active;
        active = null;
        control.popup.hidden = true;
        control.trigger.setAttribute('aria-expanded', 'false');
        if (focus && control.trigger.isConnected) control.trigger.focus({ preventScroll: true });
    }
    function position(control) {
        var rect = control.trigger.getBoundingClientRect();
        var width = Math.min(control.calendar ? 308 : Math.max(rect.width, 180), innerWidth - 24);
        var below = innerHeight - rect.bottom;
        var up = below < (control.calendar ? 380 : 180) && rect.top > below;
        control.popup.style.width = width + 'px';
        control.popup.style.left = Math.max(12, Math.min(rect.left, innerWidth - width - 12)) + 'px';
        control.popup.style.top = up ? 'auto' : rect.bottom + 6 + 'px';
        control.popup.style.bottom = up ? innerHeight - rect.top + 6 + 'px' : 'auto';
        control.popup.style.maxHeight = Math.max(100, Math.min(control.calendar ? 460 : 320, (up ? rect.top : below) - 16)) + 'px';
    }
    function labelFor(field) {
        return field.getAttribute('aria-label') || field.labels?.[0]?.textContent.trim() || field.closest('.form-group')?.querySelector('label')?.textContent.trim() || field.options?.[0]?.textContent.trim() || 'Choose date';
    }
    function createControl(field, calendar) {
        var oldWrapper = field.closest('.custom-select, .custom-date');
        if (oldWrapper) oldWrapper.replaceWith(field);
        var wrapper = document.createElement('div');
        wrapper.className = calendar ? 'custom-date' : 'custom-select';
        if (field.classList.contains('egg-size-select')) wrapper.classList.add('sale-size-control');
        if (field.hasAttribute('data-export-select')) wrapper.classList.add('export-control');
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'custom-select-trigger';
        trigger.setAttribute('aria-haspopup', calendar ? 'dialog' : 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        var icon = field.dataset.controlIcon || (calendar ? 'calendar' : 'chevron-right');
        if (field.dataset.controlIcon) trigger.appendChild(createUiIcon(icon, 18));
        var caption = document.createElement('span');
        caption.className = 'control-caption';
        trigger.appendChild(caption);
        var arrow = createUiIcon(calendar ? 'calendar' : 'chevron-right', 17);
        arrow.classList.add(calendar ? 'calendar-icon' : 'control-arrow');
        trigger.appendChild(arrow);
        var popup = document.createElement('div');
        popup.className = calendar ? 'custom-calendar' : 'custom-select-menu';
        popup.id = 'controlPopup' + (++sequence);
        popup.setAttribute('role', calendar ? 'dialog' : 'listbox');
        popup.setAttribute('aria-label', labelFor(field));
        popup.hidden = true;
        trigger.setAttribute('aria-controls', popup.id);
        field.before(wrapper);
        wrapper.append(field, trigger);
        (field.closest('dialog') || document.body).appendChild(popup);
        field.classList.remove('enhanced-select-native');
        field.classList.add('enhanced-field-native');
        field.setAttribute('aria-hidden', 'true');
        field.tabIndex = -1;
        var control = { field: field, wrapper: wrapper, trigger: trigger, popup: popup, caption: caption, calendar: calendar };
        controls.set(field, control);
        field.addEventListener('focus', function() { trigger.focus(); });
        field.addEventListener('invalid', function(event) {
            event.preventDefault();
            trigger.setAttribute('aria-invalid', 'true');
            open(control);
        });
        field.addEventListener('change', function() { sync(control); });
        trigger.addEventListener('click', function() { if (active === control) closeControl(false); else open(control); });
        trigger.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); open(control); }
        });
        return control;
    }
    function sync(control) {
        var field = control.field;
        control.trigger.disabled = field.disabled;
        control.caption.textContent = control.calendar ? (field.value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(parseDate(field.value)) : 'Choose date') : (field.selectedOptions[0]?.textContent || 'No options available');
        control.trigger.setAttribute('aria-label', labelFor(field) + ': ' + control.caption.textContent);
        if (field.validity.valid) control.trigger.removeAttribute('aria-invalid');
    }
    function selectValue(control, value) {
        control.field.value = value;
        closeControl(true);
        control.field.dispatchEvent(new Event('input', { bubbles: true }));
        control.field.dispatchEvent(new Event('change', { bubbles: true }));
        sync(control);
    }
    function renderOptions(control) {
        control.popup.replaceChildren();
        Array.from(control.field.options).forEach(function(option) {
            if (option.hidden) return;
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'custom-select-option';
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', String(option.selected));
            button.disabled = option.disabled || (option.parentElement.tagName === 'OPTGROUP' && option.parentElement.disabled);
            button.textContent = option.textContent;
            button.addEventListener('click', function() { selectValue(control, option.value); });
            control.popup.appendChild(button);
        });
        control.popup.onkeydown = function(event) {
            var buttons = Array.from(control.popup.querySelectorAll('button:not(:disabled)'));
            var index = buttons.indexOf(document.activeElement);
            var next;
            if (event.key === 'ArrowDown') next = (index + 1) % buttons.length;
            if (event.key === 'ArrowUp') next = (index - 1 + buttons.length) % buttons.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = buttons.length - 1;
            if (buttons[next]) { event.preventDefault(); buttons[next].focus(); }
            if (event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey) {
                control.search = (control.search || '') + event.key.toLowerCase();
                clearTimeout(control.searchTimeout);
                control.searchTimeout = setTimeout(function() { control.search = ''; }, 600);
                buttons.find(function(button) { return button.textContent.toLowerCase().startsWith(control.search); })?.focus();
            }
        };
    }
    function parseDate(value) {
        var parts = value.split('-').map(Number);
        var date = new Date(0);
        date.setHours(12, 0, 0, 0);
        date.setFullYear(parts[0], parts[1] - 1, parts[2]);
        return date;
    }
    function dateValue(date) { return String(date.getFullYear()).padStart(4, '0') + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0'); }
    function allowed(control, date) {
        var value = dateValue(date);
        var field = control.field;
        if (field.min && value < field.min || field.max && value > field.max) return false;
        var step = Number(field.step || 1);
        if (step > 1) {
            var base = parseDate(field.min || field.getAttribute('value') || '1970-01-01');
            var day = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
            var baseDay = Date.UTC(base.getFullYear(), base.getMonth(), base.getDate());
            return (Math.round((day - baseDay) / 86400000) % step) === 0;
        }
        return true;
    }
    function calendarButton(label, icon, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'calendar-nav';
        button.setAttribute('aria-label', label);
        button.appendChild(createUiIcon(icon, 18));
        button.addEventListener('click', onClick);
        return button;
    }
    function renderCalendar(control) {
        var popup = control.popup;
        popup.replaceChildren();
        var header = document.createElement('div');
        header.className = 'calendar-heading';
        var label = document.createElement('strong');
        label.setAttribute('aria-live', 'polite');
        label.textContent = new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(control.month);
        function move(months) { control.month.setMonth(control.month.getMonth() + months); renderCalendar(control); }
        header.append(calendarButton('Previous year', 'chevron-left', function() { move(-12); }), calendarButton('Previous month', 'chevron-left', function() { move(-1); }), label, calendarButton('Next month', 'chevron-right', function() { move(1); }), calendarButton('Next year', 'chevron-right', function() { move(12); }));
        popup.appendChild(header);
        var grid = document.createElement('div');
        grid.className = 'calendar-grid';
        grid.setAttribute('role', 'grid');
        grid.setAttribute('aria-label', label.textContent);
        var week = document.createElement('div');
        week.className = 'calendar-week';
        week.setAttribute('role', 'row');
        ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(function(day) { var cell = document.createElement('span'); cell.setAttribute('role', 'columnheader'); cell.textContent = day; week.appendChild(cell); });
        grid.appendChild(week);
        var first = new Date(control.month);
        first.setDate(1 - first.getDay());
        var focusValue = control.focusDate || control.field.value || dateValue(new Date());
        for (var row = 0; row < 6; row++) {
            var line = document.createElement('div');
            line.className = 'calendar-week';
            line.setAttribute('role', 'row');
            for (var column = 0; column < 7; column++) {
                var date = new Date(first);
                date.setDate(first.getDate() + row * 7 + column);
                var value = dateValue(date);
                var dayButton = document.createElement('button');
                dayButton.type = 'button';
                dayButton.className = 'calendar-day' + (date.getMonth() !== control.month.getMonth() ? ' adjacent-month' : '');
                dayButton.setAttribute('role', 'gridcell');
                dayButton.setAttribute('aria-label', new Intl.DateTimeFormat('en-PH', { dateStyle: 'full' }).format(date));
                dayButton.setAttribute('aria-selected', String(control.field.value === value));
                dayButton.dataset.date = value;
                dayButton.textContent = date.getDate();
                dayButton.disabled = !allowed(control, date);
                dayButton.tabIndex = value === focusValue ? 0 : -1;
                if (value === dateValue(new Date())) dayButton.setAttribute('aria-current', 'date');
                dayButton.addEventListener('click', function(event) { selectValue(control, event.currentTarget.dataset.date); });
                line.appendChild(dayButton);
            }
            grid.appendChild(line);
        }
        grid.addEventListener('keydown', function(event) {
            if (!event.target.dataset.date) return;
            var date = parseDate(event.target.dataset.date);
            var delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
            if (delta) date.setDate(date.getDate() + delta);
            else if (event.key === 'Home') date.setDate(date.getDate() - date.getDay());
            else if (event.key === 'End') date.setDate(date.getDate() + 6 - date.getDay());
            else if (event.key === 'PageUp' || event.key === 'PageDown') { date.setDate(1); date.setMonth(date.getMonth() + (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1)); }
            else return;
            event.preventDefault();
            control.focusDate = dateValue(date);
            control.month = parseDate(control.focusDate.slice(0, 7) + '-01');
            renderCalendar(control);
            control.popup.querySelector('[data-date="' + control.focusDate + '"]:not(:disabled)')?.focus();
        });
        popup.appendChild(grid);
        var footer = document.createElement('div');
        footer.className = 'calendar-footer';
        var today = document.createElement('button'); today.type = 'button'; today.className = 'btn-small'; today.textContent = 'Today'; today.disabled = !allowed(control, new Date()); today.onclick = function() { selectValue(control, dateValue(new Date())); }; footer.appendChild(today);
        if (!control.field.required) { var clear = document.createElement('button'); clear.type = 'button'; clear.className = 'btn-small'; clear.textContent = 'Clear'; clear.onclick = function() { selectValue(control, ''); }; footer.appendChild(clear); }
        popup.appendChild(footer);
    }
    function open(control) {
        if (control.field.disabled) return;
        closeControl(false);
        sync(control);
        if (control.calendar) {
            var value = control.field.value || dateValue(new Date());
            if (control.field.min && value < control.field.min) value = control.field.min;
            if (control.field.max && value > control.field.max) value = control.field.max;
            control.focusDate = value;
            control.month = parseDate(value.slice(0, 7) + '-01');
            renderCalendar(control);
        } else renderOptions(control);
        position(control);
        control.popup.hidden = false;
        control.trigger.setAttribute('aria-expanded', 'true');
        active = control;
        (control.popup.querySelector(control.calendar ? '[tabindex="0"]:not(:disabled)' : '[aria-selected="true"]:not(:disabled)') || control.popup.querySelector('button:not(:disabled)'))?.focus({ preventScroll: true });
    }
    function enhance(root) {
        root = root || document;
        var fields = root.matches?.('select, input[type="date"]') ? [root] : [];
        fields = fields.concat(Array.from(root.querySelectorAll('select, input[type="date"]')));
        fields.forEach(function(field) {
            if (!field.closest('.app-container') || (field.tagName === 'SELECT' && (field.multiple || field.size > 1))) return;
            if (!controls.has(field)) createControl(field, field.type === 'date');
            sync(controls.get(field));
        });
        controls.forEach(function(control, field) { if (!field.isConnected) { if (active === control) closeControl(false); control.popup.remove(); controls.delete(field); } });
    }
    window.enhanceControls = enhance;
    document.addEventListener('DOMContentLoaded', function() {
        enhance();
        var observer = new MutationObserver(function(records) {
            records.forEach(function(record) {
                if (record.type === 'attributes') {
                    var field = record.target.closest('select, input[type="date"]');
                    if (controls.has(field)) sync(controls.get(field));
                    return;
                }
                record.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && !node.closest('.custom-select-menu, .custom-calendar')) enhance(node);
                });
                var select = record.target.closest('select');
                if (controls.has(select)) sync(controls.get(select));
            });
            controls.forEach(function(control, field) {
                if (!field.isConnected) {
                    if (active === control) closeControl(false);
                    control.popup.remove();
                    clearTimeout(control.searchTimeout);
                    controls.delete(field);
                }
            });
        });
        observer.observe(document.querySelector('.app-container'), { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected', 'required', 'min', 'max', 'step'] });
        document.addEventListener('change', function(event) { var form = event.target.closest('form[data-auto-filter]'); if (form) form.requestSubmit(); });
        document.addEventListener('reset', function() { queueMicrotask(function() { controls.forEach(sync); }); });
    });
    document.addEventListener('click', function(event) { if (active && !active.wrapper.contains(event.target) && !active.popup.contains(event.target)) closeControl(false); });
    document.addEventListener('focusin', function(event) { if (active && !active.wrapper.contains(event.target) && !active.popup.contains(event.target)) closeControl(false); });
    document.addEventListener('close', function(event) { if (active && event.target.contains(active.wrapper)) closeControl(false); }, true);
    document.addEventListener('keydown', function(event) { if (event.key === 'Escape' && active) { event.preventDefault(); closeControl(true); } if (event.key === 'Tab' && active) closeControl(false); });
    window.addEventListener('resize', function() { closeControl(false); });
    window.addEventListener('scroll', function(event) { if (active && !active.popup.contains(event.target)) closeControl(false); }, true);
})();
