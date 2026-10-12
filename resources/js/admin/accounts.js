/**
 * Account pages: contract amount label per account type, action sections, entry form labels per
 * type, weekday choices only for daily collection plans, plan term -> end date + estimated total
 * (display only; the server stores what is posted), confirm before posting/closing, no double posts.
 */
import { initConfirmForms } from './confirm.js';

function initTypeToggle(form) {
    const loanFields = form.querySelector('[data-loan-fields]');
    const savingFields = form.querySelector('[data-saving-fields]');
    const total = form.querySelector('[data-contract-total]');
    const principal = form.querySelector('input[name="principal_amount"]');
    const extra = form.querySelector('input[name="extra_amount"]');

    const toggle = (box, on) => {
        box.hidden = !on;
        box.querySelectorAll('input').forEach((input) => {
            input.disabled = !on;
        });
    };

    const sync = () => {
        const isLoan = form.querySelector('input[name="account_type"]:checked')?.value === 'loan';
        toggle(loanFields, isLoan);
        toggle(savingFields, !isLoan);
        principal.required = isLoan;
    };

    /** Display only; the server adds principal and extra again. */
    const showTotal = () => {
        const amount = (input) => Number(toAsciiDigits(input.value).replace(/,/g, '')) || 0;
        total.textContent = `৳ ${new Intl.NumberFormat('bn-BD').format(amount(principal) + amount(extra))}`;
    };

    form.querySelectorAll('input[name="account_type"]').forEach((radio) => radio.addEventListener('change', sync));
    [principal, extra].forEach((input) => input.addEventListener('input', showTotal));
    sync();
    showTotal();
}

function initPlanToggle(form) {
    const frequency = form.querySelector('select[name="frequency"]');
    const weekdays = form.querySelector('[data-daily-only]');

    const sync = () => {
        const isDaily = frequency.value === 'daily';
        weekdays.hidden = !isDaily;
        weekdays.disabled = !isDaily;
        form.querySelectorAll('[data-frequency-hint]').forEach((hint) => {
            hint.hidden = hint.dataset.frequencyHint !== frequency.value;
        });
    };

    frequency.addEventListener('change', sync);
    sync();
    initPlanEstimate(form);
}

const toAsciiDigits = (value) => value.replace(/[০-৯]/g, (digit) => String(digit.charCodeAt(0) - 0x09e6));

const parseDate = (value) => (/^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00Z`) : null);

const formatDate = (date) => date.toISOString().slice(0, 10);

/** Same as Carbon addMonthsNoOverflow: 31 Jan + 1 month = 28/29 Feb. */
function addMonthsNoOverflow(date, months) {
    const target = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(date.getUTCDate(), lastDay));

    return target;
}

/** Mirrors CollectionPlan::fallsOn() / estimatedTotal(). */
function countCollectionDays(form, start, end) {
    const frequency = form.querySelector('select[name="frequency"]').value;
    const weekdays = [...form.querySelectorAll('input[name="weekdays[]"]:checked')].map((box) => Number(box.value));
    let days = 0;

    for (const date = new Date(start); date <= end; date.setUTCDate(date.getUTCDate() + 1)) {
        const lastDay = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
        const falls = {
            daily: weekdays.includes(date.getUTCDay()),
            weekly: date.getUTCDay() === start.getUTCDay(),
            monthly: date.getUTCDate() === Math.min(start.getUTCDate(), lastDay),
        }[frequency];
        days += falls ? 1 : 0;
    }

    return days;
}

function initPlanEstimate(form) {
    const field = (name) => form.querySelector(`[name="${name}"]`);
    const estimate = form.querySelector('[data-plan-estimate]');
    const estimateValue = form.querySelector('[data-plan-estimate-value]');

    const fillEndDate = () => {
        const start = parseDate(field('starts_on').value);
        const count = Number(field('duration').value);
        if (!start || !Number.isInteger(count) || count < 1) {
            return;
        }

        const months = field('duration_unit').value === 'years' ? count * 12 : count;
        const end = addMonthsNoOverflow(start, months);
        end.setUTCDate(end.getUTCDate() - 1);
        field('ends_on').value = formatDate(end);
    };

    const showEstimate = () => {
        const start = parseDate(field('starts_on').value);
        const end = parseDate(field('ends_on').value);
        const installment = Number(toAsciiDigits(field('installment_amount').value).replace(/,/g, ''));
        const valid = start && end && end >= start && installment > 0;
        estimate.hidden = !valid;
        if (valid) {
            const total = installment * countCollectionDays(form, start, end);
            estimateValue.textContent = `৳ ${total.toLocaleString(document.documentElement.lang || 'bn', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
    };

    ['duration', 'duration_unit', 'starts_on'].forEach((name) => field(name).addEventListener('input', fillEndDate));
    form.addEventListener('input', showEstimate);
    form.addEventListener('change', showEstimate);
    fillEndDate();
    showEstimate();
}

/** Entry form: the amount question, submit label, hint and payment method follow the chosen entry type. */
function initEntryForm(form) {
    const types = JSON.parse(form.dataset.types);
    const moneyFields = form.querySelector('[data-money-only]');
    const moneyFallback = form.querySelector('[data-money-fallback]');

    const sync = () => {
        const type = form.querySelector('input[name="type"]:checked, input[name="type"][type="hidden"]')?.value;
        const strings = types[type];
        if (!strings) {
            return;
        }

        form.querySelector('[data-amount-label]').textContent = strings.amount;
        form.querySelector('[data-submit-label]').textContent = strings.submit;
        form.querySelectorAll('[data-type-hint]').forEach((hint) => {
            hint.hidden = hint.dataset.typeHint !== type;
        });
        moneyFields.hidden = !strings.money;
        moneyFields.disabled = !strings.money;
        moneyFallback.disabled = strings.money;
    };

    form.querySelectorAll('input[name="type"]').forEach((radio) => radio.addEventListener('change', sync));
    sync();
}

/** The big action buttons open their section (closing the others) and bring it into view. */
function initSectionButtons() {
    document.querySelectorAll('[data-account-section]').forEach((button) => {
        button.addEventListener('click', () => {
            const section = document.getElementById(`section-${button.dataset.accountSection}`);
            const body = section.querySelector('.accordion-collapse');
            const focusFirstField = () => body.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')?.focus({ preventScroll: true });

            if (body.classList.contains('show')) {
                focusFirstField();
            } else {
                body.addEventListener('shown.bs.collapse', focusFirstField, { once: true });
                window.bootstrap.Collapse.getOrCreateInstance(body, { toggle: false }).show();
            }
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
}

/** A second tap on a slow network must not post twice. */
function initSubmitOnce(form) {
    form.addEventListener('submit', () => {
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form.js-account-form').forEach(initTypeToggle);
    document.querySelectorAll('form.js-plan-form').forEach(initPlanToggle);
    document.querySelectorAll('form.js-entry-form').forEach(initEntryForm);
    document.querySelectorAll('form.js-once').forEach(initSubmitOnce);
    initSectionButtons();
    initConfirmForms();
});
