/*
 * JavaScript entry point. Phase 0b (Diseño) owns resources/js and
 * resources/css; keep the <x-ui.modal> contract from docs/arquitectura.md.
 */
import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

Alpine.plugin(focus);
window.Alpine = Alpine;

/*
 * fetch() for same-origin calls from module views (Alpine components): asks
 * for JSON, sends the CSRF token of <meta name="csrf-token"> and the session
 * cookie, and JSON-encodes a plain-object (or array) body. FormData, strings
 * and other bodies go as they are. Caller headers win. Returns the Response.
 */
window.apiFetch = (url, options = {}) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const headers = new Headers(options.headers);
    let { body } = options;

    const setDefault = (name, value) => {
        if (value && !headers.has(name)) {
            headers.set(name, value);
        }
    };

    setDefault('Accept', 'application/json');
    setDefault('X-Requested-With', 'XMLHttpRequest');
    setDefault('X-CSRF-TOKEN', token);

    const isJson = Array.isArray(body) || (body !== null
        && typeof body === 'object'
        && [Object.prototype, null].includes(Object.getPrototypeOf(body)));

    if (isJson) {
        body = JSON.stringify(body);
        setDefault('Content-Type', 'application/json');
    }

    return fetch(url, { credentials: 'same-origin', ...options, headers, body });
};

Alpine.start();

// <x-ui.modal> contract: an element with data-modal-open="<id>" opens the
// dialog with that id; an element with data-modal-close closes its dialog.
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal-open]');
    if (opener) {
        document.getElementById(opener.dataset.modalOpen)?.showModal();
        return;
    }

    const closer = event.target.closest('[data-modal-close]');
    if (closer) {
        closer.closest('dialog')?.close();
        return;
    }

    // A click on the backdrop lands on the <dialog> itself (its content fills
    // the box), so it closes the modal, like Escape does.
    if (event.target instanceof HTMLDialogElement && event.target.open) {
        event.target.close();
    }
});

// <x-ui.modal :open="true"> renders data-modal-autoopen (e.g. after a validation error).
document.querySelectorAll('dialog[data-modal-autoopen]').forEach((dialog) => dialog.showModal());

// PWA (A.6): the service worker caches static files and the offline page
// only, never portfolio data. Browsers allow it on HTTPS and localhost.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Without a service worker the app works the same, only without the offline page.
        });
    });
}
