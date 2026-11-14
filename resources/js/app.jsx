import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import LicenseNotice from './Components/LicenseNotice';

const appName = import.meta.env.VITE_APP_NAME || 'Atheris ERM';

// The vendor portal shows the client's name, never the platform's, so its
// titles carry no suffix. `title` receives only a string; resolve() sees the
// component name first, and a vendor session never navigates into staff pages.
let inPortal = false;

createInertiaApp({
    title: (title) =>
        inPortal ? (title || '') : title ? `${title} - ${appName}` : appName,
    resolve: (name) => {
        inPortal = name.startsWith('TprmPortal/');

        return resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        );
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        // The licence surface renders beside every page. Inertia hands the page
        // context to the render-prop child, so LicenseNotice can read the
        // shared `license` prop without each page having to mount it.
        root.render(
            <App {...props}>
                {({ Component, props: pageProps, key }) => (
                    <>
                        <Component key={key} {...pageProps} />
                        <LicenseNotice />
                    </>
                )}
            </App>,
        );
    },
    progress: {
        color: '#D4AF37',
    },
});
