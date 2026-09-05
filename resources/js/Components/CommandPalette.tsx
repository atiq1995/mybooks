import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { cn } from '@/Utils/cn';
import { NAVIGATION, isAvailable } from '@/Layouts/navigation';

export interface CommandPaletteProps {
    open: boolean;
    onClose: () => void;
}

interface Command {
    id: string;
    label: string;
    /** Where it sits, shown as context — "Sales", "Accounting". */
    group: string;
    href: string;
    available: boolean;
    phase: number;
}

/**
 * Global search and navigation, on Ctrl/Cmd+K.
 *
 * Phase 0 searches the navigation only. The structure — grouped results, a
 * type label on each, keyboard-first selection — is what the server-backed
 * search will fill in Phase 3, when there are invoices, contacts and accounts
 * to find. Building the shape now means the interaction does not change under
 * users later.
 */
export function CommandPalette({ open, onClose }: CommandPaletteProps) {
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    const commands = useMemo(() => buildCommands(), []);

    const results = useMemo(() => {
        const term = query.trim().toLowerCase();
        if (term === '') return commands.slice(0, 8);

        return commands
            .filter(
                (command) =>
                    command.label.toLowerCase().includes(term) ||
                    command.group.toLowerCase().includes(term),
            )
            .slice(0, 12);
    }, [commands, query]);

    // Reset on every open, so the palette never reopens showing the last
    // search. State follows the `open` prop during render — React's documented
    // pattern — and only the focus call, a DOM side-effect, lives in an effect.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setQuery('');
            setActiveIndex(0);
        }
    }

    useEffect(() => {
        if (open) {
            // After paint, or the input is not yet in the document to focus.
            requestAnimationFrame(() => inputRef.current?.focus());
        }
    }, [open]);

    // Keep the highlighted row visible when arrowing past the fold.
    useEffect(() => {
        listRef.current
            ?.querySelector(`[data-index="${activeIndex}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    if (!open) return null;

    const select = (command: Command | undefined) => {
        if (command === undefined) return;
        onClose();
        router.visit(command.href);
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setActiveIndex((index) => (index + 1) % Math.max(results.length, 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActiveIndex(
                    (index) =>
                        (index - 1 + Math.max(results.length, 1)) % Math.max(results.length, 1),
                );
                break;
            case 'Enter':
                event.preventDefault();
                select(results[activeIndex]);
                break;
            case 'Escape':
                event.preventDefault();
                onClose();
                break;
        }
    };

    return (
        <div className="fixed inset-0 z-100 flex items-start justify-center px-4 pt-[12vh]">
            <button
                type="button"
                aria-label="Close search"
                onClick={onClose}
                className="absolute inset-0 bg-neutral-950/40 backdrop-blur-[1px]"
            />

            <div
                role="dialog"
                aria-modal="true"
                aria-label="Search"
                className="border-line bg-surface-overlay shadow-modal relative w-full max-w-xl overflow-hidden rounded-lg border"
            >
                <div className="border-line-subtle flex items-center gap-2.5 border-b px-3.5">
                    <Search className="text-content-muted size-4 shrink-0" aria-hidden="true" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            // A new search starts from the top of its results.
                            setActiveIndex(0);
                        }}
                        onKeyDown={onKeyDown}
                        placeholder="Search invoices, contacts, accounts, settings…"
                        aria-label="Search"
                        aria-controls="command-results"
                        aria-activedescendant={
                            results[activeIndex] !== undefined
                                ? `command-${results[activeIndex].id}`
                                : undefined
                        }
                        className="text-md text-content placeholder:text-content-disabled h-12 flex-1 bg-transparent focus:outline-none"
                    />
                    <kbd className="border-line bg-surface-sunken text-2xs text-content-muted rounded-sm border px-1.5 py-0.5 font-mono">
                        Esc
                    </kbd>
                </div>

                {results.length === 0 ? (
                    <div className="px-4 py-10 text-center">
                        <p className="text-content text-sm">
                            Nothing matched “<span className="font-medium">{query}</span>”
                        </p>
                        <p className="text-content-muted mt-1 text-xs">
                            Search covers navigation today. Invoices, contacts and accounts join in
                            Phase&nbsp;3.
                        </p>
                    </div>
                ) : (
                    /* A listbox's children must be options, directly — no list
                       markup in between, or assistive tech loses the count.
                       Focus stays in the input (aria-activedescendant pattern),
                       so the options carry tabIndex -1 and are not tab stops. */
                    <div
                        ref={listRef}
                        id="command-results"
                        role="listbox"
                        aria-label="Results"
                        className="max-h-80 overflow-y-auto py-1.5"
                    >
                        {results.map((command, index) => (
                            <button
                                key={command.id}
                                type="button"
                                id={`command-${command.id}`}
                                role="option"
                                aria-selected={index === activeIndex}
                                data-index={index}
                                tabIndex={-1}
                                onMouseEnter={() => setActiveIndex(index)}
                                onClick={() => select(command)}
                                className={cn(
                                    'flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm',
                                    index === activeIndex
                                        ? 'bg-surface-selected'
                                        : 'bg-transparent',
                                )}
                            >
                                <span className="text-content flex-1 truncate">
                                    {command.label}
                                </span>

                                <span className="text-2xs text-content-muted shrink-0">
                                    {command.group}
                                </span>

                                {!command.available && (
                                    <span className="bg-surface-active text-2xs text-content-muted shrink-0 rounded-sm px-1.5 py-0.5">
                                        Phase {command.phase}
                                    </span>
                                )}

                                {index === activeIndex && (
                                    <CornerDownLeft
                                        className="text-content-muted size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                )}
                            </button>
                        ))}
                    </div>
                )}

                <div className="border-line-subtle bg-surface-sunken text-2xs text-content-muted flex items-center gap-3 border-t px-3.5 py-2">
                    <span>↑↓ to navigate</span>
                    <span>↵ to open</span>
                    <span>esc to close</span>
                </div>
            </div>
        </div>
    );
}

function buildCommands(): Command[] {
    const commands: Command[] = [];

    const push = (label: string, group: string, href: string, phase: number) => {
        commands.push({
            id: href,
            label,
            group,
            href,
            available: isAvailable(phase),
            phase,
        });
    };

    for (const section of NAVIGATION) {
        if (section.items === undefined) {
            push(section.label, 'Navigate', section.match, section.phase);
            continue;
        }

        for (const item of section.items) {
            push(item.label, section.label, item.match, item.phase);
        }
    }

    // Available things first — an unbuilt module should never outrank a
    // working one in the results.
    return commands.sort((a, b) => Number(b.available) - Number(a.available));
}
