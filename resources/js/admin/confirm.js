/**
 * SweetAlert2 confirmation for forms marked `.js-confirm`. Strings come from the form's
 * `data-confirm` JSON: { title, text, confirm, cancel, input? }. With `input` (its label), the dialog
 * asks for a required reason and copies it into the form's hidden `reason` field before submitting.
 */
import Swal from 'sweetalert2';

import 'sweetalert2/dist/sweetalert2.css';

export function initConfirmForms(root = document) {
    root.querySelectorAll('form.js-confirm').forEach((form) => {
        const strings = JSON.parse(form.dataset.confirm);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const result = await Swal.fire({
                icon: 'warning',
                title: strings.title,
                text: strings.text,
                showCancelButton: true,
                confirmButtonText: strings.confirm,
                cancelButtonText: strings.cancel,
                focusCancel: !strings.input,
                ...(strings.input && {
                    input: 'textarea',
                    inputLabel: strings.input,
                    inputAttributes: { maxlength: '500', 'aria-label': strings.input },
                    inputValidator: (value) => (value.trim() === '' ? strings.input : undefined),
                }),
            });

            if (result.isConfirmed) {
                if (strings.input) {
                    form.querySelector('input[name="reason"]').value = result.value.trim();
                }
                form.submit();
            }
        });
    });
}
