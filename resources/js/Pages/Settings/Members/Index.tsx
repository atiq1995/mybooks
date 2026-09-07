import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Mail, ShieldAlert, Trash2, UserPlus } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { Card } from '@ui/Card';
import { Badge } from '@ui/Badge';
import { EmptyState } from '@ui/States';

interface Member {
    id: string;
    name: string | null;
    email: string | null;
    role: string;
    role_label: string;
    status: string;
    status_label: string;
    joined_at: string | null;
    invited_at: string | null;
    invitation_expired: boolean;
    is_you: boolean;
    two_factor_enabled: boolean;
}

interface AssignableRole {
    value: string;
    label: string;
    description: string;
}

interface MembersProps {
    members: Member[];
    assignableRoles: AssignableRole[];
    can: { invite: boolean; update_role: boolean; remove: boolean };
}

/**
 * Who has access to this organisation.
 *
 * Roles are changed inline rather than behind an edit screen: the whole point
 * of this page is comparing what people can do, and a modal per person would
 * hide exactly the comparison being made.
 */
export default function Members({ members, assignableRoles, can }: MembersProps) {
    const [inviting, setInviting] = useState(false);

    const invite = useForm({
        email: '',
        role: assignableRoles.at(-1)?.value ?? 'viewer',
    });

    const submitInvite = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        invite.post('/settings/members', {
            preserveScroll: true,
            onSuccess: () => {
                invite.reset('email');
                setInviting(false);
            },
        });
    };

    const changeRole = (member: Member, role: string) => {
        router.patch(`/settings/members/${member.id}`, { role }, { preserveScroll: true });
    };

    const remove = (member: Member) => {
        const what = member.status === 'invited' ? 'Revoke the invitation to' : 'Remove';

        // A destructive action confirms first — and names who it affects, so
        // the confirmation is not a reflex.
        if (!window.confirm(`${what} ${member.email ?? 'this person'}?`)) {
            return;
        }

        router.delete(`/settings/members/${member.id}`, { preserveScroll: true });
    };

    const pending = members.filter((m) => m.status === 'invited');
    const active = members.filter((m) => m.status !== 'invited');

    return (
        <AppLayout
            title="People"
            description="Who has access to this organisation, and what they may do."
            breadcrumbs={[{ label: 'Settings' }, { label: 'People' }]}
            actions={
                can.invite && !inviting ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<UserPlus aria-hidden="true" />}
                        onClick={() => setInviting(true)}
                    >
                        Invite someone
                    </Button>
                ) : undefined
            }
        >
            <Head title="People" />

            {inviting && can.invite && (
                <Card flush className="mb-4">
                    <form onSubmit={submitInvite}>
                        <header className="border-line-subtle border-b px-4 py-3">
                            <h2 className="text-content text-md font-semibold">Invite someone</h2>
                            <p className="text-content-muted text-xs">
                                They receive an email and choose their own password. The link works
                                once and expires.
                            </p>
                        </header>

                        <div className="grid gap-4 p-4 sm:grid-cols-[1fr_14rem]">
                            <Input
                                label="Email address"
                                type="email"
                                value={invite.data.email}
                                onChange={(e) => invite.setData('email', e.target.value)}
                                error={invite.errors.email}
                                placeholder="name@example.com"
                                required
                            />

                            <Select
                                label="Role"
                                value={invite.data.role}
                                onChange={(e) => invite.setData('role', e.target.value)}
                                error={invite.errors.role}
                                options={assignableRoles.map((r) => ({
                                    value: r.value,
                                    label: r.label,
                                }))}
                                required
                            />
                        </div>

                        <p className="text-content-muted px-4 pb-3 text-xs">
                            {assignableRoles.find((r) => r.value === invite.data.role)?.description}
                        </p>

                        <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                            <Button variant="ghost" size="md" onClick={() => setInviting(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="primary"
                                size="md"
                                loading={invite.processing}
                                icon={<Mail aria-hidden="true" />}
                            >
                                Send invitation
                            </Button>
                        </footer>
                    </form>
                </Card>
            )}

            {pending.length > 0 && (
                <Card flush className="mb-4">
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">
                            Pending invitations
                            <span className="text-content-muted ml-2 font-normal">
                                {pending.length}
                            </span>
                        </h2>
                    </header>

                    <MemberTable
                        members={pending}
                        assignableRoles={assignableRoles}
                        can={can}
                        onChangeRole={changeRole}
                        onRemove={remove}
                        pending
                    />
                </Card>
            )}

            <Card flush>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Members</h2>
                </header>

                {active.length === 0 ? (
                    <EmptyState
                        icon={UserPlus}
                        title="Nobody else yet"
                        description="Invite the people who keep these books with you. They choose their own password, and you decide what each of them can do."
                    />
                ) : (
                    <MemberTable
                        members={active}
                        assignableRoles={assignableRoles}
                        can={can}
                        onChangeRole={changeRole}
                        onRemove={remove}
                    />
                )}
            </Card>
        </AppLayout>
    );
}

