/**
 * Report tables marked `.js-report-table`: Excel export and print through DataTables Buttons.
 * Rows stay in server order and nothing is paged or filtered; the table only gains the buttons.
 * PDF is the browser's print dialog ("Save as PDF"), which keeps Bengali text intact (pdfmake would need an embedded Bengali font).
 * Tables showing only an empty-state row (a colspan cell) are left alone: DataTables needs a rectangular table.
 */
import JSZip from 'jszip';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-buttons-bs5';
import 'datatables.net-buttons/js/buttons.html5.mjs';
import 'datatables.net-buttons/js/buttons.print.mjs';

import 'datatables.net-bs5/css/dataTables.bootstrap5.css';
import 'datatables.net-buttons-bs5/css/buttons.bootstrap5.css';

DataTable.Buttons.jszip(JSZip);

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.js-report-table').forEach((table) => {
        if (table.querySelector('tbody td[colspan]')) {
            return;
        }

        const name = table.dataset.exportName || 'report';

        new DataTable(table, {
            paging: false,
            searching: false,
            ordering: false,
            info: false,
            layout: {
                topStart: {
                    buttons: [
                        { extend: 'excelHtml5', text: table.dataset.excelLabel, filename: name, title: null },
                        { extend: 'print', text: table.dataset.printLabel, title: document.title },
                    ],
                },
                topEnd: null,
                bottomStart: null,
                bottomEnd: null,
            },
        });
    });
});
