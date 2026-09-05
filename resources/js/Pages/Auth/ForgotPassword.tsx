import type { SyntheticEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, MailCheck } from 'lucide-react';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { LogoWithName } from '@/Components/Logo';

interface ForgotPasswordProps {
    /** Fortify sends null when there is nothing to say, not undefined. */
    status?: string | null;
}

/**
 * Request a password reset link.
 *
 * The success message is the same whether or not the address exists — a form
 * that says "no account found" is an account-enumeration tool.
 */
export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const form = useForm({ email: '' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/forgot-password');
    };

    const sent = typeof status === 'string' && status !== '';

    return (
        <>
            <Head title="Reset password" />

            <div className="bg-surface-sunken flex min-h-dvh items-center justify-center px-6 py-10">
                <div className="w-full max-w-sm">
                    <LogoWithName className="mb-8" />

                    <div className="border-line-subtle bg-surface-base rounded-md border p-6">
                        {sent ? (
                            <div className="flex flex-col items-center py-2 text-center">
                                <div className="bg-status-success mb-3 flex size-10 items-center justify-center rounded-full">
                                    <MailCheck
                                        className="text-status-success-fg size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <h1 className="text-content text-lg font-semibold">
                                    Check your email
                                </h1>
                                <p className="text-content-muted mt-1.5 text-sm">{status}</p>
                            </div>
                        ) : (
                            <>
                                <h1 className="text-content text-lg font-semibold">
                                    Reset your password
                                </h1>
                                <p className="text-content-muted mt-1 text-sm">
                                    Enter the email address on your account and we will send a link
                                    to choose a new password.
                                </p>

                                <form onSubmit={submit} className="mt-5 flex flex-col gap-4">
                                    <Input
                                        label="Email address"
                                        type="email"
                                        name="email"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData('email', event.target.value)
                                        }
                                        error={form.errors.email}
                                        autoComplete="username"
                                        required
                                    />

                                    <Button
                                        type="submit"
                                        variant="primary"
                                        size="lg"
                                        fullWidth
                                        loading={form.processing}
                                    >
                                        Send reset link
                                    </Button>
                                </form>
                            </>
                        )}
                    </div>

                    <Link
                        href="/login"
                        className="text-content-link mt-5 inline-flex items-center gap-1.5 text-sm hover:underline"
                    >
                        <ArrowLeft className="size-3.5" aria-hidden="true" />
                        Back to sign in
                    </Link>
                </div>
            </div>
        </>
    );
}
