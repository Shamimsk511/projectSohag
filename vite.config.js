import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/admin.js', 'resources/js/admin/parties.js', 'resources/js/field.js'],
            refresh: true,
        }),
    ],
});
