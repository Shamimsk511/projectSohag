import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/admin.js', 'resources/js/admin/parties.js', 'resources/js/admin/accounts.js', 'resources/js/admin/office-entries.js', 'resources/js/admin/holidays.js', 'resources/js/admin/daily-closings.js', 'resources/js/admin/schedules.js', 'resources/js/admin/monthly-report.js', 'resources/js/admin/dashboard.js', 'resources/js/admin/report-export.js', 'resources/js/field.js', 'resources/js/portal.js', 'resources/js/public.js'],
            refresh: true,
        }),
    ],
});
