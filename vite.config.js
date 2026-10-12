import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/admin.js', 'resources/js/admin/parties.js', 'resources/js/admin/accounts.js', 'resources/js/admin/office-entries.js', 'resources/js/admin/holidays.js', 'resources/js/field.js'],
            refresh: true,
        }),
    ],
});
