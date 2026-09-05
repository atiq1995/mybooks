import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { AuthLayout } from '@/Layouts/AuthLayout';

/**
 * Password confirmation before a sensitive action.
 *
 * Stands between a live session and the controls that could lock someone out
 * of their own books — changing two-factor settings, minting API tokens,
 * managing users. A stolen session alone is not enough to reach those.
 */
export default function ConfirmPassword() {
    const form = useForm({ password: '' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/user/confirm-password', {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <>
            <Head title="Confirm your password" />

            <AuthLayout
                title="Confirm your password"
                description="This is a sensitive area. Please confirm your password before continuing."
            >
                <div className="border-status-warning-line bg-status-warning text-status-warning-fg mb-4 flex items-start gap-2 rounded-md border p-3 text-sm">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>You will not be asked again for a few hours.</span>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Input
                        label="Password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                        autoComplete="current-password"
                        required
                    />

                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        fullWidth
                        loading={form.processing}
                    >
                        Confirm
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
