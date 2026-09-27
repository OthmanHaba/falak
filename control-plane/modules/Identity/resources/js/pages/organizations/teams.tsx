import { Avatar } from '@/components/kiln/avatar';
import { Button } from '@/components/kiln/button';
import { Checkbox } from '@/components/kiln/checkbox';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { Dialog } from '@/components/kiln/dialog';
import { EmptyState } from '@/components/kiln/empty-state';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { Menu } from '@/components/kiln/menu';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Users, UsersRound } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

interface Person {
    id: string;
    name: string;
    email: string;
}

interface Team {
    id: string;
    name: string;
    description: string | null;
    members: Person[];
}

interface TeamsProps {
    teams: Team[];
    members: Person[];
    canManage: boolean;
}

function TeamFormDialog({ team, onOpenChange }: { team: Team | null; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ name: team?.name ?? '', description: team?.description ?? '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (team) form.patch(route('organization.teams.update', team.id), options);
        else form.post(route('organization.teams.store'), options);
    };

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            title={team ? 'Edit team' : 'New team'}
            description='Teams group members, e.g. "Backend" or "On-call".'
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="team-form" loading={form.processing}>
                        {team ? 'Save' : 'Create team'}
                    </Button>
                </>
            }
        >
            <form id="team-form" onSubmit={submit} className="grid gap-4">
                <Field label="Name" error={form.errors.name}>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoFocus />
                </Field>
                <Field label="Description" error={form.errors.description}>
                    <Input value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                </Field>
            </form>
        </Dialog>
    );
}

function TeamMembersDialog({ team, members, onOpenChange }: { team: Team; members: Person[]; onOpenChange: (open: boolean) => void }) {
    const form = useForm<{ user_ids: string[] }>({ user_ids: team.members.map((member) => member.id) });

    const toggle = (id: string, checked: boolean) =>
        form.setData('user_ids', checked ? [...form.data.user_ids, id] : form.data.user_ids.filter((userId) => userId !== id));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('organization.teams.members', team.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            title={`Members of ${team.name}`}
            description="Only organization members can join a team."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="team-members" loading={form.processing}>
                        Save members
                    </Button>
                </>
            }
        >
            <form id="team-members" onSubmit={submit} className="grid max-h-80 gap-2.5 overflow-y-auto">
                {members.map((member) => (
                    <Field
                        key={member.id}
                        inline
                        label={
                            <span>
                                {member.name} <span className="text-fg-faint font-normal">{member.email}</span>
                            </span>
                        }
                    >
                        <Checkbox
                            checked={form.data.user_ids.includes(member.id)}
                            onCheckedChange={(checked) => toggle(member.id, checked === true)}
                        />
                    </Field>
                ))}
                {form.errors.user_ids && <p className="text-danger text-xs">{form.errors.user_ids}</p>}
            </form>
        </Dialog>
    );
}

export default function Teams({ teams, members, canManage }: TeamsProps) {
    const [editing, setEditing] = useState<Team | null | undefined>(undefined);
    const [managing, setManaging] = useState<Team | null>(null);
    const [deleting, setDeleting] = useState<Team | null>(null);

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('organization.teams.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    return (
        <SettingsLayout
            title="Teams"
            description="Group members of this organization."
            wide
            actions={
                canManage && (
                    <Button variant="primary" icon={<Plus />} onClick={() => setEditing(null)}>
                        New team
                    </Button>
                )
            }
        >
            {teams.length === 0 ? (
                <EmptyState
                    icon={<UsersRound />}
                    title="No teams yet"
                    description="Teams group members (e.g. Backend, On-call) so you can route alerts and grant access together."
                    action={
                        canManage && (
                            <Button variant="primary" icon={<Plus />} onClick={() => setEditing(null)}>
                                Create a team
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="grid gap-3 md:grid-cols-2">
                    {teams.map((team) => (
                        <article key={team.id} className="border-border bg-surface-1 grid content-start gap-3 rounded-lg border p-4">
                            <header className="flex items-start justify-between gap-2">
                                <div className="grid min-w-0 gap-0.5">
                                    <h3 className="text-fg truncate text-base font-medium">{team.name}</h3>
                                    {team.description && <p className="text-fg-muted text-sm">{team.description}</p>}
                                </div>
                                {canManage && (
                                    <Menu
                                        label={`Actions for ${team.name}`}
                                        actions={[
                                            { label: 'Manage members', icon: <Users />, onSelect: () => setManaging(team) },
                                            { label: 'Edit team', icon: <Pencil />, onSelect: () => setEditing(team) },
                                            { type: 'separator' },
                                            { label: 'Delete team', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(team) },
                                        ]}
                                    />
                                )}
                            </header>
                            {team.members.length === 0 ? (
                                <p className="text-fg-faint text-sm">No members.</p>
                            ) : (
                                <ul className="grid gap-1.5">
                                    {team.members.map((member) => (
                                        <li key={member.id} className="flex min-w-0 items-center gap-2 text-sm">
                                            <Avatar name={member.name} size="xs" />
                                            <span className="text-fg truncate">{member.name}</span>
                                            <span className="text-fg-faint truncate text-xs">{member.email}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </article>
                    ))}
                </div>
            )}

            {editing !== undefined && (
                <TeamFormDialog key={editing?.id ?? 'new'} team={editing} onOpenChange={(open) => !open && setEditing(undefined)} />
            )}
            {managing && <TeamMembersDialog team={managing} members={members} onOpenChange={(open) => !open && setManaging(null)} />}
            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete team"
                description="Members stay in the organization; only the grouping is removed."
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete team"
                onConfirm={destroy}
            />
        </SettingsLayout>
    );
}
