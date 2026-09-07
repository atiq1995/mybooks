import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Copy, KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { apiFetch, firstError } from '@/Utils/api';
import { SettingsLayout } from '@/Layouts/SettingsLayout';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { Card, CardHeader } from '@ui/Card';
import { Badge } from '@ui/Badge';

interface SecurityProps {
    twoFactor: {
        enabled: boolean;
        confirmed_at: string | null;
        required_for_your_role: boolean;
        enforced: boolean;
    };
    passkeys: { id: string; name: string; last_used_at: string | null }[];
    session: { last_login_at: string | null; last_login_ip: string | null };
}

/**
 * Password and two-factor.
 *
 * Two-factor is not presented as a nice-to-have. Sixteen permissions require
 * it — posting to the ledger, moving money, changing who has access — so the
 * screen tells this particular person whether their own role depends on it,
 * rather than nagging everybody equally.
 *
 * Enrolment is a three-step flow because that is what it honestly is: enable,
 * scan, then confirm with a real code. Confirming last means nobody can lock
 * themselves out with a mis-scanned QR.
 */
export default function Security({ twoFactor, passkeys, session }: SecurityProps) {
    return (
        <SettingsLayout title="Security" description="How you sign in, and what protects it.">
            <Head title="Security" />

            <div className="flex flex-col gap-4">
                <TwoFactorCard twoFactor={twoFactor} />
                <PasswordCard />
                <PasskeysCard passkeys={passkeys} />
                <SignInCard session={session} />
            </div>
        </SettingsLayout>
    );
}

// ---------------------------------------------------------------------------

