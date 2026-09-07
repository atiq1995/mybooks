import { Head, router } from '@inertiajs/react';
import { Check, Monitor, Moon, Rows2, Rows3, Sun } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { SettingsLayout } from '@/Layouts/SettingsLayout';
import { Card, CardHeader } from '@ui/Card';
import { cn } from '@/Utils/cn';
import { useTheme } from '@/Hooks/useTheme';

interface AppearanceProps {
    appearance: { theme: string; density: string };
}

/**
 * Theme and density.
 *
 * Density earns a settings screen where theme does not: it is a considered
 * choice rather than something to flip on a whim, and it changes how much of
 * a ledger fits on screen. Compact shows roughly a third more rows, which is
 * what an accountant scanning two hundred lines actually wants; comfortable
 * is easier for occasional use.
 *
 * Choices are shown as previews rather than a dropdown, because the effect is
 * visual and a label cannot convey it.
 */
export default function Appearance({ appearance }: AppearanceProps) {
    const { theme, setTheme } = useTheme();

    const setDensity = (density: 'compact' | 'comfortable') => {
        document.documentElement.setAttribute('data-density', density);

        try {
            localStorage.setItem('my-books:density', density);
        } catch {
            // Non-fatal: it applies for this page view either way.
        }

        router.patch(
            '/settings/appearance',
            { density },
            { preserveScroll: true, preserveState: true, only: [] },
        );
    };

    return (
        <SettingsLayout title="Appearance" description="How My Books looks on this device.">
            <Head title="Appearance" />

            <div className="flex flex-col gap-4">
                <Card flush>
                    <CardHeader
                        title="Theme"
                        description="Applies immediately, and follows you to your other devices."
                    />

                    <div className="grid gap-3 p-4 sm:grid-cols-3">
                        <ThemeChoice
                            icon={Sun}
                            label="Light"
                            selected={theme === 'light'}
                            onSelect={() => setTheme('light')}
                        />
                        <ThemeChoice
                            icon={Moon}
                            label="Dark"
                            selected={theme === 'dark'}
                            onSelect={() => setTheme('dark')}
                        />
                        <ThemeChoice
                            icon={Monitor}
                            label="Match my system"
                            selected={false}
                            onSelect={() => {
                                try {
                                    localStorage.removeItem('my-books:theme');
                                } catch {
                                    // Non-fatal.
                                }
                                window.location.reload();
                            }}
                        />
                    </div>
                </Card>

                <Card flush>
                    <CardHeader
                        title="Density"
                        description="How much fits on screen. Compact shows about a third more rows."
                    />

                    <div className="grid gap-3 p-4 sm:grid-cols-2">
                        <DensityChoice
                            icon={Rows3}
                            label="Compact"
                            hint="For scanning long ledgers"
                            rows={7}
                            selected={appearance.density === 'compact'}
                            onSelect={() => setDensity('compact')}
                        />
                        <DensityChoice
                            icon={Rows2}
                            label="Comfortable"
                            hint="Easier for occasional use"
                            rows={4}
                            selected={appearance.density === 'comfortable'}
                            onSelect={() => setDensity('comfortable')}
                        />
                    </div>
                </Card>
            </div>
        </SettingsLayout>
    );
}

function ThemeChoice({
    icon: Icon,
    label,
    selected,
    onSelect,
}: {
    icon: LucideIcon;
    label: string;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={selected}
            className={cn(
                'flex items-center gap-2.5 rounded-md border px-3 py-2.5 text-left transition-colors',
                'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-2',
                selected
                    ? 'border-line-brand bg-brand-subtle'
                    : 'border-line hover:bg-surface-hover',
            )}
        >
            <Icon
                className={cn(
                    'size-4 shrink-0',
                    selected ? 'text-brand-text' : 'text-content-muted',
                )}
                aria-hidden="true"
            />
            <span className="text-content flex-1 text-sm font-medium">{label}</span>
            {selected && <Check className="text-brand size-3.5" aria-hidden="true" />}
        </button>
    );
}

function DensityChoice({
    icon: Icon,
    label,
    hint,
    rows,
    selected,
    onSelect,
}: {
    icon: LucideIcon;
    label: string;
    hint: string;
    rows: number;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={selected}
            className={cn(
                'flex flex-col gap-2.5 rounded-md border p-3 text-left transition-colors',
                'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-2',
                selected
                    ? 'border-line-brand bg-brand-subtle'
                    : 'border-line hover:bg-surface-hover',
            )}
        >
            <span className="flex items-center gap-2">
                <Icon
                    className={cn(
                        'size-4 shrink-0',
                        selected ? 'text-brand-text' : 'text-content-muted',
                    )}
                    aria-hidden="true"
                />
                <span className="text-content flex-1 text-sm font-medium">{label}</span>
                {selected && <Check className="text-brand size-3.5" aria-hidden="true" />}
            </span>

            {/* A miniature of the effect, since the difference is visual and a
                label cannot convey it. Both previews are the same height, so
                the only thing that changes is how many rows fit — which is
                exactly the trade-off being chosen. */}
            <span
                className="border-line-subtle bg-surface-base flex h-20 flex-col justify-start overflow-hidden rounded border p-1.5"
                aria-hidden="true"
                style={{ gap: rows > 5 ? 3 : 6 }}
            >
                {Array.from({ length: rows }, (_, i) => (
                    <span
                        key={i}
                        className="bg-surface-active shrink-0 rounded-xs"
                        style={{ height: rows > 5 ? 5 : 11 }}
                    />
                ))}
            </span>

            <span className="text-content-muted text-xs">{hint}</span>
        </button>
    );
}
