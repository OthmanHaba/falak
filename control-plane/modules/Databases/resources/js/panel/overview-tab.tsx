import { Button, CodeBlock, CopyButton, KeyValue, RelativeTime, Section, Select, SkeletonRows, StatusBadge, toast } from '@/components/kiln';
import { copyText } from '@/components/kiln/copy-button';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { AlertTriangle, Eye, EyeOff, Info } from 'lucide-react';
import { useState } from 'react';
import { resourceStatus, useDatabasePanel } from './api';

const MASK = '••••••••••';

/** Handle of a service name as `${{ … }}` references use it (matches Projects' Service::handle). */
function handle(name: string): string {
    return name
        .trim()
        .replace(/[_.]/g, '-')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

/** §5.4 Overview: engine, server, connection strings (copy + audited reveal), private address, reference keys. */
export function DatabaseOverviewTab({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDatabasePanel(ctx);
    const [rotating, setRotating] = useState(false);
    const [hostIndex, setHostIndex] = useState(0);
    const [userId, setUserId] = useState<string | null>(null);
    const [passwords, setPasswords] = useState<Record<string, string>>({});
    const [shown, setShown] = useState(false);
    const [revealing, setRevealing] = useState(false);

    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const { database, server, connection, users } = data;
    const keyValue = connection.kind === 'key_value';
    const host = connection.hosts[hostIndex] ?? connection.hosts[0];
    const user = users.find((item) => item.id === userId) ?? users[0];
    const password = user ? passwords[user.id] : undefined;
    const scheme = connection.driver === 'pgsql' ? 'postgresql' : 'mysql';
    const build = (secret: string) =>
        !host || !user
            ? ''
            : keyValue
              ? `redis://default:${secret}@${host.value}:${connection.port}`
              : `${scheme}://${encodeURIComponent(user.username)}:${secret}@${host.value}:${connection.port}/${encodeURIComponent(database.name)}`;
    const env = (secret: string) =>
        (keyValue
            ? [`REDIS_CLIENT=phpredis`, `REDIS_HOST=${host?.value ?? ''}`, `REDIS_PORT=${connection.port}`, `REDIS_PASSWORD=${secret}`]
            : [
                  `DB_CONNECTION=${connection.driver}`,
                  `DB_HOST=${host?.value ?? ''}`,
                  `DB_PORT=${connection.port}`,
                  `DB_DATABASE=${database.name}`,
                  `DB_USERNAME=${user?.username ?? ''}`,
                  `DB_PASSWORD=${secret}`,
              ]
        ).join('\n');
    // On the server: the password goes through the environment, never on the command line.
    const cli = (secret: string) => `REDISCLI_AUTH='${secret}' ${server.engine === 'valkey' ? 'valkey-cli' : 'redis-cli'} -p ${connection.port}`;
    const keys = keyValue
        ? ['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_CLIENT']
        : ['DATABASE_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    const noun = keyValue ? 'instance' : 'database';

    const reveal = async (): Promise<string | null> => {
        if (!user) return null;
        if (password) return password;
        setRevealing(true);
        try {
            const body = await requestJson<{ password: string }>(`/databases/users/${user.id}/reveal`, 'POST', {});
            setPasswords((current) => ({ ...current, [user.id]: body.password }));

            return body.password;
        } catch (e) {
            toast.error('Could not reveal the password', errorMessage(e));

            return null;
        } finally {
            setRevealing(false);
        }
    };

    const copyUrl = async () => {
        const secret = data.can.reveal ? await reveal() : null;
        if (await copyText(build(secret ? encodeURIComponent(secret) : MASK)))
            toast.success(secret ? 'Connection URL copied' : 'Copied (without the password)');
    };

    const visible = shown && password ? password : MASK;
    const name = handle(ctx.service.name);

    return (
        <div className="grid gap-8">
            {(database.status !== 'active' || database.status_message) && (
                <div className="border-border bg-surface-2 flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-sm">
                    <Info className="text-info mt-0.5 size-4 shrink-0" aria-hidden />
                    <span className="text-fg-muted">
                        {database.status === 'pending' && `Creating ${database.name} on ${server.server_name}…`}
                        {database.status === 'active' && database.status_message}
                        {database.status === 'failed' && (database.status_message ?? `Creating the ${noun} failed.`)}
                        {database.status === 'deleting' && (keyValue ? 'Removing the instance…' : 'Dropping the database…')}
                    </span>
                </div>
            )}

            <KeyValue
                columns={3}
                items={[
                    { label: 'Engine', value: `${server.engine_label}${server.version ? ` ${server.version}` : ''}` },
                    { label: 'Server', value: server.server_name, mono: true },
                    { label: 'Status', value: <StatusBadge status={resourceStatus(database.status)} /> },
                    { label: keyValue ? 'Instance' : 'Database', value: database.name, mono: true, copy: database.name },
                    { label: 'Port', value: String(connection.port), mono: true },
                    { label: 'Created', value: <RelativeTime value={database.created_at} /> },
                ]}
            />

            <Section
                title="Connect"
                description={host?.hint ?? 'No reachable address yet.'}
                aside={
                    !keyValue &&
                    users.length > 1 && (
                        <Select
                            size="sm"
                            aria-label="User"
                            value={user?.id}
                            onValueChange={(value) => {
                                setUserId(value);
                                setShown(false);
                            }}
                            options={users.map((item) => ({ value: item.id, label: item.username }))}
                        />
                    )
                }
            >
                {connection.hosts.length > 1 && (
                    <div role="tablist" aria-label="Network" className="bg-surface-2 flex w-fit flex-wrap gap-0.5 rounded-md p-0.5">
                        {connection.hosts.map((item, index) => (
                            <button
                                key={item.label}
                                type="button"
                                role="tab"
                                aria-selected={index === hostIndex}
                                onClick={() => setHostIndex(index)}
                                className={cn(
                                    'rounded-sm px-2.5 py-1 text-xs transition-colors',
                                    index === hostIndex ? 'bg-surface-1 text-fg shadow-panel' : 'text-fg-muted hover:text-fg',
                                )}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                )}
                {user ? (
                    <>
                        <div className="border-border bg-canvas flex min-w-0 items-center gap-1 rounded-md border py-1 pr-1 pl-3">
                            <code className="text-fg min-w-0 flex-1 truncate font-mono text-xs" data-testid="connection-url">
                                {build(shown && password ? encodeURIComponent(password) : MASK)}
                            </code>
                            {data.can.reveal && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    loading={revealing}
                                    icon={shown ? <EyeOff /> : <Eye />}
                                    onClick={async () => {
                                        if (shown) setShown(false);
                                        else if (await reveal()) setShown(true);
                                    }}
                                >
                                    {shown ? 'Hide' : 'Reveal'}
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" onClick={() => void copyUrl()}>
                                Copy
                            </Button>
                        </div>
                        <CodeBlock title=".env" code={env(visible)} copyable={shown && Boolean(password)} />
                        {keyValue && <CodeBlock title={`On ${server.server_name}`} code={cli(visible)} copyable={shown && Boolean(password)} />}
                        <p className="text-fg-faint text-xs">Revealing a password is recorded in the audit log.</p>
                        {keyValue && data.can.manage && (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <span className="text-fg-muted text-xs">
                                    A new password applies to the running instance right away (no restart). Sites get it through their references on
                                    their next deploy; until then they can&apos;t connect.
                                </span>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    loading={rotating}
                                    onClick={async () => {
                                        setRotating(true);
                                        try {
                                            await requestJson(`/databases/users/${user.id}/password`, 'POST', {});
                                            setPasswords({});
                                            setShown(false);
                                            toast.success('Password rotated', 'Redeploy the sites that reference this instance.');
                                            await reload();
                                        } catch (e) {
                                            toast.error('Could not rotate the password', errorMessage(e));
                                        } finally {
                                            setRotating(false);
                                        }
                                    }}
                                >
                                    Rotate password
                                </Button>
                            </div>
                        )}
                    </>
                ) : (
                    <p className="text-fg-muted text-sm">
                        {keyValue ? 'The instance has no password yet.' : 'No user can access this database yet — add one under Databases & users.'}
                    </p>
                )}
            </Section>

            {keyValue && connection.access && connection.access.length > 0 && (
                <Section
                    title="Who can connect"
                    description="Sites of this environment and the address their references resolve to. Other servers connect over a private network only; the instance is never exposed on a public address."
                >
                    <ul className="grid gap-2" data-testid="instance-access">
                        {connection.access.map((item) => (
                            <li key={item.name} className="grid gap-0.5 text-sm">
                                <div className="flex min-w-0 items-center gap-2">
                                    <span className="text-fg font-medium">{item.name}</span>
                                    {item.host ? (
                                        <code className="text-fg-muted truncate font-mono text-xs">
                                            {item.host}:{connection.port}
                                        </code>
                                    ) : (
                                        <span className="text-warning inline-flex items-center gap-1 text-xs">
                                            <AlertTriangle className="size-3.5" aria-hidden />
                                            Can&apos;t connect
                                        </span>
                                    )}
                                </div>
                                {item.reason && <p className="text-fg-muted text-xs">{item.reason}</p>}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section
                title="Use from another service"
                description="Reference these in a site's variables; they resolve at deploy time within this environment and draw an edge on the canvas."
            >
                <div className="grid gap-1.5">
                    {keys.map((key) => {
                        const reference = `\${{ ${name}.${key} }}`;

                        return (
                            <div key={key} className="flex items-center gap-2">
                                <code className="text-fg-muted min-w-0 flex-1 truncate font-mono text-xs">{reference}</code>
                                <CopyButton value={reference} label={`Copy ${key} reference`} />
                            </div>
                        );
                    })}
                </div>
            </Section>
        </div>
    );
}