function TwoFactorCard({ twoFactor }: { twoFactor: SecurityProps['twoFactor'] }) {
    const [qr, setQr] = useState<string | null>(null);
    const [secret, setSecret] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Enabling two-factor is a sensitive action, so Fortify demands a recent
    // password confirmation first. Collected inline rather than on a separate
    // screen — see the comment in Utils/api.ts for why.
    const [password, setPassword] = useState('');
    const [needsPassword, setNeedsPassword] = useState(false);
    const [code, setCode] = useState('');

    /**
     * Fetch the QR code and the typed-by-hand key.
     *
     * Requested separately rather than shipped as page props: they are served
     * only to the authenticated owner, so keeping them out of the Inertia
     * payload keeps them out of anything that caches or logs it.
     */
    const loadEnrolmentSecret = async () => {
        const [{ svg }, { secretKey }] = await Promise.all([
            apiFetch<{ svg: string }>('/user/two-factor-qr-code'),
            apiFetch<{ secretKey: string }>('/user/two-factor-secret-key'),
        ]);

        setQr(svg);
        setSecret(secretKey);
    };

    const loadRecoveryCodes = async () => {
        setRecoveryCodes(await apiFetch<string[]>('/user/two-factor-recovery-codes'));
    };

    /** Enable, then show the QR. Assumes the password is already confirmed. */
    const enable = async () => {
        await apiFetch('/user/two-factor-authentication', { method: 'POST' });
        await loadEnrolmentSecret();
    };

    /**
     * Begin enrolment.
     *
     * Checks whether the password has been confirmed recently and asks for it
     * inline if not, rather than letting Fortify redirect away from a
     * half-finished setup.
     */
    const begin = async () => {
        setBusy(true);
        setError(null);

        try {
            const { confirmed } = await apiFetch<{ confirmed: boolean }>(
                '/user/confirmed-password-status',
            );

            if (!confirmed) {
                setNeedsPassword(true);

                return;
            }

            await enable();
        } catch (e) {
            setError(firstError(e, 'password') ?? 'Two-factor could not be set up. Try again.');
        } finally {
            setBusy(false);
        }
    };

    const confirmPasswordThenEnable = async (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await apiFetch('/user/confirm-password', {
                method: 'POST',
                body: JSON.stringify({ password }),
            });

            setPassword('');
            setNeedsPassword(false);
            await enable();
        } catch (e) {
            setError(firstError(e, 'password') ?? 'That password was not accepted.');
        } finally {
            setBusy(false);
        }
    };

    const submitCode = async (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await apiFetch('/user/confirmed-two-factor-authentication', {
                method: 'POST',
                body: JSON.stringify({ code }),
            });

            setQr(null);
            setSecret(null);
            setCode('');
            // Show the recovery codes immediately — this is the one moment
            // they are worth presenting.
            await loadRecoveryCodes();
            // Refresh so the card reflects that two-factor is now on.
            router.reload({ only: ['twoFactor'] });
        } catch (e) {
            setError(firstError(e, 'code') ?? 'That code was not accepted. Try the next one.');
        } finally {
            setBusy(false);
        }
    };

    const disable = () => {
        if (
            !window.confirm(
                'Turn off two-factor authentication? Anything that requires it will stop working for you.',
            )
        ) {
            return;
        }
        router.delete('/user/two-factor-authentication', { preserveScroll: true });
    };

    const enrolling = qr !== null;

    return (
        <Card flush>
            <CardHeader
                title="Two-factor authentication"
                description="A code from your phone, in addition to your password."
                actions={
                    twoFactor.enabled ? (
                        <Badge tone="success" dot>
                            On
                        </Badge>
                    ) : twoFactor.required_for_your_role ? (
                        <Badge tone="danger" dot>
                            Required
                        </Badge>
                    ) : (
                        <Badge tone="neutral" dot>
                            Off
                        </Badge>
                    )
                }
            />

            <div className="flex flex-col gap-4 p-4">
                {/* Why it matters to THIS person, not a generic nag. */}
                {!twoFactor.enabled && twoFactor.required_for_your_role && (
                    <div className="border-status-danger-line bg-status-danger text-status-danger-fg flex items-start gap-2 rounded-md border p-3 text-sm">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>
                            Your role can post to the ledger, move money or change who has access.
                            {twoFactor.enforced
                                ? ' Those actions are blocked until you turn this on.'
                                : ' Enforcement is off in this environment, but those actions will be blocked once it is on.'}
                        </span>
                    </div>
                )}

                {twoFactor.enabled && !enrolling && (
                    <>
                        <p className="text-content-secondary text-sm">
                            Enabled
                            {twoFactor.confirmed_at !== null && ` on ${twoFactor.confirmed_at}`}.
                        </p>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="secondary"
                                size="md"
                                onClick={() => {
                                    void loadRecoveryCodes();
                                }}
                            >
                                Show recovery codes
                            </Button>

                            <Button
                                variant="secondary"
                                size="md"
                                onClick={() =>
                                    router.post(
                                        '/user/two-factor-recovery-codes',
                                        {},
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setRecoveryCodes(null),
                                        },
                                    )
                                }
                            >
                                Regenerate codes
                            </Button>

                            <Button variant="danger" size="md" onClick={disable}>
                                Turn off
                            </Button>
                        </div>
                    </>
                )}

                {error !== null && (
                    <p role="alert" className="text-danger-600 text-sm">
                        {error}
                    </p>
                )}

                {!twoFactor.enabled && !enrolling && !needsPassword && (
                    <div>
                        <p className="text-content-secondary mb-3 text-sm">
                            You will need an authenticator app — Google Authenticator, 1Password,
                            Authy, or any other TOTP app.
                        </p>
                        <Button
                            variant="primary"
                            size="md"
                            loading={busy}
                            icon={<ShieldCheck aria-hidden="true" />}
                            onClick={() => {
                                void begin();
                            }}
                        >
                            Set up two-factor
                        </Button>
                    </div>
                )}

                {needsPassword && (
                    <form
                        onSubmit={(e) => {
                            void confirmPasswordThenEnable(e);
                        }}
                        className="flex flex-col gap-3"
                    >
                        <p className="text-content-secondary text-sm">
                            Confirm your password to continue. This is a sensitive change, so we ask
                            even though you are already signed in.
                        </p>

                        <Input
                            label="Your password"
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            autoComplete="current-password"
                            containerClassName="max-w-sm"
                            required
                        />

                        <div className="flex items-center gap-2">
                            <Button type="submit" variant="primary" size="md" loading={busy}>
                                Confirm and continue
                            </Button>
                            <Button
                                variant="ghost"
                                size="md"
                                onClick={() => {
                                    setNeedsPassword(false);
                                    setPassword('');
                                    setError(null);
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                {enrolling && (
                    <div className="flex flex-col gap-4">
                        <p className="text-content-secondary text-sm">
                            Scan this with your authenticator app, then enter the six-digit code it
                            shows to confirm.
                        </p>

                        <div className="flex flex-wrap items-start gap-5">
                            {/* Fortify returns the QR as an inline SVG. It is
                                generated server-side from the user's own secret
                                and contains no third-party content. */}
                            <div
                                className="border-line-subtle bg-surface-base rounded-md border p-3 [&_svg]:size-40"
                                dangerouslySetInnerHTML={{ __html: qr }}
                            />

                            <div className="min-w-0 flex-1">
                                <p className="text-content-secondary text-xs font-medium">
                                    Cannot scan it?
                                </p>
                                <p className="text-content-muted mb-2 text-xs">
                                    Enter this key into your app by hand.
                                </p>
                                <code className="border-line-subtle bg-surface-sunken text-content block rounded border px-2 py-1.5 font-mono text-xs break-all">
                                    {secret}
                                </code>

                                <form
                                    onSubmit={(e) => {
                                        void submitCode(e);
                                    }}
                                    className="mt-4 flex flex-col gap-3"
                                >
                                    <Input
                                        label="Six-digit code"
                                        value={code}
                                        onChange={(e) => setCode(e.target.value)}
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        required
                                    />
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        size="md"
                                        loading={busy}
                                    >
                                        Confirm and turn on
                                    </Button>
                                </form>
                            </div>
                        </div>
                    </div>
                )}

                {recoveryCodes !== null && (
                    <div className="border-status-warning-line bg-status-warning rounded-md border p-3">
                        <p className="text-status-warning-fg text-sm font-medium">Recovery codes</p>
                        <p className="text-status-warning-fg mb-2.5 text-xs">
                            Save these somewhere safe. Each works once, and they are the only way
                            back in if you lose your device.
                        </p>

                        <ul className="grid grid-cols-2 gap-1 sm:grid-cols-4">
                            {recoveryCodes.map((code) => (
                                <li
                                    key={code}
                                    className="border-line-subtle bg-surface-base text-content rounded border px-2 py-1 font-mono text-xs"
                                >
                                    {code}
                                </li>
                            ))}
                        </ul>

                        <div className="mt-2.5 flex items-center gap-2">
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={<Copy aria-hidden="true" />}
                                onClick={() => {
                                    void navigator.clipboard.writeText(recoveryCodes.join('\n'));
                                }}
                            >
                                Copy all
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setRecoveryCodes(null)}
                            >
                                I have saved them
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </Card>
    );
}

// ---------------------------------------------------------------------------

function PasswordCard() {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put('/user/password', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <Card flush>
            <CardHeader title="Password" description="Changing it signs you out everywhere else." />

            <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                <Input
                    label="Current password"
                    type="password"
                    value={form.data.current_password}
                    onChange={(e) => form.setData('current_password', e.target.value)}
                    error={form.errors.current_password}
                    autoComplete="current-password"
                    required
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label="New password"
                        type="password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        error={form.errors.password}
                        hint="At least 12 characters."
                        autoComplete="new-password"
                        required
                    />

                    <Input
                        label="Confirm new password"
                        type="password"
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                        error={form.errors.password_confirmation}
                        autoComplete="new-password"
                        required
                    />
                </div>

                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        disabled={form.data.password === ''}
                    >
                        Change password
                    </Button>
                </div>
            </form>
        </Card>
    );
}

// ---------------------------------------------------------------------------

function PasskeysCard({ passkeys }: { passkeys: SecurityProps['passkeys'] }) {
    return (
        <Card flush>
            <CardHeader
                title="Passkeys"
                description="Sign in with your device instead of a password."
            />

            <div className="p-4">
                {passkeys.length === 0 ? (
                    <p className="text-content-muted text-sm">
                        No passkeys registered. Two-factor is the better first step — passkey
                        enrolment lands with the rest of the security settings work.
                    </p>
                ) : (
                    <ul className="divide-line-subtle divide-y">
                        {passkeys.map((passkey) => (
                            <li
                                key={passkey.id}
                                className="flex items-center justify-between gap-3 py-2"
                            >
                                <span className="flex items-center gap-2">
                                    <KeyRound
                                        className="text-content-muted size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span className="text-content text-sm">{passkey.name}</span>
                                </span>
                                <span className="text-content-muted text-xs">
                                    {passkey.last_used_at ?? 'Never used'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Card>
    );
}

// ---------------------------------------------------------------------------

function SignInCard({ session }: { session: SecurityProps['session'] }) {
    return (
        <Card flush>
            <CardHeader
                title="Recent sign-in"
                description="If this was not you, change your password."
            />

            <dl className="divide-line-subtle divide-y">
                <div className="flex items-center justify-between gap-4 px-4 py-2.5">
                    <dt className="text-content-secondary text-sm">Last signed in</dt>
                    <dd className="text-content text-sm">{session.last_login_at ?? '—'}</dd>
                </div>
                <div className="flex items-center justify-between gap-4 px-4 py-2.5">
                    <dt className="text-content-secondary text-sm">From</dt>
                    <dd className="text-content font-mono text-sm">
                        {session.last_login_ip ?? '—'}
                    </dd>
                </div>
            </dl>
        </Card>
    );
}
