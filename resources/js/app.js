/*
 * JavaScript entry point. Phase 0b (Diseño) owns resources/js and
 * resources/css; keep the <x-ui.modal> contract from docs/arquitectura.md.
 */
import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

Alpine.plugin(focus);
window.Alpine = Alpine;
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
    }
});

// <x-ui.modal :open="true"> renders data-modal-autoopen (e.g. after a validation error).
document.querySelectorAll('dialog[data-modal-autoopen]').forEach((dialog) => dialog.showModal());
