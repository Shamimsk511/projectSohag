/**
 * Account pages: show the contract amount field only for loans, weekday choices only for daily
 * collection plans, and confirm before closing.
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

function initPlanToggle(form) {
    const frequency = form.querySelector('select[name="frequency"]');
    const weekdays = form.querySelector('[data-daily-only]');

    const sync = () => {
        const isDaily = frequency.value === 'daily';
        weekdays.hidden = !isDaily;
        weekdays.disabled = !isDaily;
    };

    frequency.addEventListener('change', sync);
    sync();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form.js-account-form').forEach(initTypeToggle);
    document.querySelectorAll('form.js-plan-form').forEach(initPlanToggle);
    initConfirmForms();
});
