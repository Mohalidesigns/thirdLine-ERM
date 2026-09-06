import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // One JavaScript entry since Phase 6.8 of the migration programme:
            // app.jsx boots Inertia + React. The second entry, app.js, booted
            // Livewire and Alpine for the Blade screens, and went with them.
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    // Drop console.log/debug/info from production bundles (keep warn/error for
    // real diagnostics). Marking them pure lets minification remove the calls
    // and their arguments entirely. Copied from ThirdLine (VAPT-060).
    esbuild: {
        pure: ['console.log', 'console.debug', 'console.info'],
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
