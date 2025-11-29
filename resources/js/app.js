import './bootstrap';
import Alpine from 'alpinejs';
import TomSelect from 'tom-select'
import 'tom-select/dist/css/tom-select.css'

window.Alpine = Alpine;

Alpine.start();

document.addEventListener('DOMContentLoaded', () => {
    const languagesEl = document.querySelector('#languages');
    if (!languagesEl) return;

    try {
        new TomSelect(languagesEl, {
            plugins: ['remove_button'],
            maxItems: null,
            create: false
        });
    } catch (err) {
        console.error('TomSelect init failed:', err);
    }
});