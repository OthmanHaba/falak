import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Film, SquareTerminal, Users } from 'lucide-react';
import { FormEventHandler } from 'react';
import { SessionStatusBadge } from '../components/session-status';
import { formatBytes, formatDuration, REASON_LABELS } from '../lib';
import { type TerminalSessionData } from '../types';

interface ServerOption {
    id: string;
    name: string;
    ipv4: string | null;
    unix_user: string;
}

interface Props {
    servers: ServerOption[];
    sessions: TerminalSessionData[];
    recordings: TerminalSessionData[];
    defaultUser: string;
    idleTimeout: number;
    can: { open: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Terminal', href: '/terminal' }];

export default function Index({ servers, sessions, recordings, defaultUser, idleTimeout, can }: Props) {
    const form = useForm({ server: servers[0]?.id ?? '', user: defaultUser });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!form.data.server) return;

        form.transform((data) => ({ user: data.user }));
        form.post(route('terminal.sessions.store', form.data.server));
    };

    const selected = servers.find((server) => server.id === form.data.server);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Terminal" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Terminal"
                    description="Browser shells on your servers through the Kiln agent — no inbound SSH required. Sessions are recorded."
                />

                {can.open && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Open a session</CardTitle>
                            <CardDescription>Sessions close after {formatDuration(idleTimeout)} without activity.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {servers.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No active servers yet.</p>
                            ) : (
                                <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                                    <div className="grid min-w-56 gap-2">
                                        <Label htmlFor="terminal-server">Server</Label>
                                        <Select value={form.data.server} onValueChange={(value) => form.setData('server', value)}>
                                            <SelectTrigger id="terminal-server">
                                                <SelectValue placeholder="Choose a server" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {servers.map((server) => (
                                                    <SelectItem key={server.id} value={server.id}>
                                                        {server.name}
                                                        {server.ipv4 ? ` (${server.ipv4})` : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={form.errors.server} />
                                    </div>
                                    <div className="grid w-44 gap-2">
                                        <Label htmlFor="terminal-user">Run as</Label>
                                        <Input
                                            id="terminal-user"
                                            value={form.data.user}
                                            onChange={(e) => form.setData('user', e.target.value)}
                                            list="terminal-users"
                                            className="font-mono"
                                        />
                                        <datalist id="terminal-users">
                                            <option value="root" />
                                            {selected && <option value={selected.unix_user} />}
                                        </datalist>
                                        <InputError message={form.errors.user} />
                                    </div>
                                    <Button disabled={form.processing || !form.data.server}>
                                        <SquareTerminal /> Open terminal
                                    </Button>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Live sessions</h2>
                    {sessions.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No live sessions you can join.</p>
                    ) : (
                        <Card className="py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Server</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Started</TableHead>
                                        <TableHead className="w-24" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sessions.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="font-medium">{session.server_name}</TableCell>
                                            <TableCell className="font-mono text-xs">{session.unix_user}</TableCell>
                                            <TableCell>
                                                <span className="inline-flex items-center gap-1.5">
                                                    {session.owner.name}
                                                    {session.shared && <Users className="text-muted-foreground size-3.5" aria-label="Shared" />}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <SessionStatusBadge status={session.status} />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {formatDistanceToNow(new Date(session.created_at), { addSuffix: true })}
                                            </TableCell>
                                            <TableCell>
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={route('terminal.sessions.show', session.id)}>Attach</Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Recordings</h2>
                    {recordings.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No recordings yet.</p>
                    ) : (
                        <Card className="py-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Server</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Ended</TableHead>
                                        <TableHead>Duration</TableHead>
                                        <TableHead>Size</TableHead>
                                        <TableHead className="w-24" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recordings.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="font-medium">{session.server_name}</TableCell>
                                            <TableCell className="font-mono text-xs">{session.unix_user}</TableCell>
                                            <TableCell>{session.owner.name}</TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {session.close_reason ? (REASON_LABELS[session.close_reason] ?? session.close_reason) : '—'}
                                                {session.closed_at && ` · ${formatDistanceToNow(new Date(session.closed_at), { addSuffix: true })}`}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {session.duration_s !== null ? formatDuration(session.duration_s) : '—'}
                                            </TableCell>
                                            <TableCell className="tabular-nums">{formatBytes(session.recording_bytes)}</TableCell>
                                            <TableCell>
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={route('terminal.sessions.recording', session.id)}>
                                                        <Film /> Replay
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
