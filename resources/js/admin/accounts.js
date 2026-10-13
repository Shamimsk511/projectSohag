/**
 * Account pages: contract amount label per account type (loan, saving agreed amount, FDR agreed payback), loan collection section with live preview, action sections, entry form labels per
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
        box.querySelectorAll('input, select').forEach((input) => {
            input.disabled = !on;
        });
    };

    const existing = form.querySelector('input[name="existing_loan"]');
    const carriedFields = form.querySelector('[data-carried-fields]');
    const newLoanOnly = form.querySelector('[data-new-loan-only]');

    /** An existing loan carries book entries: no payment method, plus what was received before. */
    const syncExisting = () => {
        const isExisting = Boolean(existing?.checked);
        if (carriedFields) {
            toggle(carriedFields, isExisting);
            carriedFields.querySelector('input[name="carried_as_of"]').required = isExisting;
        }
        newLoanOnly.hidden = isExisting;
        newLoanOnly.disabled = isExisting;
    };

    const sync = () => {
        const type = form.querySelector('input[name="account_type"]:checked')?.value;
        const isLoan = type === 'loan';
        toggle(loanFields, isLoan);
        toggle(savingFields, !isLoan);
        const agreedLabel = savingFields.querySelector('label[for="contract_amount"]');
        if (agreedLabel) {
            agreedLabel.textContent = savingFields.dataset[type === 'fdr' ? 'labelFdr' : 'labelSaving'];
        }
        const agreedHint = savingFields.querySelector('.form-text');
        if (agreedHint) {
            agreedHint.textContent = savingFields.dataset[type === 'fdr' ? 'hintFdr' : 'hintSaving'];
        }
        form.querySelectorAll('[data-loan-only]').forEach((box) => toggle(box, isLoan));
        form.querySelectorAll('[data-saving-only]').forEach((box) => toggle(box, !isLoan));
        principal.required = isLoan;
        if (isLoan) {
            syncExisting();
        }
    };

    /** Display only; the server adds principal and extra again. */
    const showTotal = () => {
        const amount = (input) => Number(toAsciiDigits(input.value).replace(/,/g, '')) || 0;
        total.textContent = `৳ ${new Intl.NumberFormat('bn-BD').format(amount(principal) + amount(extra))}`;
    };

    form.querySelectorAll('input[name="account_type"]').forEach((radio) => radio.addEventListener('change', sync));
    existing?.addEventListener('change', syncExisting);
    [principal, extra].forEach((input) => input.addEventListener('input', showTotal));
    sync();
    showTotal();
}

/**
 * Collection section of the open form: weekdays only for daily plans. Loans: installment and number
 * of installments fill each other and the preview comes from the server (same math and dates as
 * opening). Saving/FDR: term fills the end date and an estimated total is shown. Display only.
 */
function initLoanCollection(form) {
    const box = form.querySelector('[data-collection-fields]');
    const preview = form.querySelector('[data-loan-preview]');
    if (!box || !preview) {
        return;
    }

    const field = (name) => form.querySelector(`[name="${name}"]`);
    const frequency = field('frequency');
    const weekdays = box.querySelector('[data-daily-only]');
    const basis = field('installment_basis');
    let timer = null;
    let request = null;

    const syncFrequency = () => {
        const isDaily = frequency.value === 'daily';
        weekdays.hidden = !isDaily;
        weekdays.disabled = !isDaily;
    };

    const refresh = async () => {
        request?.abort();
        const typed = (name) => field(name).value.trim() !== '';
        if (form.querySelector('input[name="account_type"]:checked')?.value !== 'loan' || !typed('principal_amount') || !(typed('installment_amount') || typed('installment_count'))) {
            preview.hidden = true;
            return;
        }

        request = new AbortController();
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            if (key !== '_token') {
                params.append(key, value);
            }
        });

        try {
            const response = await fetch(`${preview.dataset.url}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            const data = response.ok ? await response.json() : { ready: false };
            preview.hidden = !data.ready;
            if (!data.ready) {
                return;
            }
            preview.querySelectorAll('[data-preview]').forEach((node) => {
                node.textContent = data[node.dataset.preview] ?? '';
            });
            preview.querySelector('[data-preview-carried]').hidden = data.to_collect === data.contract;
            if (basis.value === 'count') {
                field('installment_amount').value = data.installment_taka;
            } else {
                field('installment_count').value = data.count;
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                preview.hidden = true;
            }
        }
    };

    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(refresh, 300);
    };

    /** Typing in one sets the basis; clearing it clears the other too (no plan). */
    [['installment_amount', 'installment_count', 'amount'], ['installment_count', 'installment_amount', 'count']].forEach(([name, other, kind]) => {
        field(name).addEventListener('input', () => {
            basis.value = kind;
            if (field(name).value.trim() === '') {
                field(other).value = '';
            }
        });
    });
    frequency.addEventListener('change', syncFrequency);
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    syncFrequency();
    refresh();
    initPlanEstimate(form);
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
    const scheduleField = form.querySelector('[data-schedule-only]');

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
        if (scheduleField) {
            scheduleField.hidden = !strings.schedule;
            scheduleField.querySelector('select').disabled = !strings.schedule;
        }
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
    document.querySelectorAll('form.js-account-form').forEach((form) => {
        initTypeToggle(form);
        initLoanCollection(form);
    });
    document.querySelectorAll('form.js-plan-form').forEach(initPlanToggle);
    document.querySelectorAll('form.js-entry-form').forEach(initEntryForm);
    document.querySelectorAll('form.js-once').forEach(initSubmitOnce);
    initSectionButtons();
    initConfirmForms();
});
