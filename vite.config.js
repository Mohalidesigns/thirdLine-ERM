import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Two JavaScript entries until Phase 6 of the migration programme:
            // app.js boots Livewire + Alpine for the Blade screens that have not
            // been ported yet, app.jsx boots Inertia + React for the ones that
            // have. Both share one stylesheet.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.jsx'],
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
