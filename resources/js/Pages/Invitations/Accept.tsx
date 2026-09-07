import type { SyntheticEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Building2 } from 'lucide-react';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { AuthLayout } from '@/Layouts/AuthLayout';

type Mode = 'register' | 'sign-in' | 'accept' | 'wrong-account';

interface AcceptProps {
    token: string;
    organization: string | null;
    invitedEmail: string;
    role: string;
    roleDescription: string | null;
    expiresAt: string | null;
    mode: Mode;
    signedInAs: string | null;
}

/**
 * Accepting an invitation.
 *
 * Four situations, and the page commits to one rather than showing a form
 * that might not apply:
 *
 *   register      — no account yet; the invitation creates it
 *   sign-in       — the account exists; sign in first
 *   accept        — signed in as the invited person; one button
 *   wrong-account — signed in as somebody else; say so plainly
 *
 * The email address is never editable. It comes from the invitation, because
 * an invitation that could register any address would be an open registration
 * form wearing a disguise.
 */
export default function AcceptInvitation({
    token,
    organization,
    invitedEmail,
    role,
    roleDescription,
    expiresAt,
    mode,
    signedInAs,
}: AcceptProps) {
    const register = useForm({ name: '', password: '', password_confirmation: '' });

    const submitRegister = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        register.post(`/invitations/${token}/register`, {
            onFinish: () => register.reset('password', 'password_confirmation'),
        });
    };

    const summary = (
        <div className="border-line-subtle bg-surface-sunken mb-5 rounded-md border p-3">
            <div className="flex items-start gap-2.5">
                <span className="bg-brand-subtle flex size-7 shrink-0 items-center justify-center rounded-md">
                    <Building2 className="text-brand-text size-4" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <p className="text-content text-sm font-medium">{organization}</p>
                    <p className="text-content-muted text-xs">
                        You are invited as <span className="text-content-secondary">{role}</span>
                        {' · '}
                        {invitedEmail}
                    </p>
                    {roleDescription !== null && (
                        <p className="text-content-muted mt-1 text-xs">{roleDescription}</p>
                    )}
                </div>
            </div>
        </div>
    );

    return (
        <>
            <Head title={`Join ${organization ?? 'My Books'}`} />

            <AuthLayout
                title={
                    mode === 'wrong-account' ? 'Signed in as someone else' : 'You have been invited'
                }
                description={
                    expiresAt !== null && mode !== 'wrong-account'
                        ? `This invitation expires on ${expiresAt}.`
                        : undefined
                }
                footer={<span />}
            >
                {summary}

                {mode === 'register' && (
                    <form onSubmit={submitRegister} className="flex flex-col gap-4">
                        <Input
                            label="Your name"
                            value={register.data.name}
                            onChange={(e) => register.setData('name', e.target.value)}
                            error={register.errors.name}
                            autoComplete="name"
                            required
                        />

                        {/* Fixed, and shown so it is obvious which address the
                            new account will belong to. */}
                        <Input
                            label="Email address"
                            type="email"
                            value={invitedEmail}
                            readOnly
                            hint="Taken from your invitation and cannot be changed."
                            autoComplete="username"
                        />

                        <Input
                            label="Choose a password"
                            type="password"
                            value={register.data.password}
                            onChange={(e) => register.setData('password', e.target.value)}
                            error={register.errors.password}
                            hint="At least 12 characters. Longer is better than more symbols."
                            autoComplete="new-password"
                            required
                        />

                        <Input
                            label="Confirm password"
                            type="password"
                            value={register.data.password_confirmation}
                            onChange={(e) =>
                                register.setData('password_confirmation', e.target.value)
                            }
                            error={register.errors.password_confirmation}
                            autoComplete="new-password"
                            required
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            fullWidth
                            loading={register.processing}
                        >
                            Create account and join
                        </Button>
                    </form>
                )}

                {mode === 'sign-in' && (
                    <div className="flex flex-col gap-4">
                        <p className="text-content-secondary text-sm">
                            You already have a My Books account for this address. Sign in and this
                            invitation will be waiting.
                        </p>
                        <Button
                            variant="primary"
                            size="lg"
                            fullWidth
                            onClick={() => router.visit('/login')}
                        >
                            Sign in to accept
                        </Button>
                    </div>
                )}

                {mode === 'accept' && (
                    <div className="flex flex-col gap-4">
                        <p className="text-content-secondary text-sm">
                            Accept and {organization} will appear in your organisation switcher.
                        </p>
                        <Button
                            variant="primary"
                            size="lg"
                            fullWidth
                            onClick={() => router.post(`/invitations/${token}/accept`)}
                        >
                            Join {organization}
                        </Button>
                    </div>
                )}

                {mode === 'wrong-account' && (
                    <div className="flex flex-col gap-4">
                        <div className="border-status-warning-line bg-status-warning text-status-warning-fg flex items-start gap-2 rounded-md border p-3 text-sm">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>
                                This invitation was sent to <strong>{invitedEmail}</strong>, but you
                                are signed in as <strong>{signedInAs}</strong>. Sign out and sign
                                back in as the invited person to accept it.
                            </span>
                        </div>

                        <Button
                            variant="secondary"
                            size="lg"
                            fullWidth
                            onClick={() => router.post('/logout')}
                        >
                            Sign out
                        </Button>

                        <Link
                            href="/dashboard"
                            className="text-content-link self-center text-sm hover:underline"
                        >
                            Stay signed in and go to my dashboard
                        </Link>
                    </div>
                )}
            </AuthLayout>
        </>
    );
}
