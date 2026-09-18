'use strict';
// Progressive enhancement; every essential action also works without JavaScript.
const live_message = document.getElementById('live-message');
for (const button of document.querySelectorAll('[data-copy]')) {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copy);
        try {
            await navigator.clipboard.writeText(target.textContent);
            const label = button.textContent;
            button.textContent = 'Kopiert';
            live_message.textContent = 'In die Zwischenablage kopiert.';
            setTimeout(() => { button.textContent = label; }, 1800);
        } catch {
            const range = document.createRange();
            range.selectNodeContents(target);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            live_message.textContent = 'Text markiert. Bitte über die Kopierfunktion deines Browsers kopieren.';
        }
    });
}
const search = document.getElementById('article-search');
if (search) search.addEventListener('input', () => {
    let visible = 0;
    for (const row of document.querySelectorAll('[data-article-row]')) {
        row.hidden = !row.textContent.toLocaleLowerCase('de').includes(search.value.toLocaleLowerCase('de'));
        if (!row.hidden) visible++;
    }
    const empty = document.getElementById('no-results');
    if (empty) empty.hidden = visible !== 0;
    live_message.textContent = `${visible} passende Artikel.`;
});
for (const textarea of document.querySelectorAll('[data-selector-list]')) {
    const container = document.createElement('div');
    const list = document.createElement('div');
    const suggestions = document.createElement('datalist');
    suggestions.id = `${textarea.id}-options`;
    for (const value of JSON.parse(textarea.dataset.presets)) {
        const option = document.createElement('option');
        option.value = value;
        suggestions.append(option);
    }
    const sync = () => {
        textarea.value = [...list.querySelectorAll('input')].map(input => input.value.trim()).filter(Boolean).join('\n');
    };
    const add_row = (value = '') => {
        const row = document.createElement('div');
        row.className = 'selector-row';
        const input = document.createElement('input');
        input.value = value;
        input.setAttribute('list', suggestions.id);
        input.setAttribute('aria-label', `CSS-Selektor für ${textarea.previousElementSibling.textContent}`);
        input.placeholder = 'Selektor wählen oder eingeben';
        input.maxLength = 300;
        input.addEventListener('input', sync);
        row.append(input);
        for (const [symbol, label, move] of [['↑', 'Nach oben', -1], ['↓', 'Nach unten', 1], ['×', 'Entfernen', 0]]) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'secondary';
            button.textContent = symbol;
            button.setAttribute('aria-label', label);
            button.addEventListener('click', () => {
                if (move === -1 && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
                if (move === 1 && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
                if (move === 0) { row.remove(); add_button.focus(); }
                sync();
                live_message.textContent = `Selektor: ${label}.`;
            });
            row.append(button);
        }
        list.append(row);
        sync();
        return input;
    };
    const add_button = document.createElement('button');
    add_button.type = 'button';
    add_button.className = 'secondary add-selector';
    add_button.textContent = '+ Selektor hinzufügen';
    add_button.addEventListener('click', () => add_row().focus());
    const original = textarea.value.split('\n').map(line => line.trim()).filter(Boolean);
    for (const value of original) add_row(value);
    container.append(list, suggestions, add_button);
    textarea.after(container);
    textarea.hidden = true;
    textarea.form.addEventListener('submit', sync);
}
for (const form of document.forms) {
    form.addEventListener('submit', () => {
        if (!form.checkValidity()) return;
        form.setAttribute('aria-busy', 'true');
        setTimeout(() => {
            for (const button of form.querySelectorAll('button:not([type="button"])')) button.disabled = true;
        }, 0);
    });
}
window.addEventListener('pageshow', () => {
    for (const button of document.querySelectorAll('button:disabled')) button.disabled = false;
    for (const form of document.forms) form.removeAttribute('aria-busy');
});
