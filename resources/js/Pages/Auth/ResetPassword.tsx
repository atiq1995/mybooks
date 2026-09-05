import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { AuthLayout } from '@/Layouts/AuthLayout';

interface ResetPasswordProps {
    token: string;
    email: string;
}

/**
 * Choose a new password from an emailed reset link.
 *
 * Completing this invalidates every other session for the account — a reset
 * is what someone does when they suspect their password is known, so leaving
 * other sessions alive would defeat the point.
 */
export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/reset-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Choose a new password" />

            <AuthLayout
                title="Choose a new password"
                description="At least 12 characters. Longer is better than more symbols."
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Input
                        label="Email address"
                        type="email"
                        name="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                        autoComplete="username"
                        readOnly
                    />

                    <Input
                        label="New password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                        autoComplete="new-password"
                        required
                    />

                    <Input
                        label="Confirm new password"
                        type="password"
                        name="password_confirmation"
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData('password_confirmation', event.target.value)
                        }
                        error={form.errors.password_confirmation}
                        autoComplete="new-password"
                        required
                    />

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        fullWidth
                        loading={form.processing}
                    >
                        Set new password
                    </Button>

                    <p className="text-content-muted text-xs">
                        Setting a new password signs you out everywhere else.
                    </p>
                </form>
            </AuthLayout>
        </>
    );
}
