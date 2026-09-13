import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import LicenseNotice from './Components/LicenseNotice';

const appName = import.meta.env.VITE_APP_NAME || 'Atheris ERM';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
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
