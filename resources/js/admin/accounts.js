/**
 * Account pages: show the contract amount field only for loans, and confirm before closing.
 */
import { initConfirmForms } from './confirm.js';

function initTypeToggle(form) {
    const contractField = form.querySelector('[data-loan-only]');
    const contractInput = contractField.querySelector('input');

    const sync = () => {
        const isLoan = form.querySelector('input[name="account_type"]:checked')?.value === 'loan';
        contractField.hidden = !isLoan;
        contractInput.disabled = !isLoan;
        contractInput.required = isLoan;
    };

    form.querySelectorAll('input[name="account_type"]').forEach((radio) => radio.addEventListener('change', sync));
    sync();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form.js-account-form').forEach(initTypeToggle);
    initConfirmForms();
});
