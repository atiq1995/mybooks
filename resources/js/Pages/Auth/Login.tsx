import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { AlertCircle, Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { LogoWithName } from '@/Components/Logo';

interface LoginProps {
    /** Fortify sends null when there is nothing to say, not undefined. */
    status?: string | null;
    canResetPassword: boolean;
}

/**
 * Sign in.
 *
 * A two-panel layout: the form on the left at a comfortable reading width,
 * and a quiet panel on the right that says what the product is. The panel is
 * decoration in the sense that it carries no controls — but it is the first
 * thing a new user sees, and a blank half-screen would say the product was
 * unfinished.
 */
export default function Login({ status, canResetPassword }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);

    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/login', {
            // Never leave a password in memory after a failed attempt.
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <>
            <Head title="Sign in" />

            <div className="bg-surface-base flex min-h-dvh">
                {/* Form */}
                <div className="flex w-full flex-col justify-center px-6 py-10 sm:px-12 lg:w-[52%] lg:px-16">
                    <div className="mx-auto w-full max-w-sm">
                        <LogoWithName className="mb-8" />

                        <h1 className="text-content text-2xl font-semibold tracking-tight">
                            Sign in
                        </h1>
                        <p className="text-content-muted mt-1 text-sm">
                            Enter your details to reach your books.
                        </p>

                        {typeof status === 'string' && status !== '' && (
                            <div
                                role="status"
                                className="border-status-success-line bg-status-success text-status-success-fg mt-5 rounded-md border p-3 text-sm"
                            >
                                {status}
                            </div>
                        )}

                        {/* A failed sign-in is reported once, above the form —
                            not duplicated onto both fields, which tells the
                            user nothing about which one was wrong (and must
                            not, since that would confirm a valid email). */}
                        {form.errors.email !== undefined && (
                            <div
                                role="alert"
                                className="border-status-danger-line bg-status-danger text-status-danger-fg mt-5 flex items-start gap-2 rounded-md border p-3 text-sm"
                            >
                                <AlertCircle
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{form.errors.email}</span>
                            </div>
                        )}

                        <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
                            <Input
                                label="Email address"
                                type="email"
                                name="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                autoComplete="username"
                                required
                            />

                            <div className="flex flex-col gap-1">
                                <Input
                                    label="Password"
                                    type={showPassword ? 'text' : 'password'}
                                    name="password"
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData('password', event.target.value)
                                    }
                                    autoComplete="current-password"
                                    required
                                    suffix={
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword((value) => !value)}
                                            aria-label={
                                                showPassword ? 'Hide password' : 'Show password'
                                            }
                                            className="text-content-muted hover:text-content -mr-1 rounded p-1 transition-colors"
                                        >
                                            {showPassword ? (
                                                <EyeOff className="size-3.5" aria-hidden="true" />
                                            ) : (
                                                <Eye className="size-3.5" aria-hidden="true" />
                                            )}
                                        </button>
                                    }
                                />

                                {canResetPassword && (
                                    <a
                                        href="/forgot-password"
                                        className="text-content-link self-end text-xs hover:underline"
                                    >
                                        Forgot your password?
                                    </a>
                                )}
                            </div>

                            <label className="text-content-secondary flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.remember}
                                    onChange={(event) =>
                                        form.setData('remember', event.target.checked)
                                    }
                                    className="border-line text-brand focus-visible:outline-focus size-3.5 rounded-xs focus-visible:outline-2 focus-visible:outline-offset-2"
                                />
                                Keep me signed in
                            </label>

                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                fullWidth
                                loading={form.processing}
                            >
                                Sign in
                            </Button>
                        </form>

                        <p className="text-content-muted mt-8 flex items-center gap-1.5 text-xs">
                            <ShieldCheck className="size-3.5" aria-hidden="true" />
                            Self-hosted. Your books never leave your server.
                        </p>
                    </div>
                </div>

                {/* Context panel */}
                <div className="border-line-subtle bg-surface-sunken relative hidden overflow-hidden border-l lg:block lg:w-[48%]">
                    <div className="flex h-full flex-col justify-center px-14">
                        <p className="text-2xs text-content-muted font-mono tracking-[0.14em] uppercase">
                            Double-entry accounting
                        </p>

                        <p className="text-content mt-4 max-w-md text-xl leading-snug font-medium text-balance">
                            Every figure in this system traces back to a journal entry that
                            balances.
                        </p>

                        <p className="text-content-secondary mt-3 max-w-md text-sm">
                            Invoices, bills, expenses and reconciliations all post through one
                            ledger — so a report is never an estimate of what your books say.
                        </p>

                        {/* A small, real ledger. Chosen over an abstract graphic because
                            it shows precisely what the product does. */}
                        <div
                            className="border-line-subtle bg-surface-base mt-9 max-w-md overflow-hidden rounded-md border"
                            aria-hidden="true"
                        >
                            <div className="border-line-subtle bg-surface-sunken flex items-center justify-between border-b px-3 py-2">
                                <span className="text-2xs text-content-muted font-mono tracking-wide uppercase">
                                    Journal · INV-000041
                                </span>
                                <span className="border-status-success-line bg-status-success text-2xs text-status-success-fg rounded-sm border px-1.5 py-0.5">
                                    Balanced
                                </span>
                            </div>

                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="text-2xs text-content-muted tracking-wide uppercase">
                                        <th className="px-3 py-1.5 text-left font-medium">
                                            Account
                                        </th>
                                        <th className="px-3 py-1.5 text-right font-medium">
                                            Debit
                                        </th>
                                        <th className="px-3 py-1.5 text-right font-medium">
                                            Credit
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-subtle divide-y">
                                    {[
                                        ['1200 Accounts Receivable', '112,100.00', ''],
                                        ['4900 Trade Discounts', '5,000.00', ''],
                                        ['4000 Sales Revenue', '', '100,000.00'],
                                        ['2300 GST Output Payable', '', '17,100.00'],
                                    ].map(([account, debit, credit]) => (
                                        <tr key={account}>
                                            <td className="text-content-secondary px-3 py-1.5 font-mono">
                                                {account}
                                            </td>
                                            <td className="text-content px-3 py-1.5 text-right tabular-nums">
                                                {debit === '' ? '—' : debit}
                                            </td>
                                            <td className="text-content px-3 py-1.5 text-right tabular-nums">
                                                {credit === '' ? '—' : credit}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-line bg-surface-sunken border-t-2 font-medium">
                                        <td className="text-content-secondary px-3 py-1.5">
                                            Total
                                        </td>
                                        <td className="text-content px-3 py-1.5 text-right tabular-nums">
                                            117,100.00
                                        </td>
                                        <td className="text-content px-3 py-1.5 text-right tabular-nums">
                                            117,100.00
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
