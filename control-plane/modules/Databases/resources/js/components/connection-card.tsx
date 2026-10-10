import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Eye, EyeOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import { type Connection, type DatabaseRow, type DatabaseUserRow } from '../types';
import { CopyButton, postJson } from './database-ui';

interface Props {
    connection: Connection;
    databases: DatabaseRow[];
    users: DatabaseUserRow[];
    canReveal: boolean;
}

const MASK = '••••••••';

/**
 * Connection details: reachable hosts plus a DSN / .env snippet for a chosen database + user.
 * Passwords are fetched on demand (audited) and kept only in component state.
 */
export function ConnectionCard({ connection, databases, users, canReveal }: Props) {
    const [hostIndex, setHostIndex] = useState(0);
    const [databaseId, setDatabaseId] = useState<string>(databases[0]?.id ?? '');
    const [userId, setUserId] = useState<string>(users[0]?.id ?? '');
    const [passwords, setPasswords] = useState<Record<string, string>>({});
    const [shown, setShown] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const host = connection.hosts[hostIndex] ?? connection.hosts[0];
    const database = databases.find((d) => d.id === databaseId) ?? databases[0];
    const user = users.find((u) => u.id === userId) ?? users[0];
    const password = user ? passwords[user.id] : undefined;
    const visiblePassword = shown && password ? password : MASK;

    const reveal = async () => {
        if (!user) return;

        if (password) {
            setShown((value) => !value);

            return;
        }

        try {
            const body = await postJson<{ password: string }>(`/databases/users/${user.id}/reveal`);
            setPasswords((current) => ({ ...current, [user.id]: body.password }));
            setShown(true);
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Could not reveal the password.');
        }
    };

    const snippets = useMemo(() => {
        if (!host) return null;

        const db = database?.name ?? 'database';
        const username = user?.username ?? 'user';

        if (connection.kind === 'key_value') {
            return {
                url: (secret: string) => `redis://default:${encodeURIComponent(secret)}@${host.value}:${host.port}`,
                env: (secret: string) =>
                    ['REDIS_CLIENT=phpredis', `REDIS_HOST=${host.value}`, `REDIS_PORT=${host.port}`, `REDIS_PASSWORD=${secret}`].join('\n'),
            };
        }

        const scheme = connection.driver === 'pgsql' ? 'postgresql' : 'mysql';
        const url = (secret: string) =>
            `${scheme}://${encodeURIComponent(username)}:${encodeURIComponent(secret)}@${host.value}:${host.port}/${encodeURIComponent(db)}`;
        const env = (secret: string) =>
            [
                `DB_CONNECTION=${connection.driver}`,
                `DB_HOST=${host.value}`,
                `DB_PORT=${host.port}`,
                `DB_DATABASE=${db}`,
                `DB_USERNAME=${username}`,
                `DB_PASSWORD=${secret}`,
            ].join('\n');

        return { url, env };
    }, [host, database, user, connection]);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Connection</CardTitle>
                <CardDescription>Where applications reach this database, most private first.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <ul className="divide-y rounded-md border text-sm">
                    {connection.hosts.map((candidate, index) => (
                        <li key={`${candidate.label}-${candidate.value}`} className="flex items-center justify-between gap-2 px-3 py-2">
                            <button type="button" className="text-left" onClick={() => setHostIndex(index)}>
                                <span className={index === hostIndex ? 'font-medium' : undefined}>{candidate.label}</span>
                                <span className="text-muted-foreground block text-xs">{candidate.hint}</span>
                            </button>
                            <span className="flex items-center gap-1 font-mono text-xs">
                                {candidate.value}:{candidate.port}
                                <CopyButton value={`${candidate.value}:${candidate.port}`} label="Copy address" />
                            </span>
                        </li>
                    ))}
                </ul>

                {snippets && databases.length > 0 && users.length > 0 && (
                    <div className="space-y-3">
                        <div className={connection.kind === 'key_value' ? 'hidden' : 'grid gap-3 sm:grid-cols-2'}>
                            <div className="grid gap-1.5">
                                <Label>Database</Label>
                                <Select value={database?.id} onValueChange={setDatabaseId}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {databases.map((d) => (
                                            <SelectItem key={d.id} value={d.id}>
                                                {d.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>User</Label>
                                <Select
                                    value={user?.id}
                                    onValueChange={(value) => {
                                        setUserId(value);
                                        setShown(false);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {users.map((u) => (
                                            <SelectItem key={u.id} value={u.id}>
                                                {u.username}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="flex items-center justify-between gap-2">
                            <Label>Connection URL</Label>
                            {canReveal && (
                                <Button type="button" variant="ghost" size="sm" onClick={reveal}>
                                    {shown ? <EyeOff /> : <Eye />} {shown ? 'Hide' : 'Reveal'} password
                                </Button>
                            )}
                        </div>
                        <div className="bg-muted flex items-start gap-2 rounded-md p-2 font-mono text-xs break-all">
                            <span className="flex-1">{snippets.url(visiblePassword)}</span>
                            {shown && password && <CopyButton value={snippets.url(password)} label="Copy URL" />}
                        </div>
                        <Label>.env</Label>
                        <div className="bg-muted flex items-start gap-2 rounded-md p-2 font-mono text-xs whitespace-pre">
                            <span className="flex-1 overflow-x-auto">{snippets.env(visiblePassword)}</span>
                            {shown && password && <CopyButton value={snippets.env(password)} label="Copy .env" />}
                        </div>
                        {error && <p className="text-sm text-red-600">{error}</p>}
                    </div>
                )}

                {connection.access.length > 0 && (
                    <div className="space-y-2">
                        <Label>Sites of this environment</Label>
                        <ul className="divide-y rounded-md border text-sm">
                            {connection.access.map((item) => (
                                <li key={item.name} className="px-3 py-2">
                                    <span className="font-medium">{item.name}</span>
                                    {item.host ? (
                                        <span className="text-muted-foreground ml-2 font-mono text-xs">
                                            {item.host}:{item.port}
                                        </span>
                                    ) : (
                                        <span className="ml-2 text-xs text-amber-600">can&apos;t connect</span>
                                    )}
                                    {item.reason && <p className="text-muted-foreground text-xs">{item.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
