import { Button } from '@/components/kiln/button';
import { Callout } from '@/components/kiln/callout';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { Section } from '@/components/kiln/section';
import { Switch } from '@/components/kiln/switch';
import { Tag } from '@/components/kiln/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm } from '@inertiajs/react';
import { Cloud, ExternalLink, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ZoneOption {
    id: string;
    name: string;
    status: string;
    plan: string;
    managed: { id: string; proxied: boolean } | null;
}

interface Connection {
    id: string;
    name: string;
    verified_at: string | null;
    error: string | null;
    zones: ZoneOption[];
}

interface ManagedRecord {
    name: string;
    type: string;
    content: string;
    proxied: boolean;
    status: 'pending' | 'synced' | 'conflict' | 'error';
    error: string | null;
}

interface ManagedZone {
    id: string;
    name: string;
    connection: string;
    proxied: boolean;
    generates: boolean;
    health: Record<string, { value: string | null; recommended: string; ok: boolean }> | null;
    records: ManagedRecord[];
}

interface Props {
    connections: Connection[];
    zones: ManagedZone[];
    can: { manage: boolean };
}

// Dashboard template for the token (https://developers.cloudflare.com/fundamentals/api/how-to/account-owned-token-template/).
const TOKEN_PERMISSIONS = [
    { key: 'dns', type: 'edit' },
    { key: 'zone', type: 'read' },
    { key: 'zone_settings', type: 'edit' },
    { key: 'cache', type: 'purge' },
];
const TOKEN_URL = `https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=${encodeURIComponent(JSON.stringify(TOKEN_PERMISSIONS))}&accountId=*&zoneId=all&name=${encodeURIComponent('Kiln')}`;

const SETTING_LABELS: Record<string, string> = { ssl: 'SSL/TLS mode', min_tls_version: 'Minimum TLS version' };
const SETTING_VALUES: Record<string, string> = { strict: 'Full (strict)', full: 'Full', flexible: 'Flexible', off: 'Off' };

const RECORD_TONE = { synced: 'success', pending: 'info', conflict: 'warning', error: 'danger' } as const;

const options = { preserveScroll: true };

/** Settings → Integrations → Cloudflare: connect a token, pick the zones Kiln manages, check their TLS settings. */
export default function Cloudflare({ connections, zones, can }: Props) {
    const form = useForm({ name: 'Cloudflare', api_token: '' });
    const [releasing, setReleasing] = useState<ManagedZone | null>(null);
    const [disconnecting, setDisconnecting] = useState<Connection | null>(null);

    return (
        <SettingsLayout
            title="Cloudflare"
            description="Let Kiln create your DNS records, generate names under your zone and put your services behind Cloudflare’s proxy (DDoS protection, edge cache, hidden origin)."
        >
            {can.manage && (
                <Section
                    title={connections.length === 0 ? 'Connect Cloudflare' : 'Add a connection'}
                    description="An API token scoped to the zones Kiln should manage. It is verified with Cloudflare and stored encrypted."
                >
                    <ol className="text-fg-muted grid list-decimal gap-1 pl-5 text-sm">
                        <li>
                            <a href={TOKEN_URL} target="_blank" rel="noreferrer" className="text-fg inline-flex items-center gap-1 underline">
                                Create a token in Cloudflare <ExternalLink className="size-3.5" aria-hidden />
                            </a>{' '}
                            — the permissions are filled in: Zone → DNS → Edit, Zone → Zone → Read, Zone → Zone Settings → Edit, Zone → Cache Purge.
                        </li>
                        <li>
                            Under <span className="text-fg">Zone Resources</span>, pick <span className="text-fg">Specific zone</span> and your
                            domain.
                        </li>
                        <li>Create the token, copy it and paste it below.</li>
                    </ol>
                    <form
                        className="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/settings/cloudflare', { ...options, onSuccess: () => form.reset('api_token') });
                        }}
                    >
                        <Field label="Name" error={form.errors.name}>
                            <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        </Field>
                        <Field label="API token" error={form.errors.api_token}>
                            <Input
                                type="password"
                                mono
                                autoComplete="off"
                                value={form.data.api_token}
                                onChange={(event) => form.setData('api_token', event.target.value)}
                                placeholder="Paste the token"
                            />
                        </Field>
                        <Button variant="primary" type="submit" icon={<Cloud />} loading={form.processing} disabled={form.data.api_token.length < 20}>
                            Connect
                        </Button>
                    </form>
                    <p className="text-fg-faint text-xs">
                        Later phases (tunnels, cache rules) also need Account → Cloudflare Tunnel → Edit and Zone → Cache Rules → Edit; you can add
                        them to the same token.
                    </p>
                </Section>
            )}

            {connections.map((connection) => (
                <Section
                    key={connection.id}
                    title={
                        <span className="flex items-center gap-2">
                            <Cloud className="text-fg-muted size-4" aria-hidden /> {connection.name}
                            {connection.error ? <Tag tone="danger">unreachable</Tag> : <Tag tone="success">connected</Tag>}
                        </span>
                    }
                    description="Zones this token can see. Manage a zone to let Kiln create and remove the DNS records of its domains."
                    aside={
                        can.manage && (
                            <Button variant="ghost" size="sm" icon={<Trash2 />} onClick={() => setDisconnecting(connection)}>
                                Disconnect
                            </Button>
                        )
                    }
                >
                    {connection.error && <Callout tone="danger">{connection.error}</Callout>}
                    <ul className="divide-border grid divide-y">
                        {connection.zones.map((zone) => (
                            <li key={zone.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span className="flex items-center gap-2">
                                    <span className="text-fg font-mono text-sm">{zone.name}</span>
                                    <Tag>{zone.plan || 'zone'}</Tag>
                                    {zone.status !== 'active' && <Tag tone="warning">{zone.status}</Tag>}
                                </span>
                                {zone.managed ? (
                                    <Tag tone="success">managed by Kiln</Tag>
                                ) : (
                                    can.manage && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/cloudflare/${connection.id}/zones`,
                                                    { zone_id: zone.id, proxied: true, generate: zones.length === 0 },
                                                    options,
                                                )
                                            }
                                        >
                                            Manage
                                        </Button>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            ))}

            {zones.map((zone) => (
                <Section
                    key={zone.id}
                    title={<span className="font-mono">{zone.name}</span>}
                    description={`Managed by Kiln through ${zone.connection}. Kiln only changes the records it created (tagged “kiln:” in Cloudflare).`}
                    aside={
                        can.manage && (
                            <div className="flex gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    icon={<RefreshCw />}
                                    onClick={() => router.post(`/settings/cloudflare/zones/${zone.id}/sync`, {}, options)}
                                >
                                    Sync
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setReleasing(zone)}>
                                    Stop managing
                                </Button>
                            </div>
                        )
                    }
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="flex items-start justify-between gap-3 text-sm">
                            <span>
                                <span className="text-fg font-medium">Proxy new records</span>
                                <span className="text-fg-muted block text-xs">
                                    Orange cloud: DDoS protection, edge cache and a hidden server IP. Each domain can override it.
                                </span>
                            </span>
                            <Switch
                                checked={zone.proxied}
                                disabled={!can.manage}
                                onCheckedChange={(proxied) => router.patch(`/settings/cloudflare/zones/${zone.id}`, { proxied }, options)}
                                aria-label={`Proxy new records in ${zone.name}`}
                            />
                        </label>
                        <label className="flex items-start justify-between gap-3 text-sm">
                            <span>
                                <span className="text-fg font-medium">Generate names here</span>
                                <span className="text-fg-muted block text-xs">
                                    New services get <span className="font-mono">service.{zone.name}</span> (copies in other environments:{' '}
                                    <span className="font-mono">service-staging.{zone.name}</span>).
                                </span>
                            </span>
                            <Switch
                                checked={zone.generates}
                                disabled={!can.manage}
                                onCheckedChange={(generate) => router.patch(`/settings/cloudflare/zones/${zone.id}`, { generate }, options)}
                                aria-label={`Generate names under ${zone.name}`}
                            />
                        </label>
                    </div>

                    {zone.health ? (
                        <div className="grid gap-2">
                            {Object.entries(zone.health).map(([key, setting]) => (
                                <div key={key} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span className="flex items-center gap-2">
                                        <ShieldCheck className={setting.ok ? 'text-success size-4' : 'text-warning size-4'} aria-hidden />
                                        {SETTING_LABELS[key] ?? key}:{' '}
                                        <span className="text-fg font-mono">{SETTING_VALUES[setting.value ?? ''] ?? setting.value ?? 'unknown'}</span>
                                        {!setting.ok && (
                                            <span className="text-fg-muted text-xs">
                                                (recommended: {SETTING_VALUES[setting.recommended] ?? setting.recommended})
                                            </span>
                                        )}
                                    </span>
                                    {!setting.ok && can.manage && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => router.put(`/settings/cloudflare/zones/${zone.id}/setting`, { setting: key }, options)}
                                        >
                                            Set to {SETTING_VALUES[setting.recommended] ?? setting.recommended}
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {zone.health.ssl && zone.health.ssl.value === 'flexible' && (
                                <Callout tone="warning">
                                    Flexible makes Cloudflare talk to your server over plain HTTP, and Kiln redirects HTTP to HTTPS: sites loop. Use
                                    Full (strict).
                                </Callout>
                            )}
                        </div>
                    ) : (
                        <p className="text-fg-faint text-xs">Zone settings could not be read (the token needs Zone Settings → Read or Edit).</p>
                    )}

                    <div className="grid gap-1">
                        <p className="text-fg text-sm font-medium">Records Kiln manages ({zone.records.length})</p>
                        {zone.records.length === 0 ? (
                            <p className="text-fg-muted text-xs">None yet. Add a domain under {zone.name} to a service, or generate one.</p>
                        ) : (
                            <ul className="grid gap-1">
                                {zone.records.map((record) => (
                                    <li key={`${record.name}-${record.type}-${record.content}`} className="grid gap-0.5 text-xs">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="text-fg font-mono">{record.name}</span>
                                            <Tag>{record.type}</Tag>
                                            <span className="text-fg-muted font-mono">{record.content}</span>
                                            {record.proxied && <Tag tone="info">proxied</Tag>}
                                            <Tag tone={RECORD_TONE[record.status]}>{record.status}</Tag>
                                        </span>
                                        {record.error && <span className="text-fg-muted">{record.error}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </Section>
            ))}

            <ConfirmDestructive
                open={releasing !== null}
                onOpenChange={(open) => !open && setReleasing(null)}
                title={`Stop managing ${releasing?.name}?`}
                description="Kiln stops changing DNS records in this zone. The records it created stay in Cloudflare, so your services keep resolving."
                confirmText={releasing?.name ?? ''}
                confirmLabel="Stop managing"
                onConfirm={async () => {
                    if (!releasing) return;
                    router.delete(`/settings/cloudflare/zones/${releasing.id}`, { ...options, onFinish: () => setReleasing(null) });
                }}
            />
            <ConfirmDestructive
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
                title={`Disconnect ${disconnecting?.name}?`}
                description="Its zones stop being managed (records stay in Cloudflare) and domains that got certificates through it switch back to HTTP validation."
                confirmText={disconnecting?.name ?? ''}
                confirmLabel="Disconnect"
                onConfirm={async () => {
                    if (!disconnecting) return;
                    router.delete(`/settings/cloudflare/${disconnecting.id}`, { ...options, onFinish: () => setDisconnecting(null) });
                }}
            />
        </SettingsLayout>
    );
}
