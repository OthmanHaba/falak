import { Avatar } from '@/components/kiln/avatar';
import { Button } from '@/components/kiln/button';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { DataTable } from '@/components/kiln/data-table';
import { Dialog } from '@/components/kiln/dialog';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Section } from '@/components/kiln/section';
import { Select } from '@/components/kiln/select';
import { Tag } from '@/components/kiln/tag';
import { Tooltip } from '@/components/kiln/tooltip';
import SettingsLayout from '@/layouts/settings/layout';
import { type OrganizationRole, type SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { LogOut, Mail, ShieldCheck, Trash2, UserMinus, UserPlus } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

interface Member {
    id: string;
    name: string;
    email: string;
    role: OrganizationRole | null;
    two_factor: boolean;
    joined_at: string | null;
}

interface Invitation {
    id: string;
    email: string;
    role: OrganizationRole;
    invited_by: string | null;
    expires_at: string;
}

interface RoleOption {
    value: OrganizationRole;
    label: string;
    description: string;
    assignable: boolean;
}

interface MembersProps {
    members: Member[];
    invitations: Invitation[];
    roles: RoleOption[];
    myRole: OrganizationRole | null;
    canManage: boolean;
}

const rank: Record<OrganizationRole, number> = { owner: 40, admin: 30, developer: 20, viewer: 10 };

export default function Members({ members, invitations, roles, myRole, canManage }: MembersProps) {
    const { auth } = usePage<SharedData>().props;
    const [inviteOpen, setInviteOpen] = useState(false);
    const [removing, setRemoving] = useState<Member | null>(null);
    const [removeProcessing, setRemoveProcessing] = useState(false);
    const invite = useForm<{ email: string; role: OrganizationRole }>({ email: '', role: 'developer' });
    const myRank = myRole ? rank[myRole] : 0;

    const canEdit = (member: Member) =>
        canManage && member.id !== auth.user.id && member.role !== null && member.role !== 'owner' && myRank > rank[member.role];

    const assignable = roles.filter((role) => role.assignable && rank[role.value] <= myRank);
    const roleOptions = assignable.map((role) => ({ value: role.value, label: role.label }));

    const submitInvite: FormEventHandler = (event) => {
        event.preventDefault();
        invite.post(route('organization.invitations.store'), {
            preserveScroll: true,
            onSuccess: () => {
                invite.reset();
                setInviteOpen(false);
            },
        });
    };

    const changeRole = (member: Member, role: string) =>
        router.patch(route('organization.members.update', member.id), { role }, { preserveScroll: true });

    const remove = () => {
        if (!removing) return;
        router.delete(route('organization.members.destroy', removing.id), {
            preserveScroll: true,
            onStart: () => setRemoveProcessing(true),
            onFinish: () => {
                setRemoveProcessing(false);
                setRemoving(null);
            },
        });
    };

    const revoke = (invitation: Invitation) => router.delete(route('organization.invitations.destroy', invitation.id), { preserveScroll: true });
    const leaving = removing?.id === auth.user.id;

    return (
        <SettingsLayout
            title="Members"
            description="Everyone with access to this organization and their role."
            wide
            actions={
                canManage && (
                    <Button variant="primary" icon={<UserPlus />} onClick={() => setInviteOpen(true)}>
                        Invite
                    </Button>
                )
            }
        >
            <DataTable
                label="Members"
                rows={members}
                rowKey={(member) => member.id}
                defaultSort={{ column: 'member', direction: 'asc' }}
                columns={[
                    {
                        id: 'member',
                        header: 'Member',
                        sortValue: (member) => member.name,
                        cell: (member) => (
                            <div className="flex min-w-0 items-center gap-2.5 py-1.5">
                                <Avatar name={member.name} size="sm" />
                                <div className="grid min-w-0">
                                    <span className="flex items-center gap-1.5 truncate font-medium">
                                        {member.name}
                                        {member.id === auth.user.id && <span className="text-fg-faint font-normal">(you)</span>}
                                        {member.two_factor && (
                                            <Tooltip content="Two-factor enabled">
                                                <ShieldCheck className="text-success size-3.5" aria-label="Two-factor enabled" />
                                            </Tooltip>
                                        )}
                                    </span>
                                    <span className="text-fg-faint truncate text-xs">{member.email}</span>
                                </div>
                            </div>
                        ),
                    },
                    {
                        id: 'role',
                        header: 'Role',
                        sortValue: (member) => (member.role ? rank[member.role] : 0),
                        cell: (member) =>
                            canEdit(member) ? (
                                <Select
                                    size="sm"
                                    className="w-32"
                                    aria-label={`Role of ${member.name}`}
                                    value={member.role ?? undefined}
                                    onValueChange={(value) => changeRole(member, value)}
                                    options={roleOptions}
                                />
                            ) : (
                                <Tag tone={member.role === 'owner' ? 'accent' : 'neutral'} className="capitalize">
                                    {member.role ?? 'none'}
                                </Tag>
                            ),
                    },
                    {
                        id: 'joined',
                        header: 'Joined',
                        hideOnMobile: true,
                        sortValue: (member) => member.joined_at ?? '',
                        cell: (member) => <RelativeTime value={member.joined_at} className="text-fg-muted" />,
                    },
                ]}
                rowActions={(member) => {
                    const self = member.id === auth.user.id;
                    if (member.role === 'owner' || (!self && !canEdit(member))) return [{ label: 'No actions available', disabled: true }];

                    return [
                        {
                            label: self ? 'Leave organization' : 'Remove from organization',
                            icon: self ? <LogOut /> : <UserMinus />,
                            danger: true,
                            onSelect: () => setRemoving(member),
                        },
                    ];
                }}
            />

            {canManage && (
                <Section title="Pending invitations" description="Invitation links are valid for 7 days." bare>
                    <DataTable
                        label="Pending invitations"
                        rows={invitations}
                        rowKey={(invitation) => invitation.id}
                        empty={{
                            icon: <Mail />,
                            title: 'No pending invitations',
                            description: 'Invite teammates by email; they join with the role you pick.',
                            size: 'sm',
                        }}
                        columns={[
                            { id: 'email', header: 'Email', cell: (invitation) => invitation.email, sortValue: (invitation) => invitation.email },
                            { id: 'role', header: 'Role', cell: (invitation) => <Tag className="capitalize">{invitation.role}</Tag> },
                            {
                                id: 'by',
                                header: 'Invited by',
                                hideOnMobile: true,
                                cell: (invitation) => <span className="text-fg-muted">{invitation.invited_by ?? '—'}</span>,
                            },
                            {
                                id: 'expires',
                                header: 'Expires',
                                cell: (invitation) => <RelativeTime value={invitation.expires_at} className="text-fg-muted" />,
                            },
                        ]}
                        rowActions={(invitation) => [
                            { label: 'Revoke invitation', icon: <Trash2 />, danger: true, onSelect: () => revoke(invitation) },
                        ]}
                    />
                </Section>
            )}

            <Dialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                title="Invite a member"
                description="They'll receive an email with a link valid for 7 days."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setInviteOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="invite-member" loading={invite.processing}>
                            Send invitation
                        </Button>
                    </>
                }
            >
                <form id="invite-member" onSubmit={submitInvite} className="grid gap-4">
                    <Field label="Email" error={invite.errors.email}>
                        <Input type="email" value={invite.data.email} onChange={(event) => invite.setData('email', event.target.value)} autoFocus />
                    </Field>
                    <Field label="Role" error={invite.errors.role} hint={roles.find((role) => role.value === invite.data.role)?.description}>
                        <Select
                            value={invite.data.role}
                            onValueChange={(value) => invite.setData('role', value as OrganizationRole)}
                            options={roleOptions}
                        />
                    </Field>
                </form>
            </Dialog>

            <ConfirmDestructive
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={leaving ? 'Leave organization' : 'Remove member'}
                description={leaving ? 'You will lose access to everything this organization owns.' : 'They will lose access immediately.'}
                confirmText={removing?.email ?? ''}
                confirmLabel={leaving ? 'Leave' : 'Remove'}
                onConfirm={remove}
                processing={removeProcessing}
            />
        </SettingsLayout>
    );
}
