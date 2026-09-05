import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { AuthLayout } from '@/Layouts/AuthLayout';

/**
 * Second factor at sign-in.
 *
 * Offers the recovery-code path in the same breath as the authenticator code,
 * because the moment someone needs a recovery code is the moment they have
 * lost the device — and hiding it behind a support request helps nobody.
 */
export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);

    const form = useForm({
        code: '',
        recovery_code: '',
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/two-factor-challenge', {
            onFinish: () => form.reset('code', 'recovery_code'),
        });
    };

    const swap = () => {
        setUseRecovery((value) => !value);
        form.reset('code', 'recovery_code');
        form.clearErrors();
    };

    return (
        <>
            <Head title="Two-factor authentication" />

            <AuthLayout
                title="Two-factor authentication"
                description={
                    useRecovery
                        ? 'Enter one of the recovery codes you saved when you set this up. Each works once.'
                        : 'Enter the six-digit code from your authenticator app.'
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    {useRecovery ? (
                        <Input
                            label="Recovery code"
                            name="recovery_code"
                            value={form.data.recovery_code}
                            onChange={(event) => form.setData('recovery_code', event.target.value)}
                            error={form.errors.recovery_code}
                            autoComplete="one-time-code"
                            required
                        />
                    ) : (
                        <Input
                            label="Authentication code"
                            name="code"
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            error={form.errors.code}
                            // A numeric one-time code: surface the digit keypad,
                            // and let password managers fill it.
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            required
                        />
                    )}

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        fullWidth
                        loading={form.processing}
                    >
                        Continue
                    </Button>

                    <button
                        type="button"
                        onClick={swap}
                        className="text-content-link self-center text-xs hover:underline"
                    >
                        {useRecovery
                            ? 'Use an authenticator code instead'
                            : 'I have lost my device — use a recovery code'}
                    </button>
                </form>
            </AuthLayout>
        </>
    );
}
