// Enhanced multiple select (no jQuery, ADR-010).
//
// Every <select multiple> is upgraded on page load into a field that shows
// the chosen options as removable chips and opens a panel with a search box,
// checkboxes and "Todos / Ninguno" shortcuts, so users never need
// Ctrl/Cmd + click. The native select stays in the DOM (visually hidden) and
// is the single source of truth: the form submits exactly as before, server
// validation is unchanged, and a `change` event is dispatched on every edit.
//
// Opt out with `data-native-select` on the <select>.

const SEARCH_THRESHOLD = 6;
let sequence = 0;

function enhance(select) {
    if (select.dataset.multiSelectReady || select.hasAttribute('data-native-select')) {
        return;
    }
    select.dataset.multiSelectReady = '1';

    const uid = `ms-${++sequence}`;
    const labelEl = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`) : null;
    const labelText = (labelEl?.textContent || select.getAttribute('aria-label') || 'opciones').replace('*', '').trim();
    const options = () => Array.from(select.options).filter((option) => option.value !== '');

    const root = document.createElement('div');
    root.className = 'ms';
    if (select.classList.contains('form-select-sm')) root.classList.add('ms-sm');

    const control = document.createElement('div');
    control.className = 'ms-control form-select';
    control.tabIndex = select.disabled ? -1 : 0;
    control.setAttribute('role', 'combobox');
    control.setAttribute('aria-haspopup', 'listbox');
    control.setAttribute('aria-expanded', 'false');
    control.setAttribute('aria-controls', `${uid}-panel`);
    control.setAttribute('aria-label', labelText);
    control.dataset.testid = 'multi-select-control';
    if (labelEl && !labelEl.id) labelEl.id = `${uid}-label`;
    if (labelEl) labelEl.addEventListener('click', (event) => { event.preventDefault(); control.focus(); });

    const chips = document.createElement('div');
    chips.className = 'ms-chips';
    control.appendChild(chips);

    const panel = document.createElement('div');
    panel.className = 'ms-panel';
    panel.id = `${uid}-panel`;
    panel.hidden = true;

    let search = null;
    if (options().length >= SEARCH_THRESHOLD) {
        search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control form-control-sm ms-search';
        search.placeholder = `Buscar ${labelText.toLowerCase()}…`;
        search.setAttribute('aria-label', `Buscar ${labelText.toLowerCase()}`);
        panel.appendChild(search);
    }

    const toolbar = document.createElement('div');
    toolbar.className = 'ms-toolbar';
    toolbar.innerHTML = `
        <span class="ms-count" aria-live="polite"></span>
        <span>
            <button type="button" class="btn btn-link btn-sm p-0 me-2" data-ms-all>Todos</button>
            <button type="button" class="btn btn-link btn-sm p-0" data-ms-none>Ninguno</button>
        </span>`;
    panel.appendChild(toolbar);

    const list = document.createElement('div');
    list.className = 'ms-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-multiselectable', 'true');
    list.setAttribute('aria-label', labelText);
    panel.appendChild(list);

    const empty = document.createElement('div');
    empty.className = 'ms-empty';
    empty.textContent = 'Sin resultados';
    empty.hidden = true;
    panel.appendChild(empty);

    options().forEach((option, index) => {
        const item = document.createElement('label');
        item.className = 'ms-option';
        item.setAttribute('role', 'option');
        item.dataset.index = String(index);
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input me-2';
        checkbox.disabled = option.disabled;
        checkbox.tabIndex = -1;
        item.appendChild(checkbox);
        item.appendChild(document.createTextNode(option.textContent.trim()));
        // mousedown keeps focus inside the widget so the keyboard keeps working.
        item.addEventListener('mousedown', (event) => event.preventDefault());
        item.addEventListener('click', (event) => {
            event.preventDefault();
            toggle(index);
            setActive(item);
        });
        list.appendChild(item);
    });

    function emit() {
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function toggle(index) {
        const option = options()[index];
        if (!option || option.disabled) return;
        option.selected = !option.selected;
        render();
        emit();
    }

    function setAll(selected) {
        visibleItems().forEach((item) => {
            const option = options()[Number(item.dataset.index)];
            if (option && !option.disabled) option.selected = selected;
        });
        render();
        emit();
    }

    function visibleItems() {
        return Array.from(list.children).filter((item) => !item.hidden);
    }

    function render() {
        const all = options();
        const selected = all.filter((option) => option.selected);

        chips.replaceChildren();
        if (selected.length === 0) {
            const placeholder = document.createElement('span');
            placeholder.className = 'ms-placeholder';
            placeholder.textContent = 'Seleccionar…';
            chips.appendChild(placeholder);
        }
        selected.forEach((option) => {
            const chip = document.createElement('span');
            chip.className = 'ms-chip';
            chip.textContent = option.textContent.trim();
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ms-chip-remove';
            remove.setAttribute('aria-label', `Quitar ${option.textContent.trim()}`);
            remove.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
            remove.disabled = select.disabled;
            remove.addEventListener('click', (event) => {
                event.stopPropagation();
                toggle(all.indexOf(option));
            });
            chip.appendChild(remove);
            chips.appendChild(chip);
        });

        Array.from(list.children).forEach((item) => {
            const option = all[Number(item.dataset.index)];
            item.querySelector('input').checked = option.selected;
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            item.classList.toggle('is-selected', option.selected);
        });

        toolbar.querySelector('.ms-count').textContent = `${selected.length} de ${all.length} seleccionados`;
        control.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
    }

    function filter() {
        const term = (search?.value || '').trim().toLocaleLowerCase('es');
        let shown = 0;
        Array.from(list.children).forEach((item) => {
            const match = item.textContent.toLocaleLowerCase('es').includes(term);
            item.hidden = !match;
            if (match) shown++;
        });
        empty.hidden = shown > 0;
        setActive(visibleItems()[0] || null);
        place();
    }

    let active = null;
    function setActive(item) {
        active?.classList.remove('is-active');
        active = item;
        if (active) {
            active.classList.add('is-active');
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    // The panel floats over the page (position: fixed) so no card, table or
    // modal with overflow can clip it. It sits below the control, or above it
    // when there is more room there, and the list shrinks to the space left.
    const GAP = 4;
    const MARGIN = 8;
    const LIST_MAX = 240;
    const LIST_MIN = 96;

    function place() {
        if (panel.hidden) return;
        const rect = control.getBoundingClientRect();
        const below = window.innerHeight - rect.bottom - GAP - MARGIN;
        const above = rect.top - GAP - MARGIN;

        list.style.maxHeight = `${LIST_MAX}px`;
        const chrome = panel.offsetHeight - list.offsetHeight;
        const wanted = chrome + Math.min(LIST_MAX, list.scrollHeight);
        const upward = below < wanted && above > below;
        const room = upward ? above : below;

        list.style.maxHeight = `${Math.max(LIST_MIN, Math.min(LIST_MAX, room - chrome))}px`;
        panel.style.left = `${Math.max(MARGIN, rect.left)}px`;
        panel.style.width = `${rect.width}px`;
        if (upward) {
            panel.style.top = '';
            panel.style.bottom = `${window.innerHeight - rect.top + GAP}px`;
        } else {
            panel.style.bottom = '';
            panel.style.top = `${rect.bottom + GAP}px`;
        }
    }

    function open() {
        if (select.disabled || !panel.hidden) return;
        panel.hidden = false;
        control.setAttribute('aria-expanded', 'true');
        root.classList.add('is-open');
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        if (search) {
            search.value = '';
            filter();
            search.focus();
        } else {
            control.focus();
            setActive(visibleItems()[0] || null);
        }
    }

    function close(refocus = false) {
        if (panel.hidden) return;
        panel.hidden = true;
        window.removeEventListener('scroll', place, true);
        window.removeEventListener('resize', place);
        control.setAttribute('aria-expanded', 'false');
        root.classList.remove('is-open');
        setActive(null);
        if (refocus) control.focus();
    }

    function onKeys(event) {
        const items = visibleItems();
        const position = items.indexOf(active);
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (panel.hidden) { open(); return; }
                setActive(items[Math.min(position + 1, items.length - 1)] || null);
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActive(items[Math.max(position - 1, 0)] || null);
                break;
            case 'Enter':
            case ' ':
                if (event.target === search && event.key === ' ') return;
                event.preventDefault();
                if (panel.hidden) { open(); return; }
                if (active) toggle(Number(active.dataset.index));
                break;
            case 'Escape':
                if (!panel.hidden) { event.preventDefault(); close(true); }
                break;
            case 'Tab':
                close();
                break;
            default:
        }
    }

    control.addEventListener('click', () => (panel.hidden ? open() : close(true)));
    root.addEventListener('keydown', onKeys);
    // The wheel over the open panel only ever scrolls the list: at its ends
    // (or over the search box and toolbar) the page must not move underneath.
    panel.addEventListener('wheel', (event) => {
        const inList = list.contains(event.target);
        const atTop = list.scrollTop <= 0 && event.deltaY < 0;
        const atBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 1 && event.deltaY > 0;
        if (!inList || atTop || atBottom) event.preventDefault();
    }, { passive: false });
    toolbar.addEventListener('mousedown', (event) => event.preventDefault());
    search?.addEventListener('input', filter);
    toolbar.querySelector('[data-ms-all]').addEventListener('click', () => setAll(true));
    toolbar.querySelector('[data-ms-none]').addEventListener('click', () => setAll(false));
    document.addEventListener('click', (event) => { if (!root.contains(event.target)) close(); });

    // A hidden `required` select cannot take focus, so surface the browser's
    // validation on the visible control instead.
    select.addEventListener('invalid', () => {
        control.classList.add('is-invalid');
        control.focus();
    });
    select.addEventListener('change', render);

    select.classList.add('ms-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.parentNode.insertBefore(root, select);
    root.appendChild(control);
    root.appendChild(panel);
    root.appendChild(select);

    render();
}

function enhanceAll(scope = document) {
    scope.querySelectorAll('select[multiple]').forEach(enhance);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => enhanceAll());
} else {
    enhanceAll();
}

window.enhanceMultiSelects = enhanceAll;
