/**
 * Office entries page: confirm before saving or reversing an entry; the head list follows the chosen
 * income/expense type.
 */
import { initConfirmForms } from './confirm.js';

function initHeadFilter(form) {
    const select = form.querySelector('select[name="office_head_id"]');
    if (!select) {
        return;
    }

    const sync = () => {
        const kind = form.querySelector('input[name="type"]:checked')?.value;
        select.querySelectorAll('option[data-kind]').forEach((option) => {
            const match = option.dataset.kind === kind;
            option.hidden = !match;
            option.disabled = !match;
            if (!match && option.selected) {
                select.value = '';
            }
        });
    };

    form.querySelectorAll('input[name="type"]').forEach((radio) => radio.addEventListener('change', sync));
    sync();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[action$="/office-entries"]').forEach(initHeadFilter);
    initConfirmForms();
});
