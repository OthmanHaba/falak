import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const modulesDir = fileURLToPath(new URL('./modules/', import.meta.url));

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        // "@modules/Telemetry/components/x" → modules/Telemetry/resources/js/components/x (mirrors tsconfig paths).
        alias: [{ find: /^@modules\/([^/]+)\//, replacement: `${modulesDir}$1/resources/js/` }],
    },
    esbuild: {
        jsx: 'automatic',
    },
});
