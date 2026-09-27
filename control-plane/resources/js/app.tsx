import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Kiln';

// Module extension points (navigation + ⌘K commands). See resources/js/lib/registry.ts.
import.meta.glob(['./register.ts', '../../modules/*/resources/js/register.ts'], { eager: true });

const pages = import.meta.glob('./pages/**/*.tsx');
const modulePages = import.meta.glob('../../modules/*/resources/js/pages/**/*.tsx');

// "Sites/Index" resolves to modules/Sites/resources/js/pages/Index.tsx, else resources/js/pages/Sites/Index.tsx.
function resolvePage(name: string) {
    const [module, ...rest] = name.split('/');
    const modulePath = `../../modules/${module}/resources/js/pages/${rest.join('/')}.tsx`;

    if (rest.length > 0 && modulePath in modulePages) {
        return resolvePageComponent(modulePath, modulePages);
    }

    return resolvePageComponent(`./pages/${name}.tsx`, pages);
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: resolvePage,
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: 'var(--accent)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
