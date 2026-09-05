import { Head, router, useForm } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import { Button } from '@ui/Button';
import { AuthLayout } from '@/Layouts/AuthLayout';

interface VerifyEmailProps {
    status?: string | null;
}

/**
 * Email verification gate.
 *
 * Required before any financial action: an unverified address means we cannot
 * prove the person controls the mailbox that receives invoices, statements and
 * password resets.
 */
export default function VerifyEmail({ status }: VerifyEmailProps) {
    const form = useForm({});
    const justSent = status === 'verification-link-sent';

    const resend = (event: React.SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/email/verification-notification');
    };

    return (
        <>
            <Head title="Verify your email" />

            <AuthLayout
                title="Verify your email"
                description="We sent a link to the address on your account. Open it to finish setting up."
                footer={
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="text-content-link mt-5 text-sm hover:underline"
                    >
                        Sign out
                    </button>
                }
            >
                {justSent && (
                    <div
                        role="status"
                        className="border-status-success-line bg-status-success text-status-success-fg mb-4 flex items-start gap-2 rounded-md border p-3 text-sm"
                    >
                        <MailCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>A fresh link is on its way.</span>
                    </div>
                )}

                <form onSubmit={resend} className="flex flex-col gap-4">
                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        fullWidth
                        loading={form.processing}
                    >
                        Send the link again
                    </Button>

                    <p className="text-content-muted text-xs">
                        In local development every outgoing email is captured by Mailpit at{' '}
                        <a
                            href="http://localhost:8025"
                            target="_blank"
                            rel="noreferrer noopener"
                            className="text-content-link hover:underline"
                        >
                            localhost:8025
                        </a>{' '}
                        — nothing reaches a real inbox.
                    </p>
                </form>
            </AuthLayout>
        </>
    );
}
