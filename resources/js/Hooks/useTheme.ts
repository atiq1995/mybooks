import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import type { Theme } from '@/Types/inertia';

const STORAGE_KEY = 'my-books:theme';

/**
 * Theme, stored per browser and mirrored to the user's profile.
 *
 * localStorage is what the pre-paint script in app.blade.php reads, so the
 * choice applies before the first frame rather than flashing. The server copy
 * is what makes the choice follow the user to another machine.
 *
 * Every storage access is wrapped: in some privacy configurations reading
 * localStorage throws outright, and a theme preference is not worth breaking
 * the application over.
 */
export function useTheme() {
    const [theme, setThemeState] = useState<Exclude<Theme, 'system'>>(() => resolveInitialTheme());

    useEffect(() => {
        document.documentElement.setAttribute('data-theme', theme);
    }, [theme]);

    const setTheme = useCallback((next: Exclude<Theme, 'system'>) => {
        setThemeState(next);

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Non-fatal: the theme still applies for this page view.
        }

        // Persisted quietly — a theme toggle must not reload the page the
        // user is working on or disturb their scroll position.
        router.patch(
            '/settings/appearance',
            { theme: next },
            { preserveScroll: true, preserveState: true, only: [] },
        );
    }, []);

    return { theme, setTheme };
}

function resolveInitialTheme(): Exclude<Theme, 'system'> {
    if (typeof document !== 'undefined') {
        const applied = document.documentElement.getAttribute('data-theme');
        if (applied === 'dark' || applied === 'light') {
            return applied;
        }
    }

    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return 'light';
}
