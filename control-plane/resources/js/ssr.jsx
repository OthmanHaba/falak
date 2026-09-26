/* prettier-ignore */
import {
createInertiaApp
} from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';

const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
const modulePages = import.meta.glob('../../modules/*/resources/js/pages/**/*.tsx', { eager: true });

import.meta.glob(['./register.ts', '../../modules/*/resources/js/register.ts'], { eager: true });

// "Sites/Index" resolves to modules/Sites/resources/js/pages/Index.tsx, else resources/js/pages/Sites/Index.tsx.
function resolvePage(name) {
    const [module, ...rest] = name.split('/');
    const modulePath = `../../modules/${module}/resources/js/pages/${rest.join('/')}.tsx`;

    if (rest.length > 0 && modulePath in modulePages) {
        return modulePages[modulePath];
    }

    return pages[`./pages/${name}.tsx`];
}

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: resolvePage,
        // prettier-ignore
        setup: ({ App, props }) => <App {...props} />,
    }),
);
