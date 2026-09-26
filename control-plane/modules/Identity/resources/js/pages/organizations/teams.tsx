import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import OrganizationLayout from '@/layouts/organization/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Teams', href: '/organization/teams' }];

function TeamFormDialog({ team, open, onOpenChange }: { team: Team | null; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ name: team?.name ?? '', description: team?.description ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (team) {
            form.patch(route('organization.teams.update', team.id), options);
        } else {
            form.post(route('organization.teams.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{team ? 'Edit team' : 'New team'}</DialogTitle>
                        <DialogDescription>Teams group members, e.g. "Backend" or "On-call".</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="team_name">Name</Label>
                        <Input id="team_name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="team_description">Description</Label>
                        <Input id="team_description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        <InputError message={form.errors.description} />
                    </div>
                    <DialogFooter>
                        <Button disabled={form.processing}>{team ? 'Save' : 'Create team'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function TeamMembersDialog({ team, members, onOpenChange }: { team: Team; members: Person[]; onOpenChange: (open: boolean) => void }) {
    const form = useForm<{ user_ids: string[] }>({ user_ids: team.members.map((member) => member.id) });

    const toggle = (id: string, checked: boolean) =>
        form.setData('user_ids', checked ? [...form.data.user_ids, id] : form.data.user_ids.filter((userId) => userId !== id));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('organization.teams.members', team.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Members of {team.name}</DialogTitle>
                        <DialogDescription>Only organization members can join a team.</DialogDescription>
                    </DialogHeader>
                    <div className="max-h-80 space-y-2 overflow-y-auto">
                        {members.map((member) => (
                            <label key={member.id} className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.user_ids.includes(member.id)}
                                    onCheckedChange={(checked) => toggle(member.id, checked === true)}
                                />
                                <span>{member.name}</span>
                                <span className="text-muted-foreground text-xs">{member.email}</span>
                            </label>
                        ))}
                    </div>
                    <InputError message={form.errors.user_ids} />
                    <DialogFooter>
                        <Button disabled={form.processing}>Save members</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Teams({ teams, members, canManage }: TeamsProps) {
    const [editing, setEditing] = useState<Team | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [managing, setManaging] = useState<Team | null>(null);

    const openForm = (team: Team | null) => {
        setEditing(team);
        setFormOpen(true);
    };

    const destroy = (team: Team) => {
        if (window.confirm(`Delete the team "${team.name}"?`)) {
            router.delete(route('organization.teams.destroy', team.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Teams" />
            <OrganizationLayout wide>
                <div className="space-y-4">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall title="Teams" description="Group members of this organization." />
                        {canManage && (
                            <Button size="sm" onClick={() => openForm(null)}>
                                <Plus className="size-4" /> New team
                            </Button>
                        )}
                    </div>

                    {teams.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No teams yet.</p>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {teams.map((team) => (
                                <Card key={team.id}>
                                    <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
                                        <div className="space-y-1">
                                            <CardTitle className="text-base">{team.name}</CardTitle>
                                            {team.description && <CardDescription>{team.description}</CardDescription>}
                                        </div>
                                        {canManage && (
                                            <div className="flex gap-1">
                                                <Button size="icon" variant="ghost" aria-label="Manage members" onClick={() => setManaging(team)}>
                                                    <Users className="size-4" />
                                                </Button>
                                                <Button size="icon" variant="ghost" aria-label="Edit team" onClick={() => openForm(team)}>
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button size="icon" variant="ghost" aria-label="Delete team" onClick={() => destroy(team)}>
                                                    <Trash2 className="text-destructive size-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {team.members.length === 0 ? (
                                            <p className="text-muted-foreground text-sm">No members.</p>
                                        ) : (
                                            <ul className="space-y-1 text-sm">
                                                {team.members.map((member) => (
                                                    <li key={member.id}>
                                                        {member.name} <span className="text-muted-foreground text-xs">{member.email}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>

                {formOpen && <TeamFormDialog key={editing?.id ?? 'new'} team={editing} open={formOpen} onOpenChange={setFormOpen} />}
                {managing && <TeamMembersDialog team={managing} members={members} onOpenChange={(open) => !open && setManaging(null)} />}
            </OrganizationLayout>
        </AppLayout>
    );
}
