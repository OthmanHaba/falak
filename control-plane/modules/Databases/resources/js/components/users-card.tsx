import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type DatabaseRow, type DatabaseServer, type DatabaseUserRow } from '../types';
import { StatusBadge } from './database-ui';

interface Props {
    server: DatabaseServer;
    users: DatabaseUserRow[];
    databases: DatabaseRow[];
    privileges: string[];
    canManage: boolean;
}

interface GrantInput {
    database_id: string;
    privileges: string[];
}

type AccessLevel = 'all' | 'write' | 'read';

const LEVELS: Record<AccessLevel, { label: string; privileges: string[] }> = {
    all: { label: 'All privileges', privileges: ['ALL PRIVILEGES'] },
    write: { label: 'Read / write', privileges: ['SELECT', 'INSERT', 'UPDATE', 'DELETE'] },
    read: { label: 'Read only', privileges: ['SELECT'] },
};

function levelOf(privileges: string[]): AccessLevel {
    if (privileges.includes('ALL PRIVILEGES')) return 'all';

    return privileges.length === 1 && privileges[0] === 'SELECT' ? 'read' : 'write';
}

function GrantsEditor({
    databases,
    grants,
    onChange,
    levels,
}: {
    databases: DatabaseRow[];
    grants: GrantInput[];
    onChange: (grants: GrantInput[]) => void;
    levels: AccessLevel[];
}) {
    const toggle = (databaseId: string, checked: boolean) => {
        onChange(
            checked ? [...grants, { database_id: databaseId, privileges: ['ALL PRIVILEGES'] }] : grants.filter((g) => g.database_id !== databaseId),
        );
    };

    const setLevel = (databaseId: string, level: AccessLevel) => {
        onChange(grants.map((g) => (g.database_id === databaseId ? { ...g, privileges: LEVELS[level].privileges } : g)));
    };

    if (databases.length === 0) {
        return <p className="text-muted-foreground text-sm">Create a database first to grant access.</p>;
    }

    return (
        <ul className="max-h-64 divide-y overflow-y-auto rounded-md border">
            {databases.map((database) => {
                const grant = grants.find((g) => g.database_id === database.id);

                return (
                    <li key={database.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                        <label className="flex items-center gap-2">
                            <Checkbox checked={grant !== undefined} onCheckedChange={(value) => toggle(database.id, value === true)} />
                            <span className="font-mono">{database.name}</span>
                        </label>
                        {grant && levels.length > 1 && (
                            <Select value={levelOf(grant.privileges)} onValueChange={(value) => setLevel(database.id, value as AccessLevel)}>
                                <SelectTrigger className="h-8 w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {levels.map((level) => (
                                        <SelectItem key={level} value={level}>
                                            {LEVELS[level].label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

export function UsersCard({ server, users, databases, privileges, canManage }: Props) {
    const mysql = server.engine !== 'postgresql';
    const levels = (Object.keys(LEVELS) as AccessLevel[]).filter((level) => LEVELS[level].privileges.every((p) => privileges.includes(p)));
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<DatabaseUserRow | null>(null);
    const [deleting, setDeleting] = useState<DatabaseUserRow | null>(null);
    const [rotating, setRotating] = useState<DatabaseUserRow | null>(null);

    const create = useForm<{ username: string; password: string; host: string; grants: GrantInput[] }>({
        username: '',
        password: '',
        host: '%',
        grants: [],
    });
    const edit = useForm<{ host: string; grants: GrantInput[] }>({ host: '%', grants: [] });
    const rotate = useForm({ password: '' });

    const openEdit = (user: DatabaseUserRow) => {
        edit.setData({ host: user.host, grants: user.grants.map((g) => ({ database_id: g.database_id, privileges: g.privileges })) });
        edit.clearErrors();
        setEditing(user);
    };

    const submitCreate: FormEventHandler = (event) => {
        event.preventDefault();
        create.transform((data) => ({ ...data, password: data.password || null, host: mysql ? data.host : null }));
        create.post(`/databases/servers/${server.id}/users`, {
            preserveScroll: true,
            onSuccess: () => {
                create.reset();
                setCreating(false);
            },
        });
    };

    const submitEdit: FormEventHandler = (event) => {
        event.preventDefault();
        if (!editing) return;
        edit.transform((data) => ({ ...data, host: mysql ? data.host : null }));
        edit.put(`/databases/users/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
    };

    const submitRotate: FormEventHandler = (event) => {
        event.preventDefault();
        if (!rotating) return;
        rotate.transform((data) => ({ password: data.password || null }));
        rotate.post(`/databases/users/${rotating.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                rotate.reset();
                setRotating(null);
            },
        });
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(`/databases/users/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="flex flex-row items-center justify-between gap-2 border-b py-4">
                <CardTitle className="text-base">Users</CardTitle>
                {canManage && (
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus /> New user
                    </Button>
                )}
            </CardHeader>
            <CardContent className="px-0">
                {users.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-sm">No users yet.</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-6">User</TableHead>
                                <TableHead>Access</TableHead>
                                <TableHead>Status</TableHead>
                                {canManage && <TableHead className="w-32" />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.map((user) => (
                                <TableRow key={user.id}>
                                    <TableCell className="pl-6 font-mono">
                                        {user.username}
                                        {mysql && <span className="text-muted-foreground">@{user.host}</span>}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {user.grants.length === 0 ? (
                                            <span className="text-muted-foreground">No databases</span>
                                        ) : (
                                            user.grants.map((grant) => (
                                                <div key={grant.database_id}>
                                                    <span className="font-mono">{grant.database}</span>{' '}
                                                    <span className="text-muted-foreground">{grant.privileges.join(', ')}</span>
                                                </div>
                                            ))
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={user.status} title={user.status_message} />
                                        {user.status_message && <p className="mt-1 max-w-xs text-xs text-red-600">{user.status_message}</p>}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right whitespace-nowrap">
                                            <Button variant="ghost" size="icon" aria-label={`Edit ${user.username}`} onClick={() => openEdit(user)}>
                                                <Pencil />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Rotate password of ${user.username}`}
                                                onClick={() => setRotating(user)}
                                            >
                                                <KeyRound />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                disabled={user.status === 'deleting'}
                                                aria-label={`Delete ${user.username}`}
                                                onClick={() => setDeleting(user)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>New database user</DialogTitle>
                            <DialogDescription>Passwords are stored encrypted; leave it empty to generate a strong one.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="user-name">Username</Label>
                                <Input id="user-name" value={create.data.username} onChange={(e) => create.setData('username', e.target.value)} />
                                <InputError message={create.errors.username} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="user-password">Password</Label>
                                <Input
                                    id="user-password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={create.data.password}
                                    onChange={(e) => create.setData('password', e.target.value)}
                                    placeholder="Generated when empty"
                                />
                                <InputError message={create.errors.password} />
                            </div>
                        </div>
                        {mysql && (
                            <div className="grid gap-2">
                                <Label htmlFor="user-host">Allowed host</Label>
                                <Input
                                    id="user-host"
                                    value={create.data.host}
                                    onChange={(e) => create.setData('host', e.target.value)}
                                    placeholder="%"
                                />
                                <p className="text-muted-foreground text-xs">
                                    % = any host (the firewall still decides who can reach the port), e.g. 10.90.0.%.
                                </p>
                                <InputError message={create.errors.host} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label>Databases</Label>
                            <GrantsEditor
                                databases={databases}
                                grants={create.data.grants}
                                onChange={(grants) => create.setData('grants', grants)}
                                levels={levels}
                            />
                            <InputError message={create.errors.grants} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button disabled={create.processing}>Create user</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <form onSubmit={submitEdit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Edit {editing?.username}</DialogTitle>
                            <DialogDescription>The full user state (password, host and grants) is re-applied on the server.</DialogDescription>
                        </DialogHeader>
                        {mysql && (
                            <div className="grid gap-2">
                                <Label htmlFor="edit-host">Allowed host</Label>
                                <Input id="edit-host" value={edit.data.host} onChange={(e) => edit.setData('host', e.target.value)} />
                                <InputError message={edit.errors.host} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label>Databases</Label>
                            <GrantsEditor
                                databases={databases}
                                grants={edit.data.grants}
                                onChange={(grants) => edit.setData('grants', grants)}
                                levels={levels}
                            />
                            <InputError message={edit.errors.grants} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button disabled={edit.processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={rotating !== null} onOpenChange={(open) => !open && setRotating(null)}>
                <DialogContent>
                    <form onSubmit={submitRotate} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Rotate password of {rotating?.username}</DialogTitle>
                            <DialogDescription>Applications using the old password lose access once the agent applies the change.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="rotate-password">New password</Label>
                            <Input
                                id="rotate-password"
                                type="password"
                                autoComplete="new-password"
                                value={rotate.data.password}
                                onChange={(e) => rotate.setData('password', e.target.value)}
                                placeholder="Generated when empty"
                            />
                            <InputError message={rotate.errors.password} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setRotating(null)}>
                                Cancel
                            </Button>
                            <Button disabled={rotate.processing}>Rotate</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.username}?</DialogTitle>
                        <DialogDescription>The account is dropped from {server.server_name}; applications using it lose access.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            Delete user
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
