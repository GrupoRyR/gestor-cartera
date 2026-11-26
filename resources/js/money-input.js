/*
 * <x-ui.money-input> behaviour: pesos shown the Colombian way while the user
 * types (dot for thousands, comma for decimals: 1.234.567,50) and sent back
 * clean (1234567.50), so the server rules never see a formatted figure.
 *
 * - Every input[data-money-input] is formatted when the page loads and on
 *   each keystroke or paste, keeping the caret after the same digit.
 * - data-decimals (0 to 2) caps the decimals; with 0 a comma and what
 *   follows it are dropped. A "." typed where a decimal fits becomes ",".
 * - A pasted figure is read in its own notation first (normalizePasted), so
 *   "1234567.50" from Excel or SAP stays 1.234.567,50, never 123.456.750.
 * - On submit, the formdata event replaces each field's entry with the
 *   clean number, in POST and GET forms alike; the field itself keeps
 *   showing the formatted figure.
 *
 * The value printed by the server is clean (digits and an optional dot with
 * one or two decimals); anything else, like old input typed without the
 * script ("1.500") or a value the browser restored (back button, autofill),
 * is read as typed text instead.
 */

const SELECTOR = 'input[data-money-input]';

/** Digits and the decimal part of what the user sees or pasted. */
function partsOfTyped(text, decimals) {
    const cleaned = String(text).replace(/[^\d,]/g, '');
    const comma = cleaned.indexOf(',');
    const integer = (comma === -1 ? cleaned : cleaned.slice(0, comma)).replace(/^0+(?=\d)/, '');
    const hasComma = comma !== -1 && decimals > 0;
    const fraction = hasComma ? cleaned.slice(comma + 1).replace(/,/g, '').slice(0, decimals) : '';

    return { integer, fraction, hasComma };
}

/**
 * Digits and the decimal part of a clean value (1234567.5). A value is clean
 * only when its dot has at most two digits after it, as the server prints
 * them; "1.500" is old input typed without the script (thousands), so it is
 * read as typed text. Two digits, not $decimals: a clean "1500000.50" in a
 * whole-peso field keeps its pesos and drops the cents.
 */
function partsOfClean(text, decimals) {
    const match = /^(\d*)(?:\.(\d{0,2}))?$/.exec(String(text).trim());

    if (match === null) {
        return partsOfTyped(text, decimals);
    }

    const integer = match[1].replace(/^0+(?=\d)/, '');
    const fraction = decimals > 0 ? (match[2] ?? '').slice(0, decimals) : '';

    return { integer, fraction, hasComma: fraction !== '' };
}

function show({ integer, fraction, hasComma }) {
    if (integer === '' && !hasComma) {
        return '';
    }

    const grouped = (integer === '' ? '0' : integer).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return hasComma ? `${grouped},${fraction}` : grouped;
}

/** "1.234.567,5" (as typed) → "1234567.5"; "" when there is no number. */
export function cleanMoney(typed, decimals) {
    const { integer, fraction } = partsOfTyped(typed, decimals);

    if (integer === '' && fraction === '') {
        return '';
    }

    return fraction === '' ? (integer || '0') : `${integer || '0'}.${fraction}`;
}

/** What the field shows for text the user typed: "1234567,5" → "1.234.567,5". */
export function formatTyped(typed, decimals) {
    return show(partsOfTyped(typed, decimals));
}

/** What the field shows for a clean value from the server: "1234567.5" → "1.234.567,5". */
export function formatClean(clean, decimals) {
    return show(partsOfClean(clean, decimals));
}

/**
 * A pasted figure in the field's own notation (comma for decimals, dots for
 * thousands), whatever notation it came in. Excel, SAP or a bank statement
 * may use a dot for decimals: "1234567.50" must not become 123.456.750.
 * - With both separators, the last one is the decimal one.
 * - Only dots: the last dot is decimal when 1 or 2 digits follow it
 *   ("1234567.50", "1,234.5"); otherwise they are thousands ("1.500.000").
 * - Only commas: several are thousands ("1,234,567"); a single one is the
 *   decimal comma.
 */
