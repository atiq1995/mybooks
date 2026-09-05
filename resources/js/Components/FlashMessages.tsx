import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { cn } from '@/Utils/cn';
import type { SharedProps } from '@/Types/inertia';

type Tone = 'success' | 'error' | 'warning' | 'info';

interface Toast {
    id: number;
    tone: Tone;
    message: string;
}

const ICONS = {
    success: CheckCircle2,
    error: XCircle,
    warning: AlertTriangle,
    info: Info,
} as const;

const TONES: Record<Tone, string> = {
    success: 'border-status-success-line bg-status-success text-status-success-fg',
    error: 'border-status-danger-line bg-status-danger text-status-danger-fg',
    warning: 'border-status-warning-line bg-status-warning text-status-warning-fg',
    info: 'border-status-info-line bg-status-info text-status-info-fg',
};

/**
 * Toast notifications from server-side flash messages.
 *
 * Success and info dismiss themselves; errors and warnings do not. Something
 * that went wrong should stay on screen until it has been read — an error
 * that vanishes after four seconds is an error nobody saw.
 */
export function FlashMessages() {
    const { flash } = usePage<SharedProps>().props;
    const [toasts, setToasts] = useState<Toast[]>([]);

    // New flash → new toasts. Derived during render (React's documented
    // "adjust state when a prop changes" pattern) rather than in an effect,
    // which would cost an extra commit on every navigation.
    const [seenFlash, setSeenFlash] = useState(flash);
    const [batch, setBatch] = useState(0);
    if (flash !== seenFlash) {
        setSeenFlash(flash);
        setBatch(batch + 1);

        const incoming: Toast[] = [];
        for (const [index, tone] of (['success', 'error', 'warning', 'info'] as const).entries()) {
            const message = flash[tone];
            if (typeof message === 'string' && message !== '') {
                // Render must be pure, so ids are derived, not clocked.
                incoming.push({ id: batch * 4 + index, tone, message });
            }
        }

        if (incoming.length > 0) {
            setToasts((current) => [...current, ...incoming]);
        }
    }

    useEffect(() => {
        const transient = toasts.filter(
            (toast) => toast.tone === 'success' || toast.tone === 'info',
        );
        if (transient.length === 0) return;

        const timer = setTimeout(() => {
            setToasts((current) =>
                current.filter((toast) => toast.tone === 'error' || toast.tone === 'warning'),
            );
        }, 5000);

        return () => clearTimeout(timer);
    }, [toasts]);

    if (toasts.length === 0) return null;

    return (
        <div
            // Polite rather than assertive: a save confirmation should not
            // interrupt a screen reader mid-sentence.
            aria-live="polite"
            aria-atomic="false"
            className="pointer-events-none fixed right-4 bottom-4 z-100 flex w-full max-w-sm flex-col gap-2"
        >
            {toasts.map((toast) => {
                const Icon = ICONS[toast.tone];

                return (
                    <div
                        key={toast.id}
                        role={toast.tone === 'error' ? 'alert' : 'status'}
                        className={cn(
                            'shadow-overlay pointer-events-auto flex items-start gap-2.5 rounded-md border p-3',
                            TONES[toast.tone],
                        )}
                    >
                        <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p className="flex-1 text-sm">{toast.message}</p>
                        <button
                            type="button"
                            onClick={() =>
                                setToasts((current) =>
                                    current.filter((item) => item.id !== toast.id),
                                )
                            }
                            aria-label="Dismiss"
                            className="-m-1 shrink-0 rounded p-1 opacity-60 transition-opacity hover:opacity-100"
                        >
                            <X className="size-3.5" aria-hidden="true" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
