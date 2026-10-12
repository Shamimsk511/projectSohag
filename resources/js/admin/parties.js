/**
 * Party pages: server-side DataTable list, Select2 pickers, FilePond photo upload and
 * SweetAlert2 confirmation for forms marked `.js-confirm`.
 * Loaded only on party pages (pushed from the views), so other admin pages stay light.
 */
import $ from 'jquery';
import DataTable from 'datatables.net-bs5';
import select2 from 'select2';
import * as FilePond from 'filepond';
import Swal from 'sweetalert2';

import 'datatables.net-bs5/css/dataTables.bootstrap5.css';
import 'select2/dist/css/select2.css';
import 'filepond/dist/filepond.css';
import 'sweetalert2/dist/sweetalert2.css';

select2(window, $);

// Data attributes avoid DataTables option names: DataTables reads data-* on the table as init options.
function initPartyTable(table) {
    const lang = JSON.parse(table.dataset.strings);
    const labels = JSON.parse(table.dataset.actionLabels);
    const areaFilter = document.getElementById('filter-area');
    const statusFilter = document.getElementById('filter-status');

    const link = (href, icon, text, style) => {
        const a = document.createElement('a');
        a.href = href;
        a.className = `btn btn-sm ${style}`;
        a.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i> `;
        a.append(text);
        return a.outerHTML;
    };

    const dataTable = new DataTable(table, {
        serverSide: true,
        processing: true,
        searchDelay: 400,
        pageLength: 25,
        order: [[0, 'desc']],
        ajax: {
            url: table.dataset.source,
            data: (params) => {
                params.area_id = areaFilter.value;
                params.status = statusFilter.value;
            },
        },
        columns: [
            { data: 'party_code' },
            { data: 'name', render: DataTable.render.text() },
            { data: 'phone', render: DataTable.render.text() },
            { data: 'area', orderable: false, render: DataTable.render.text() },
            {
                data: 'status',
                render: (status, type, row) => {
                    const badge = document.createElement('span');
                    badge.className = `badge ${status === 'active' ? 'text-bg-success' : 'text-bg-secondary'}`;
                    badge.textContent = row.status_label;
                    return badge.outerHTML;
                },
            },
            {
                data: null,
                orderable: false,
                className: 'text-end text-nowrap',
                render: (data, type, row) =>
                    link(row.show_url, 'bi-eye', labels.view, 'btn-outline-secondary')
                    + (row.edit_url ? ` ${link(row.edit_url, 'bi-pencil', labels.edit, 'btn-outline-primary')}` : ''),
            },
        ],
        language: {
            search: lang.search,
            lengthMenu: lang.length_menu,
            info: lang.info,
            infoEmpty: lang.info_empty,
            infoFiltered: lang.info_filtered,
            zeroRecords: lang.zero_records,
            emptyTable: lang.zero_records,
            processing: lang.processing,
            loadingRecords: lang.processing,
            paginate: { first: lang.first, last: lang.last, next: lang.next, previous: lang.previous },
        },
    });

    [areaFilter, statusFilter].forEach((filter) => filter.addEventListener('change', () => dataTable.draw()));
}

function initPhotoUpload(input) {
    const labels = JSON.parse(input.dataset.labels);
    // JSON accept makes validation failures a 422 (shown by FilePond) instead of a redirect to HTML.
    const headers = { 'X-CSRF-TOKEN': input.dataset.csrf, Accept: 'application/json' };

    FilePond.create(input, {
        credits: false,
        allowMultiple: false,
        server: {
            process: { url: input.dataset.processUrl, headers },
            revert: { url: input.dataset.revertUrl, method: 'DELETE', headers },
            restore: null,
            load: null,
            fetch: null,
        },
        labelIdle: labels.idle,
        labelFileProcessing: labels.processing,
        labelFileProcessingComplete: labels.complete,
        labelFileProcessingError: labels.failed,
        labelTapToCancel: labels.tap_to_cancel,
        labelTapToRetry: labels.tap_to_retry,
        labelTapToUndo: labels.tap_to_undo,
    });
}

function initConfirm(form) {
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
}

document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('parties-table');
    if (table) {
        initPartyTable(table);
    }

    $('.js-select2').each(function () {
        $(this).select2({ width: '100%', placeholder: this.dataset.placeholder });
    });

    document.querySelectorAll('input.js-filepond').forEach(initPhotoUpload);
    document.querySelectorAll('form.js-confirm').forEach(initConfirm);
});
