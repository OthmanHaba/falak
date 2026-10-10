import { Button, Callout, Field, Input, Section, Select, Tag } from '@/components/falak';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface Props {
    settings: {
        domain: string;
        server_id: string | null;
        dns_credential_id: string | null;
        managed_dns: boolean;
        status: string;
        error: string | null;
    } | null;
    can: { manage: boolean };
    servers: { id: string; name: string; ipv4: string | null }[];
    dns_credentials: { id: string; name: string; provider: string }[];
}

const STATUS: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
    active: { label: 'DNS managed', tone: 'success' },
    manual: { label: 'DNS kept by you', tone: 'neutral' },
    pending: { label: 'Setting up', tone: 'warning' },
    error: { label: 'DNS error', tone: 'danger' },
};

/** Settings → Previews: the domain this Falak serves pull request previews under. */
export default function Domain({ settings, can, servers, dns_credentials }: Props) {
    const form = useForm({
        domain: settings?.domain ?? '',
        server_id: settings?.server_id ?? servers[0]?.id ?? '',
        dns_credential_id: settings?.dns_credential_id ?? null,
    });
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put('/settings/previews', { preserveScroll: true });
    };
    const status = settings ? STATUS[settings.status] : null;

    return (
        <SettingsLayout title="Previews" description="The domain pull request previews are served under, e.g. pr-12-web.prv.example.com.">
            {settings?.error && (
                <Callout tone="danger" title="The wildcard record could not be set up">
                    {settings.error}
                </Callout>
            )}

            {!can.manage ? (
                <Section title="Preview domain">
                    {settings ? (
                        <p className="text-fg text-sm">
                            Previews are served under <span className="font-mono">*.{settings.domain}</span>.
                        </p>
                    ) : (
                        <p className="text-fg-muted text-sm">This Falak has no preview domain. Its operator sets one here.</p>
                    )}
                </Section>
            ) : (
                <form onSubmit={submit}>
                    <Section
                        title="Preview domain"
                        aside={status && <Tag tone={status.tone}>{status.label}</Tag>}
                        description={
                            <>
                                With a Cloudflare credential, Falak points <span className="font-mono">*.{form.data.domain || 'domain'}</span> at the
                                edge server and gets one wildcard certificate (DNS-01) for every preview there; previews on other servers get a record
                                of their own. Without one, add the wildcard record yourself: each live preview host then gets its own certificate as
                                it is routed.
                            </>
                        }
                        footer={
                            <div className="flex w-full items-center gap-2">
                                <Button type="submit" variant="primary" loading={form.processing}>
                                    Save
                                </Button>
                                {settings && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => router.delete('/settings/previews', { preserveScroll: true })}
                                    >
                                        Turn previews off
                                    </Button>
                                )}
                            </div>
                        }
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Domain" error={form.errors.domain} hint="Only previews use names under it (sites can't add them).">
                                <Input
                                    mono
                                    placeholder="prv.example.com"
                                    value={form.data.domain}
                                    onChange={(e) => form.setData('domain', e.target.value)}
                                />
                            </Field>
                            <Field label="Edge server" error={form.errors.server_id} hint="The wildcard record points at its public address.">
                                <Select
                                    value={form.data.server_id || undefined}
                                    onValueChange={(value) => form.setData('server_id', value)}
                                    options={servers.map((s) => ({ value: s.id, label: `${s.name}${s.ipv4 ? ` (${s.ipv4})` : ''}` }))}
                                />
                            </Field>
                            <Field label="DNS provider" error={form.errors.dns_credential_id}>
                                <Select
                                    value={form.data.dns_credential_id ?? '_'}
                                    onValueChange={(value) => form.setData('dns_credential_id', value === '_' ? null : value)}
                                    options={[
                                        { value: '_', label: 'None (I keep the wildcard record)' },
                                        ...dns_credentials.map((c) => ({ value: c.id, label: `${c.name} (${c.provider})` })),
                                    ]}
                                />
                            </Field>
                        </div>
                    </Section>
                </form>
            )}
        </SettingsLayout>
    );
}
