/**
 * SweetAlert2 confirmation for forms marked `.js-confirm`. Strings come from the form's
 * `data-confirm` JSON: { title, text, confirm, cancel }.
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
                focusCancel: true,
            });

            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
}