function MemberTable({
    members,
    assignableRoles,
    can,
    onChangeRole,
    onRemove,
    pending = false,
}: {
    members: Member[];
    assignableRoles: AssignableRole[];
    can: MembersProps['can'];
    onChangeRole: (member: Member, role: string) => void;
    onRemove: (member: Member) => void;
    pending?: boolean;
}) {
    return (
        <div className="table-scroll">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                        <th className="px-4 py-2 text-left font-medium">Person</th>
                        <th className="px-4 py-2 text-left font-medium">Role</th>
                        <th className="px-4 py-2 text-left font-medium">
                            {pending ? 'Invited' : 'Joined'}
                        </th>
                        <th className="w-10 px-4 py-2" />
                    </tr>
                </thead>

                <tbody className="divide-line-subtle divide-y">
                    {members.map((member) => {
                        const canEdit =
                            can.update_role &&
                            !member.is_you &&
                            assignableRoles.some((r) => r.value === member.role);

                        return (
                            <tr key={member.id} className="hover:bg-surface-hover">
                                <td className="px-4 py-2.5">
                                    <div className="flex items-center gap-2">
                                        <span className="text-content font-medium">
                                            {member.name ?? member.email}
                                        </span>
                                        {member.is_you && <Badge tone="brand">You</Badge>}
                                        {member.invitation_expired && (
                                            <Badge tone="danger">Expired</Badge>
                                        )}
                                    </div>
                                    <div className="text-content-muted flex items-center gap-1.5 text-xs">
                                        {member.name !== null && <span>{member.email}</span>}
                                        {!pending && !member.two_factor_enabled && (
                                            <span
                                                className="text-warning-600 inline-flex items-center gap-1"
                                                title="Two-factor authentication is not enabled"
                                            >
                                                <ShieldAlert
                                                    className="size-3"
                                                    aria-hidden="true"
                                                />
                                                No 2FA
                                            </span>
                                        )}
                                    </div>
                                </td>

                                <td className="px-4 py-2.5">
                                    {canEdit ? (
                                        <Select
                                            aria-label={`Role for ${member.email ?? 'member'}`}
                                            value={member.role}
                                            onChange={(e) => onChangeRole(member, e.target.value)}
                                            options={assignableRoles.map((r) => ({
                                                value: r.value,
                                                label: r.label,
                                            }))}
                                            containerClassName="max-w-[11rem]"
                                        />
                                    ) : (
                                        <span className="text-content-secondary">
                                            {member.role_label}
                                        </span>
                                    )}
                                </td>

                                <td className="text-content-muted px-4 py-2.5 text-xs">
                                    {pending ? member.invited_at : member.joined_at}
                                </td>

                                <td className="px-4 py-2.5 text-right">
                                    {can.remove && !member.is_you && (
                                        <button
                                            type="button"
                                            onClick={() => onRemove(member)}
                                            aria-label={
                                                pending
                                                    ? `Revoke invitation to ${member.email ?? ''}`
                                                    : `Remove ${member.email ?? ''}`
                                            }
                                            className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1.5 transition-colors"
                                        >
                                            <Trash2 className="size-3.5" aria-hidden="true" />
                                        </button>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
