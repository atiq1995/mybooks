import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'My Books';

interface PageModule {
    default: ResolvedComponent;
}

// Typed explicitly so the resolver below is fully inferred; without it the
// glob's module type is lost and `default` comes back as `any`.
const pages: Record<string, () => Promise<PageModule>> = import.meta.glob<PageModule>(
    './Pages/**/*.tsx',
);

/**
 * Pages choose their own layout by rendering it — a signed-in page wraps
 * itself in <AppLayout>, the login screen does not. There is deliberately no
 * implicit default: a page's chrome is visible in the page's own file, which
 * is where someone looks for it.
 */
void createInertiaApp({
    title: (title) => (title === '' ? appName : `${title} · ${appName}`),

    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, pages).then((module) => module.default),

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },

    progress: {
        // Matches the brand, and thin enough not to be mistaken for content.
        color: 'var(--brand-solid)',
        showSpinner: false,
    },
});
