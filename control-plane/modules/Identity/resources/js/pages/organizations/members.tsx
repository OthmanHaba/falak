import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import OrganizationLayout from '@/layouts/organization/layout';
import { type BreadcrumbItem, type OrganizationRole, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { ShieldCheck, UserPlus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Members', href: '/organization/members' }];

export default function Members({ members, invitations, roles, myRole, canManage }: MembersProps) {
    const { auth } = usePage<SharedData>().props;
    const [inviteOpen, setInviteOpen] = useState(false);
    const invite = useForm<{ email: string; role: OrganizationRole }>({ email: '', role: 'developer' });
    const myRank = myRole ? rank[myRole] : 0;

    const canEdit = (member: Member) =>
        canManage && member.id !== auth.user.id && member.role !== null && member.role !== 'owner' && myRank > rank[member.role];

    const assignable = roles.filter((role) => role.assignable && rank[role.value] <= myRank);

    const submitInvite: FormEventHandler = (e) => {
        e.preventDefault();
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

    const remove = (member: Member) => {
        const self = member.id === auth.user.id;
        const message = self
            ? 'Leave this organization? You will lose access to everything it owns.'
            : `Remove ${member.name} from the organization?`;

        if (window.confirm(message)) {
            router.delete(route('organization.members.destroy', member.id), { preserveScroll: true });
        }
    };

    const revoke = (invitation: Invitation) => router.delete(route('organization.invitations.destroy', invitation.id), { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Members" />
            <OrganizationLayout wide>
                <div className="space-y-4">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall title="Members" description="Everyone with access to this organization and their role." />
                        {canManage && (
                            <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                                <DialogTrigger asChild>
                                    <Button size="sm">
                                        <UserPlus className="size-4" /> Invite
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <form onSubmit={submitInvite} className="space-y-4">
                                        <DialogHeader>
                                            <DialogTitle>Invite a member</DialogTitle>
                                            <DialogDescription>They'll receive an email with a link valid for 7 days.</DialogDescription>
                                        </DialogHeader>
                                        <div className="grid gap-2">
                                            <Label htmlFor="invite_email">Email</Label>
                                            <Input
                                                id="invite_email"
                                                type="email"
                                                value={invite.data.email}
                                                onChange={(e) => invite.setData('email', e.target.value)}
                                            />
                                            <InputError message={invite.errors.email} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>Role</Label>
                                            <Select
                                                value={invite.data.role}
                                                onValueChange={(value) => invite.setData('role', value as OrganizationRole)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {assignable.map((role) => (
                                                        <SelectItem key={role.value} value={role.value}>
                                                            {role.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-muted-foreground text-xs">
                                                {roles.find((role) => role.value === invite.data.role)?.description}
                                            </p>
                                            <InputError message={invite.errors.role} />
                                        </div>
                                        <DialogFooter>
                                            <Button disabled={invite.processing}>Send invitation</Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Member</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Joined</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <div>
                                                <div className="font-medium">
                                                    {member.name}
                                                    {member.id === auth.user.id && <span className="text-muted-foreground"> (you)</span>}
                                                </div>
                                                <div className="text-muted-foreground text-xs">{member.email}</div>
                                            </div>
                                            {member.two_factor && <ShieldCheck className="size-4 text-green-600" aria-label="Two-factor enabled" />}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {canEdit(member) ? (
                                            <Select value={member.role ?? undefined} onValueChange={(value) => changeRole(member, value)}>
                                                <SelectTrigger className="h-8 w-36">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {assignable.map((role) => (
                                                        <SelectItem key={role.value} value={role.value}>
                                                            {role.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        ) : (
                                            <Badge variant={member.role === 'owner' ? 'default' : 'secondary'} className="capitalize">
                                                {member.role ?? 'none'}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {member.joined_at ? formatDistanceToNow(new Date(member.joined_at), { addSuffix: true }) : '—'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {member.role !== 'owner' && (member.id === auth.user.id || canEdit(member)) && (
                                            <Button size="sm" variant="ghost" className="text-destructive" onClick={() => remove(member)}>
                                                {member.id === auth.user.id ? 'Leave' : 'Remove'}
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {canManage && invitations.length > 0 && (
                    <div className="space-y-4">
                        <HeadingSmall title="Pending invitations" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Invited by</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invitations.map((invitation) => (
                                    <TableRow key={invitation.id}>
                                        <TableCell>{invitation.email}</TableCell>
                                        <TableCell className="capitalize">{invitation.role}</TableCell>
                                        <TableCell className="text-muted-foreground">{invitation.invited_by ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDistanceToNow(new Date(invitation.expires_at), { addSuffix: true })}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button size="sm" variant="ghost" className="text-destructive" onClick={() => revoke(invitation)}>
                                                Revoke
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </OrganizationLayout>
        </AppLayout>
    );
}
