import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { MailCheck, MailWarning } from 'lucide-react';
import { SettingsLayout } from '@/Layouts/SettingsLayout';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { Card, CardHeader } from '@ui/Card';

interface ProfileProps {
    profile: {
        name: string;
        email: string;
        email_verified: boolean;
        timezone: string;
        locale: string;
        created_at: string | null;
    };
    options: { timezones: { value: string; label: string }[] };
}

/**
 * Personal profile.
 *
 * These follow the person between organisations. The organisation's own
 * locale and timezone govern its documents, and are set separately — a
 * distinction worth stating on the page, because "my timezone" and "the dates
 * on our invoices" are easy to conflate.
 */
export default function Profile({ profile, options }: ProfileProps) {
    const form = useForm({
        name: profile.name,
        email: profile.email,
        timezone: profile.timezone,
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put('/user/profile-information', { preserveScroll: true });
    };

    // Changing the address means re-proving it, so say so before they do.
    const emailChanged = form.data.email.trim().toLowerCase() !== profile.email.toLowerCase();

    return (
        <SettingsLayout title="Profile" description="Your details, across every organisation.">
            <Head title="Profile" />

            <Card flush>
                <CardHeader
                    title="Your details"
                    description={
                        profile.created_at !== null
                            ? `You joined My Books on ${profile.created_at}.`
                            : undefined
                    }
                />

                <form onSubmit={submit} className="flex flex-col gap-4 p-4">
                    <Input
                        label="Full name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        hint="Shown on the audit trail beside everything you do."
                        autoComplete="name"
                        required
                    />

                    <Input
                        label="Email address"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                        autoComplete="username"
                        required
                    />

                    {emailChanged && (
                        <p className="text-warning-600 -mt-2 flex items-start gap-1.5 text-xs">
                            <MailWarning className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            Changing this means verifying the new address before you can do anything
                            that affects the books.
                        </p>
                    )}

                    {!profile.email_verified && !emailChanged && (
                        <div className="border-status-warning-line bg-status-warning text-status-warning-fg -mt-2 flex items-start justify-between gap-3 rounded-md border p-3">
                            <span className="flex items-start gap-2 text-sm">
                                <MailWarning
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                This address has not been verified yet.
                            </span>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => router.post('/email/verification-notification')}
                            >
                                Resend
                            </Button>
                        </div>
                    )}

                    {profile.email_verified && !emailChanged && (
                        <p className="text-content-muted -mt-2 flex items-center gap-1.5 text-xs">
                            <MailCheck className="size-3.5 shrink-0" aria-hidden="true" />
                            Verified.
                        </p>
                    )}

                    <Select
                        label="Your time zone"
                        value={form.data.timezone}
                        onChange={(e) => form.setData('timezone', e.target.value)}
                        error={form.errors.timezone}
                        options={options.timezones}
                        hint="Affects how times are shown to you. Each organisation's documents use its own time zone."
                        required
                    />

                    <div className="flex items-center gap-2">
                        <Button
                            type="submit"
                            variant="primary"
                            size="md"
                            loading={form.processing}
                            disabled={!form.isDirty}
                        >
                            Save changes
                        </Button>

                        {form.isDirty && (
                            <Button variant="ghost" size="md" onClick={() => form.reset()}>
                                Discard
                            </Button>
                        )}

                        {form.recentlySuccessful && (
                            <span className="text-success-600 text-xs">Saved.</span>
                        )}
                    </div>
                </form>
            </Card>
        </SettingsLayout>
    );
}
