import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Halaman pemilih memakai font sistem (tanpa CDN font) agar ringan di ratusan HP.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/voter.css', 'resources/js/voter.js', 'resources/js/screen.js', 'resources/js/results-screen.js', 'resources/css/portal.css', 'resources/js/portal.js', 'resources/css/report.css', 'resources/js/report.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
