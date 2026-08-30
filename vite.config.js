import { defineConfig } from 'vite';
import tailwindcss from "@tailwindcss/vite";
import laravel from 'laravel-vite-plugin';
// Wave themes removed; anchor is the only theme.
const activeTheme = 'anchor';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                `resources/themes/${activeTheme}/assets/css/app.css`,
                `resources/themes/${activeTheme}/assets/js/app.js`,
                `resources/themes/${activeTheme}/assets/js/sosview.js`,
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
    ],
});