export function normalizePasted(text) {
    const figure = String(text).replace(/[^\d.,]/g, '');
    const lastDot = figure.lastIndexOf('.');
    const lastComma = figure.lastIndexOf(',');

    if (lastDot !== -1 && lastComma !== -1) {
        const decimal = lastDot > lastComma ? '.' : ',';
        const [integer, fraction] = [figure.slice(0, Math.max(lastDot, lastComma)), figure.slice(Math.max(lastDot, lastComma) + 1)];

        return `${integer.replace(/[.,]/g, '')},${fraction.replace(decimal === '.' ? /,/g : /\./g, '')}`;
    }

    if (lastDot !== -1) {
        return /\.\d{1,2}$/.test(figure)
            ? `${figure.slice(0, lastDot).replace(/\./g, '')},${figure.slice(lastDot + 1)}`
            : figure.replace(/\./g, '');
    }

    if (figure.indexOf(',') !== lastComma) {
        return figure.replace(/,/g, '');
    }

    return figure;
}

/** Digits and commas before position $caret: what the caret must stay after. */
function significantBefore(text, caret) {
    return text.slice(0, caret).replace(/[^\d,]/g, '').length;
}

/** The position in $formatted right after its $count-th digit or comma. */
export function caretAfter(formatted, count) {
    if (count === 0) {
        return 0;
    }

    let seen = 0;

    for (let i = 0; i < formatted.length; i += 1) {
        if (/[\d,]/.test(formatted[i])) {
            seen += 1;

            if (seen === count) {
                return i + 1;
            }
        }
    }

    return formatted.length;
}

function decimalsOf(input) {
    const decimals = Number.parseInt(input.dataset.decimals ?? '0', 10);

    return Number.isNaN(decimals) ? 0 : Math.min(Math.max(decimals, 0), 2);
}

function reformat(input) {
    const decimals = decimalsOf(input);
    const caret = input.selectionStart ?? input.value.length;
    const before = significantBefore(input.value, caret);
    const formatted = formatTyped(input.value, decimals);

    if (formatted === input.value) {
        return;
    }

    input.value = formatted;

    if (document.activeElement === input) {
        const position = caretAfter(formatted, before);
        input.setSelectionRange(position, position);
    }
}

function onBeforeInput(event) {
    const input = event.target;

    // A dot typed where a decimal fits is the user's decimal separator.
    if (!(input instanceof HTMLInputElement) || !input.matches(SELECTOR) || event.inputType !== 'insertText' || event.data !== '.') {
        return;
    }

    event.preventDefault();

    if (decimalsOf(input) > 0 && !input.value.includes(',')) {
        input.setRangeText(',', input.selectionStart ?? input.value.length, input.selectionEnd ?? input.value.length, 'end');
        reformat(input);
    }
}

function onPaste(event) {
    const input = event.target;
    const text = event.clipboardData?.getData('text');

    if (!(input instanceof HTMLInputElement) || !input.matches(SELECTOR) || !text) {
        return;
    }

    event.preventDefault();
    input.setRangeText(normalizePasted(text), input.selectionStart ?? input.value.length, input.selectionEnd ?? input.value.length, 'end');
    reformat(input);
}

function onInput(event) {
    if (event.target instanceof HTMLInputElement && event.target.matches(SELECTOR)) {
        reformat(event.target);
    }
}

function onFormData(event) {
    for (const element of event.target.elements) {
        // :disabled also covers a field inside a <fieldset disabled>, which the form leaves out.
        if (element instanceof HTMLInputElement && element.matches(SELECTOR) && element.name !== '' && !element.matches(':disabled')) {
            event.formData.set(element.name, cleanMoney(element.value, decimalsOf(element)));
        }
    }
}

function formatOnLoad(input) {
    const decimals = decimalsOf(input);

    input.value = input.value === input.defaultValue ? formatClean(input.value, decimals) : formatTyped(input.value, decimals);
}

export function startMoneyInputs() {
    document.querySelectorAll(SELECTOR).forEach(formatOnLoad);
    document.addEventListener('beforeinput', onBeforeInput);
    document.addEventListener('paste', onPaste);
    document.addEventListener('input', onInput);
    // Capture: the listener runs for every form, whether or not formdata bubbles.
    document.addEventListener('formdata', onFormData, true);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll(SELECTOR).forEach((input) => reformat(input));
        }
    });
}
