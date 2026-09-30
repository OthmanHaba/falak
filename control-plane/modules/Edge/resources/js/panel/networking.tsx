import {
    Button,
    Callout,
    Dialog,
    EmptyState,
    Field,
    IconButton,
    Input,
    Menu,
    RelativeTime,
    Section,
    Segmented,
    Select,
    SkeletonRows,
    StatusBadge,
    StatusDot,
    Switch,
    Tag,
    Textarea,
    Tooltip,
    toast,
} from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Globe, Lock, Plus, RotateCw, ShieldCheck, Star, Trash2, Upload } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { DnsInstructions, GeneratedDomainPreview } from '../components/domain-picker';
import { type DomainsData, type EdgeDomain, type RoutingData, type TlsMode, type WwwRedirect } from '../types';

const domainsUrl = (siteId: string) => `/sites/${siteId}/domains`;
const routingUrl = (siteId: string) => `/sites/${siteId}/routing`;

/** Run an Edge mutation: toast, reload, and 422 field errors back to the caller. */
async function mutate(
    method: HttpMethod,
    url: string,
    body: unknown,
    success: string,
    reload: () => Promise<void>,
): Promise<Record<string, string> | null> {
    try {
        await requestJson(url, method, body);
        toast.success(success);
        await reload();

        return null;
    } catch (error) {
        if (error instanceof HttpError && Object.keys(error.errors).length) return error.errors;
        toast.error('Something went wrong', errorMessage(error));

        return { form: errorMessage(error) };
    }
}

function Loading({ error }: { error: string | null }) {
    return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={4} />;
}

const TLS_TONE: Record<TlsMode, 'success' | 'info' | 'accent' | 'neutral' | 'warning'> = {
    auto: 'success',
    dns: 'success',
    custom: 'accent',
    internal: 'info',
    off: 'warning',
};

const WWW: { value: WwwRedirect; label: string }[] = [
    { value: 'none', label: 'No www redirect' },
    { value: 'to_www', label: 'Serve www, redirect apex → www' },
    { value: 'to_apex', label: 'Serve apex, redirect www → apex' },
];

// ─── Domains & TLS ───────────────────────────────────────────────────────────────────────────────────────────────

function DomainDialog({
    siteId,
    data,
    domain,
    open,
    onOpenChange,
    reload,
    onUploadCertificate,
}: {
    siteId: string;
    data: DomainsData;
    domain: EdgeDomain | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reload: () => Promise<void>;
    onUploadCertificate: () => void;
}) {
    const [kind, setKind] = useState<'custom' | 'generated'>('custom');
    const [form, setForm] = useState({
        name: '',
        tls_mode: 'auto' as TlsMode,
        www_redirect: 'none' as WwwRedirect,
        certificate_id: '',
        dns_credential_id: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setKind('custom');
        setForm({
            name: domain?.name ?? '',
            tls_mode: domain?.tls_mode ?? 'auto',
            www_redirect: domain?.www_redirect ?? 'none',
            certificate_id: domain?.certificate_id ?? '',
            dns_credential_id: domain?.dns_credential_id ?? '',
        });
    }, [open, domain]);

    const wildcard = form.name.startsWith('*.');
    const wwwAllowed = !wildcard && !form.name.startsWith('www.') && (domain?.supports_www ?? true);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        const generated = !domain && kind === 'generated';
        const body = {
            ...(domain ? {} : generated ? { type: 'generated' } : { name: form.name.trim() }),
            tls_mode: form.tls_mode,
            www_redirect: wwwAllowed ? form.www_redirect : 'none',
            certificate_id: form.tls_mode === 'custom' ? form.certificate_id || null : null,
            dns_credential_id: form.tls_mode === 'dns' ? form.dns_credential_id || null : null,
        };
        const problems = await mutate(
            domain ? 'PATCH' : 'POST',
            domain ? `${domainsUrl(siteId)}/${domain.id}` : domainsUrl(siteId),
            body,
            domain ? `${domain.name} updated` : `${generated ? 'Generated domain' : form.name.trim()} added — the edge is being updated`,
            reload,
        );
        setSaving(false);
        if (problems) setErrors(problems);
        else onOpenChange(false);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={domain ? `Edit ${domain.name}` : 'Add domain'}
            description="Bring your own domain (Kiln shows the DNS record and checks it), or generate one that works right away."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="domain-form" loading={saving}>
                        {domain ? 'Save' : 'Add domain'}
                    </Button>
                </>
            }
        >
            <form id="domain-form" onSubmit={submit} className="grid gap-4">
                {!domain && (
                    <Field label="Domain" error={errors.name}>
                        <div className="grid gap-2.5">
                            <Segmented
                                label="Domain kind"
                                value={kind}
                                onValueChange={setKind}
                                options={[
                                    { value: 'custom', label: 'Your domain' },
                                    { value: 'generated', label: 'Generate one' },
                                ]}
                            />
                            {kind === 'custom' ? (
                                <>
                                    <Input
                                        mono
                                        autoFocus
                                        aria-label="Domain name"
                                        placeholder="shop.example.com or *.example.com"
                                        value={form.name}
                                        onChange={(event) => setForm({ ...form, name: event.target.value })}
                                    />
                                    {/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/.test(form.name.trim().toLowerCase()) && (
                                        <DnsInstructions name={form.name.trim().toLowerCase()} serverIds={[]} siteId={siteId} label={data.slug} />
                                    )}
                                </>
                            ) : (
                                <GeneratedDomainPreview label={data.slug} serverIds={[]} siteId={siteId} />
                            )}
                        </div>
                    </Field>
                )}
                <Field
                    label="TLS"
                    error={errors.tls_mode}
                    hint={wildcard ? 'Wildcard domains need a DNS-01 challenge or a custom certificate.' : undefined}
                >
                    <Select
                        value={form.tls_mode}
                        onValueChange={(value) => setForm({ ...form, tls_mode: value as TlsMode })}
                        options={data.tlsModes.map((mode) => ({
                            value: mode.value as TlsMode,
                            label: mode.label,
                            disabled: wildcard && mode.value === 'auto',
                        }))}
                    />
                </Field>
                {form.tls_mode === 'custom' && (
                    <Field
                        label="Certificate"
                        error={errors.certificate_id}
                        aside={
                            <button type="button" className="text-primary text-xs hover:underline" onClick={onUploadCertificate}>
                                Upload…
                            </button>
                        }
                    >
                        <Select
                            value={form.certificate_id || undefined}
                            placeholder={data.certificates.length === 0 ? 'Upload a certificate first' : 'Choose a certificate'}
                            disabled={data.certificates.length === 0}
                            onValueChange={(value) => setForm({ ...form, certificate_id: value })}
                            options={data.certificates.map((certificate) => ({ value: certificate.id, label: certificate.domains.join(', ') }))}
                        />
                    </Field>
                )}
                {form.tls_mode === 'dns' && (
                    <Field label="DNS provider credential" error={errors.dns_credential_id}>
                        <Select
                            value={form.dns_credential_id || undefined}
                            placeholder={data.dnsCredentials.length === 0 ? 'Add a DNS credential first' : 'Choose a credential'}
                            disabled={data.dnsCredentials.length === 0}
                            onValueChange={(value) => setForm({ ...form, dns_credential_id: value })}
                            options={data.dnsCredentials.map((credential) => ({
                                value: credential.id,
                                label: `${credential.name} (${credential.provider})`,
                            }))}
                        />
                    </Field>
                )}
                {wwwAllowed && kind === 'custom' && (
                    <Field label="www redirect" error={errors.www_redirect}>
                        <Select value={form.www_redirect} onValueChange={(value) => setForm({ ...form, www_redirect: value })} options={WWW} />
                    </Field>
                )}
                {errors.form && <p className="text-danger text-xs">{errors.form}</p>}
            </form>
        </Dialog>
    );
}

function CertificateDialog({
    siteId,
    open,
    onOpenChange,
    reload,
}: {
    siteId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reload: () => Promise<void>;
}) {
    const [form, setForm] = useState({ certificate: '', private_key: '', chain: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        const problems = await mutate(
            'POST',
            `/sites/${siteId}/certificates`,
            { ...form, chain: form.chain || null },
            'Certificate uploaded',
            reload,
        );
        setSaving(false);
        if (problems) setErrors(problems);
        else {
            setForm({ certificate: '', private_key: '', chain: '' });
            onOpenChange(false);
        }
    };

    const pem = (key: keyof typeof form, label: string, placeholder: string) => (
        <Field label={label} error={errors[key]}>
            <Textarea
                mono
                rows={5}
                spellCheck={false}
                placeholder={placeholder}
                value={form[key]}
                onChange={(event) => setForm({ ...form, [key]: event.target.value })}
                onKeyDown={(event) => event.stopPropagation()}
            />
        </Field>
    );

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title="Upload a certificate"
            description="PEM files. The private key is encrypted at rest and only sent to the site’s edge servers."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="certificate-form" loading={saving}>
                        Upload
                    </Button>
                </>
            }
        >
            <form id="certificate-form" onSubmit={submit} className="grid gap-4">
                {pem('certificate', 'Certificate', '-----BEGIN CERTIFICATE-----')}
                {pem('private_key', 'Private key', '-----BEGIN PRIVATE KEY-----')}
                {pem('chain', 'Intermediate chain (optional)', '-----BEGIN CERTIFICATE-----')}
                {errors.form && <p className="text-danger text-xs">{errors.form}</p>}
            </form>
        </Dialog>
    );
}

function DnsCredentialDialog({
    data,
    open,
    onOpenChange,
    reload,
}: {
    data: DomainsData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reload: () => Promise<void>;
}) {
    const [form, setForm] = useState({ provider: data.dnsProviders[0]?.value ?? 'cloudflare', name: '', api_token: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        const problems = await mutate('POST', '/edge/dns-credentials', form, 'DNS credential added', reload);
        setSaving(false);
        if (problems) setErrors(problems);
        else onOpenChange(false);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Add a DNS credential"
            description="Used for DNS-01 challenges (wildcards, servers behind firewalls). Shared by the organization; the token is encrypted."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="dns-form" loading={saving}>
                        Add credential
                    </Button>
                </>
            }
        >
            <form id="dns-form" onSubmit={submit} className="grid gap-4">
                <Field label="Provider" error={errors.provider}>
                    <Select value={form.provider} onValueChange={(provider) => setForm({ ...form, provider })} options={data.dnsProviders} />
                </Field>
                <Field label="Name" error={errors.name}>
                    <Input
                        value={form.name}
                        placeholder="Cloudflare (acme.com)"
                        onChange={(event) => setForm({ ...form, name: event.target.value })}
                    />
                </Field>
                <Field label="API token" error={errors.api_token}>
                    <Input
                        mono
                        type="password"
                        autoComplete="off"
                        value={form.api_token}
                        onChange={(event) => setForm({ ...form, api_token: event.target.value })}
                    />
                </Field>
            </form>
        </Dialog>
    );
}

/** Networking: domains with TLS modes (auto, DNS-01, custom upload, internal, off), certificates and edge servers. */
export function DomainsSettings({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const { data, error, reload } = useJson<DomainsData>(domainsUrl(siteId));
    const [dialog, setDialog] = useState<{ domain: EdgeDomain | null } | null>(null);
    const [uploading, setUploading] = useState(false);
    const [addingDns, setAddingDns] = useState(false);
    const [applying, setApplying] = useState<string | null>(null);
    const [checking, setChecking] = useState<EdgeDomain | null>(null);

    if (!data) return <Loading error={error} />;
    const manage = data.can.manage;
    const refresh = async () => {
        await reload();
        ctx.refresh();
    };

    const reapply = async (serverId: string) => {
        setApplying(serverId);
        await mutate('POST', `/sites/${siteId}/edge/apply`, { server_id: serverId }, 'Re-applying the edge config', reload);
        setApplying(null);
    };

    return (
        <>
            <Section
                title="Domains"
                description="The primary domain is the canonical host (and the canvas card’s link). TLS certificates are issued and renewed automatically."
                aside={
                    manage &&
                    data.domains.length > 0 && (
                        <Button size="sm" variant="primary" icon={<Plus />} onClick={() => setDialog({ domain: null })}>
                            Add domain
                        </Button>
                    )
                }
                bare
            >
                {data.domains.length === 0 ? (
                    <EmptyState
                        size="sm"
                        icon={<Globe />}
                        title="No custom domain yet"
                        description={
                            data.testDomain ? (
                                <>
                                    The site is reachable at <code className="font-mono text-xs">{data.testDomain}</code>. Add your own domain to
                                    serve it with TLS.
                                </>
                            ) : (
                                'Add a domain to serve the site with automatic TLS.'
                            )
                        }
                        action={
                            manage && (
                                <Button size="sm" variant="primary" icon={<Plus />} onClick={() => setDialog({ domain: null })}>
                                    Add domain
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="border-border bg-surface-1 divide-border divide-y rounded-lg border" aria-label="Domains">
                        {data.domains.map((domain) => {
                            const certificate = data.certificates.find((item) => item.id === domain.certificate_id);

                            return (
                                <li key={domain.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                                    <div className="grid min-w-0 flex-1 gap-0.5">
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <a
                                                href={`https://${domain.served_host}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-fg truncate font-mono text-sm hover:underline"
                                            >
                                                {domain.name}
                                            </a>
                                            {domain.is_primary && (
                                                <Tag tone="accent" icon={<Star />}>
                                                    primary
                                                </Tag>
                                            )}
                                            {domain.wildcard && <Tag>wildcard</Tag>}
                                            {domain.cloudflare && <CloudflareTag cloudflare={domain.cloudflare} />}
                                        </span>
                                        <span className="text-fg-faint truncate text-[11px]">
                                            {domain.hosts.join(' · ')}
                                            {domain.www_redirect !== 'none' &&
                                                ` · ${WWW.find((item) => item.value === domain.www_redirect)?.label.toLowerCase()}`}
                                        </span>
                                    </div>
                                    <Tooltip
                                        content={
                                            certificate
                                                ? `Certificate for ${certificate.domains.join(', ')}`
                                                : (data.tlsModes.find((mode) => mode.value === domain.tls_mode)?.label ?? '')
                                        }
                                    >
                                        <span>
                                            <Tag tone={TLS_TONE[domain.tls_mode]} icon={domain.tls_mode === 'off' ? undefined : <Lock />}>
                                                {domain.tls_mode === 'auto'
                                                    ? "Let's Encrypt"
                                                    : domain.tls_mode === 'dns'
                                                      ? 'DNS-01'
                                                      : domain.tls_mode === 'custom'
                                                        ? 'Custom cert'
                                                        : domain.tls_mode === 'internal'
                                                          ? 'Internal CA'
                                                          : 'No TLS'}
                                            </Tag>
                                        </span>
                                    </Tooltip>
                                    {manage && (
                                        <Menu
                                            label={`${domain.name} actions`}
                                            actions={[
                                                ...(!domain.wildcard
                                                    ? [{ label: 'Check DNS & TLS', icon: <ShieldCheck />, onSelect: () => setChecking(domain) }]
                                                    : []),
                                                { label: 'Edit TLS & redirect', onSelect: () => setDialog({ domain }) },
                                                ...(domain.cloudflare
                                                    ? [
                                                          {
                                                              label: domain.cloudflare.proxied
                                                                  ? 'Cloudflare: DNS only (grey cloud)'
                                                                  : 'Cloudflare: proxy (orange cloud)',
                                                              onSelect: () =>
                                                                  void mutate(
                                                                      'PUT',
                                                                      `${domainsUrl(siteId)}/${domain.id}/cloudflare`,
                                                                      { proxied: !domain.cloudflare!.proxied },
                                                                      domain.cloudflare!.proxied
                                                                          ? `${domain.name} is DNS only`
                                                                          : `${domain.name} is proxied by Cloudflare`,
                                                                      refresh,
                                                                  ),
                                                          },
                                                      ]
                                                    : []),
                                                ...(domain.cloudflare
                                                    ? (['standard', 'everything', 'bypass'] as const).map((mode) => ({
                                                          label: `Cache: ${CACHE_LABELS[mode]}${domain.cloudflare!.cache === mode ? ' ✓' : ''}`,
                                                          disabled: domain.cloudflare!.cache === mode,
                                                          onSelect: () =>
                                                              void mutate(
                                                                  'PUT',
                                                                  `${domainsUrl(siteId)}/${domain.id}/cloudflare-cache`,
                                                                  { mode },
                                                                  `${domain.name}: cache ${CACHE_LABELS[mode].toLowerCase()}`,
                                                                  refresh,
                                                              ),
                                                      }))
                                                    : []),
                                                ...(domain.cloudflare
                                                    ? [
                                                          {
                                                              label: 'Purge Cloudflare cache',
                                                              onSelect: () =>
                                                                  void mutate(
                                                                      'POST',
                                                                      `/sites/${siteId}/cloudflare/purge`,
                                                                      {},
                                                                      'Cloudflare cache purged',
                                                                      refresh,
                                                                  ),
                                                          },
                                                      ]
                                                    : []),
                                                ...(!domain.is_primary
                                                    ? [
                                                          {
                                                              label: 'Make primary',
                                                              icon: <Star />,
                                                              onSelect: () =>
                                                                  void mutate(
                                                                      'PUT',
                                                                      `${domainsUrl(siteId)}/${domain.id}/primary`,
                                                                      {},
                                                                      `${domain.name} is now primary`,
                                                                      refresh,
                                                                  ),
                                                          },
                                                      ]
                                                    : []),
                                                { type: 'separator' },
                                                {
                                                    label: 'Remove',
                                                    icon: <Trash2 />,
                                                    danger: true,
                                                    onSelect: () =>
                                                        window.confirm(`Remove ${domain.name}? It stops being served immediately.`) &&
                                                        void mutate(
                                                            'DELETE',
                                                            `${domainsUrl(siteId)}/${domain.id}`,
                                                            undefined,
                                                            `${domain.name} removed`,
                                                            refresh,
                                                        ),
                                                },
                                            ]}
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Section>

            <Section
                title="Certificates"
                description="Uploaded certificates for the custom TLS mode. Automatic certificates don’t appear here."
                aside={
                    manage && (
                        <Button size="sm" icon={<Upload />} onClick={() => setUploading(true)}>
                            Upload certificate
                        </Button>
                    )
                }
            >
                {data.certificates.length === 0 ? (
                    <p className="text-fg-muted text-sm">No uploaded certificates.</p>
                ) : (
                    <ul className="divide-border -my-2 divide-y">
                        {data.certificates.map((certificate) => (
                            <li key={certificate.id} className="flex flex-wrap items-center gap-3 py-2.5">
                                <ShieldCheck className="text-fg-muted size-4" aria-hidden />
                                <div className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="text-fg truncate font-mono text-xs">{certificate.domains.join(', ')}</span>
                                    <span className="text-fg-faint text-[11px]">
                                        {certificate.issuer ?? 'Unknown issuer'} · expires{' '}
                                        {certificate.not_after ? <RelativeTime value={certificate.not_after} /> : 'unknown'}
                                    </span>
                                </div>
                                {certificate.expired ? (
                                    <StatusBadge status="failed" label="Expired" />
                                ) : (
                                    <StatusBadge status="active" label="Valid" />
                                )}
                                {certificate.installs.map((install) => (
                                    <Tooltip key={install.server_id} content={install.error ?? install.status}>
                                        <span className="text-fg-muted inline-flex items-center gap-1 font-mono text-[11px]">
                                            <StatusDot
                                                status={
                                                    install.status === 'installed'
                                                        ? 'active'
                                                        : install.status === 'failed'
                                                          ? 'failed'
                                                          : 'provisioning'
                                                }
                                            />
                                            {install.server_name}
                                        </span>
                                    </Tooltip>
                                ))}
                                {manage && (
                                    <IconButton
                                        size="sm"
                                        label="Delete certificate"
                                        icon={<Trash2 />}
                                        onClick={() =>
                                            window.confirm('Delete this certificate? Domains using it must switch TLS mode first.') &&
                                            void mutate(
                                                'DELETE',
                                                `/sites/${siteId}/certificates/${certificate.id}`,
                                                undefined,
                                                'Certificate deleted',
                                                reload,
                                            )
                                        }
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {data.can.manage_dns && (
                    <div className="border-border flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                        <span className="text-fg-muted text-xs">
                            DNS credentials: {data.dnsCredentials.length ? data.dnsCredentials.map((item) => item.name).join(', ') : 'none'}
                        </span>
                        <Button size="sm" variant="ghost" icon={<Plus />} onClick={() => setAddingDns(true)}>
                            Add DNS credential
                        </Button>
                    </div>
                )}
            </Section>

            <Section title="Edge servers" description="Servers that route this site, and the state of their last Caddy config apply.">
                <ul className="divide-border -my-2 divide-y">
                    {data.edgeServers.map((server) => (
                        <li key={server.id} className="flex flex-wrap items-center gap-3 py-2.5">
                            <span className="text-fg font-mono text-xs">{server.name}</span>
                            <Tag>{server.role}</Tag>
                            <span className="text-fg-faint flex-1 text-[11px]">
                                {server.state?.applied_at ? (
                                    <>
                                        {server.state.routes ?? 0} routes · applied <RelativeTime value={server.state.applied_at} />
                                    </>
                                ) : (
                                    'Not applied yet'
                                )}
                            </span>
                            {server.state && (
                                <Tooltip content={server.state.error ?? server.state.status}>
                                    <span>
                                        <StatusBadge
                                            status={
                                                server.state.status === 'applied'
                                                    ? 'active'
                                                    : server.state.status === 'pending'
                                                      ? 'provisioning'
                                                      : 'failed'
                                            }
                                            label={
                                                server.state.status === 'applied'
                                                    ? 'Applied'
                                                    : server.state.status === 'pending'
                                                      ? 'Applying'
                                                      : 'Failed'
                                            }
                                        />
                                    </span>
                                </Tooltip>
                            )}
                            {manage && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    icon={<RotateCw />}
                                    loading={applying === server.id}
                                    onClick={() => void reapply(server.id)}
                                >
                                    Re-apply
                                </Button>
                            )}
                        </li>
                    ))}
                    {data.edgeServers.length === 0 && <li className="text-fg-muted py-2 text-sm">No server routes this site yet.</li>}
                </ul>
            </Section>

            <LoadBalancer ctx={ctx} data={data} reload={reload} />

            <DomainDialog
                siteId={siteId}
                data={data}
                domain={dialog?.domain ?? null}
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
                reload={refresh}
                onUploadCertificate={() => setUploading(true)}
            />
            <Dialog
                open={checking !== null}
                onOpenChange={(open) => !open && setChecking(null)}
                title={checking ? `DNS & TLS for ${checking.served_host}` : 'DNS & TLS'}
                description="Where the name points now, the records it needs, and the certificate the edge serves for it."
            >
                {checking && <DnsInstructions name={checking.served_host} serverIds={[]} siteId={siteId} label={data.slug} />}
            </Dialog>
            <CertificateDialog siteId={siteId} open={uploading} onOpenChange={setUploading} reload={reload} />
            {data.can.manage_dns && <DnsCredentialDialog data={data} open={addingDns} onOpenChange={setAddingDns} reload={reload} />}
        </>
    );
}

function LoadBalancer({ ctx, data, reload }: { ctx: ServiceTabProps['ctx']; data: DomainsData; reload: () => Promise<void> }) {
    const initial = {
        server_id: data.loadBalancer?.server_id ?? '',
        policy: data.loadBalancer?.policy ?? data.policies[0]?.value ?? 'round_robin',
        health_uri: data.loadBalancer?.health_uri ?? '',
        backend_port: data.loadBalancer?.backend_port ? String(data.loadBalancer.backend_port) : '',
        weights: data.loadBalancer?.weights ?? {},
    };
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const key = JSON.stringify(initial);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => setForm(initial), [key]);

    if (data.lbServers.length === 0 && !data.loadBalancer) {
        return (
            <Section title="Load balancer" description="Put the site behind a load balancer server to spread traffic across its servers.">
                <p className="text-fg-muted text-sm">Create a server of type “Load balancer” in Infrastructure to use one.</p>
            </Section>
        );
    }

    const manage = data.can.manage;
    const save = async () => {
        setSaving(true);
        const problems = await mutate(
            'PUT',
            `/sites/${ctx.service.ref_id}/load-balancer`,
            { ...form, health_uri: form.health_uri || null, backend_port: form.backend_port ? Number(form.backend_port) : null },
            'Load balancer saved',
            reload,
        );
        setSaving(false);
        setErrors(problems ?? {});
    };

    return (
        <Section
            title="Load balancer"
            description="Traffic enters through the load balancer, which forwards to the site’s servers."
            footer={
                manage && (
                    <>
                        {data.loadBalancer && (
                            <Button
                                variant="ghost"
                                className="mr-auto"
                                onClick={() =>
                                    window.confirm('Stop using the load balancer? Point DNS back at the servers first.') &&
                                    void mutate('DELETE', `/sites/${ctx.service.ref_id}/load-balancer`, undefined, 'Load balancer removed', reload)
                                }
                            >
                                Remove
                            </Button>
                        )}
                        <Button
                            variant="primary"
                            loading={saving}
                            disabled={!form.server_id || JSON.stringify(form) === key}
                            onClick={() => void save()}
                        >
                            Save
                        </Button>
                    </>
                )
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Load balancer server" error={errors.server_id}>
                    <Select
                        value={form.server_id || undefined}
                        placeholder="Choose a server"
                        disabled={!manage}
                        onValueChange={(server_id) => setForm({ ...form, server_id })}
                        options={data.lbServers.map((server) => ({ value: server.id, label: server.name }))}
                    />
                </Field>
                <Field label="Policy" error={errors.policy}>
                    <Select value={form.policy} disabled={!manage} onValueChange={(policy) => setForm({ ...form, policy })} options={data.policies} />
                </Field>
                <Field label="Health check path" error={errors.health_uri}>
                    <Input
                        mono
                        placeholder="/up"
                        disabled={!manage}
                        value={form.health_uri}
                        onChange={(event) => setForm({ ...form, health_uri: event.target.value })}
                    />
                </Field>
                <Field label="Backend port" error={errors.backend_port}>
                    <Input
                        mono
                        inputMode="numeric"
                        placeholder="443"
                        disabled={!manage}
                        value={form.backend_port}
                        onChange={(event) => setForm({ ...form, backend_port: event.target.value.replace(/\D/g, '') })}
                    />
                </Field>
                {form.policy === 'weighted_round_robin' &&
                    data.targets.map((target) => (
                        <Field key={target.server_id} label={`Weight · ${target.name}`}>
                            <Input
                                mono
                                inputMode="numeric"
                                disabled={!manage}
                                value={String(form.weights[target.server_id] ?? 1)}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        weights: { ...form.weights, [target.server_id]: Number(event.target.value.replace(/\D/g, '') || 1) },
                                    })
                                }
                            />
                        </Field>
                    ))}
            </div>
        </Section>
    );
}

// ─── Routing: redirects, basic auth, headers, access & limits ───────────────────────────────────────────────────

function InlineForm({ children, onSubmit, action, saving }: { children: ReactNode; onSubmit: () => void; action: string; saving: boolean }) {
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
            onKeyDown={(event) => event.stopPropagation()}
            className="flex flex-wrap items-start gap-2"
        >
            {children}
            <Button type="submit" loading={saving}>
                {action}
            </Button>
        </form>
    );
}

function Row({ children, onDelete, label, manage }: { children: ReactNode; onDelete: () => void; label: string; manage: boolean }) {
    return (
        <li className="flex items-center gap-3 py-2">
            {children}
            {manage && <IconButton size="sm" label={label} icon={<Trash2 />} onClick={onDelete} />}
        </li>
    );
}

const MB = 1024 * 1024;
const lines = (value: string) =>
    value
        .split(/[\n,]/)
        .map((line) => line.trim())
        .filter(Boolean);

export function RoutingSettings({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const { data, error, reload } = useJson<RoutingData>(routingUrl(siteId));
    const [redirect, setRedirect] = useState({ from: '', to: '', status: '301' });
    const [rule, setRule] = useState({ path: '', username: '', password: '', name: '' });
    const [header, setHeader] = useState({ name: '', value: '' });
    const [limits, setLimits] = useState({ allow_ips: '', deny_ips: '', max_body_mb: '', encode: true });
    const [errors, setErrors] = useState<Record<string, Record<string, string>>>({});
    const [saving, setSaving] = useState<string | null>(null);

    const settingsKey = JSON.stringify(data?.settings ?? null);
    useEffect(() => {
        if (!data) return;
        setLimits({
            allow_ips: data.settings.allow_ips.join('\n'),
            deny_ips: data.settings.deny_ips.join('\n'),
            max_body_mb: data.settings.max_body_bytes ? String(Math.round((data.settings.max_body_bytes / MB) * 100) / 100) : '',
            encode: data.settings.encode,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [settingsKey]);

    if (!data) return <Loading error={error} />;
    const manage = data.can.manage;

    const submit = async (kind: string, method: HttpMethod, url: string, body: unknown, success: string, reset?: () => void) => {
        setSaving(kind);
        const problems = await mutate(method, url, body, success, reload);
        setSaving(null);
        setErrors((current) => ({ ...current, [kind]: problems ?? {} }));
        if (!problems) reset?.();
    };
    const remove = (url: string, what: string) =>
        window.confirm(`Delete ${what}?`) && void mutate('DELETE', url, undefined, `${what} deleted`, reload);
    const err = (kind: string, key: string) => errors[kind]?.[key];

    return (
        <>
            {data.behindLoadBalancer && (
                <Callout tone="info">This site is behind a load balancer: redirects, authentication and IP rules are enforced there.</Callout>
            )}
            <Section title="Redirects" description="Evaluated in order before the application. Paths may use wildcards, e.g. /blog/*.">
                {data.redirects.length === 0 ? (
                    <p className="text-fg-muted text-sm">No redirects.</p>
                ) : (
                    <ul className="divide-border -my-2 divide-y">
                        {data.redirects.map((item) => (
                            <Row
                                key={item.id}
                                manage={manage}
                                label={`Delete redirect ${item.from}`}
                                onDelete={() => remove(`/sites/${siteId}/redirects/${item.id}`, `Redirect ${item.from}`)}
                            >
                                <code className="text-fg min-w-0 flex-1 truncate font-mono text-xs">{item.from}</code>
                                <span className="text-fg-faint text-xs">→</span>
                                <code className="text-fg-muted min-w-0 flex-1 truncate font-mono text-xs">{item.to}</code>
                                <Tag mono>{item.status}</Tag>
                            </Row>
                        ))}
                    </ul>
                )}
                {manage && (
                    <InlineForm
                        action="Add"
                        saving={saving === 'redirect'}
                        onSubmit={() =>
                            void submit(
                                'redirect',
                                'POST',
                                `/sites/${siteId}/redirects`,
                                { ...redirect, status: Number(redirect.status) },
                                'Redirect added',
                                () => setRedirect({ from: '', to: '', status: '301' }),
                            )
                        }
                    >
                        <div className="grid min-w-40 flex-1 gap-1">
                            <Input
                                mono
                                aria-label="From path"
                                placeholder="/old-path"
                                value={redirect.from}
                                onChange={(event) => setRedirect({ ...redirect, from: event.target.value })}
                            />
                            {err('redirect', 'from') && <p className="text-danger text-xs">{err('redirect', 'from')}</p>}
                        </div>
                        <div className="grid min-w-40 flex-1 gap-1">
                            <Input
                                mono
                                aria-label="Target"
                                placeholder="/new-path or https://…"
                                value={redirect.to}
                                onChange={(event) => setRedirect({ ...redirect, to: event.target.value })}
                            />
                            {err('redirect', 'to') && <p className="text-danger text-xs">{err('redirect', 'to')}</p>}
                        </div>
                        <Select
                            className="w-20"
                            aria-label="Status code"
                            value={redirect.status}
                            onValueChange={(status) => setRedirect({ ...redirect, status })}
                            options={['301', '302', '307', '308'].map((value) => ({ value, label: value }))}
                        />
                    </InlineForm>
                )}
            </Section>

            <Section
                title="Basic authentication"
                description="Protect the whole site (empty path) or paths such as /admin/*. Passwords are stored as bcrypt hashes."
            >
                {data.rules.length === 0 ? (
                    <p className="text-fg-muted text-sm">No protected paths.</p>
                ) : (
                    <ul className="divide-border -my-2 divide-y">
                        {data.rules.map((item) => (
                            <Row
                                key={item.id}
                                manage={manage}
                                label={`Delete ${item.username} on ${item.path ?? '/*'}`}
                                onDelete={() => remove(`/sites/${siteId}/security-rules/${item.id}`, `Rule for ${item.username}`)}
                            >
                                <Lock className="text-fg-faint size-3.5" aria-hidden />
                                <code className="text-fg font-mono text-xs">{item.path ?? '/* (whole site)'}</code>
                                <span className="text-fg-muted flex-1 text-xs">
                                    {item.username}
                                    {item.name && ` · ${item.name}`}
                                </span>
                            </Row>
                        ))}
                    </ul>
                )}
                {manage && (
                    <InlineForm
                        action="Protect"
                        saving={saving === 'rule'}
                        onSubmit={() =>
                            void submit(
                                'rule',
                                'POST',
                                `/sites/${siteId}/security-rules`,
                                { ...rule, path: rule.path || null, name: rule.name || null },
                                'Path protected',
                                () => setRule({ path: '', username: '', password: '', name: '' }),
                            )
                        }
                    >
                        <div className="grid min-w-32 flex-1 gap-1">
                            <Input
                                mono
                                aria-label="Path"
                                placeholder="/admin/* (empty = whole site)"
                                value={rule.path}
                                onChange={(event) => setRule({ ...rule, path: event.target.value })}
                            />
                            {err('rule', 'path') && <p className="text-danger text-xs">{err('rule', 'path')}</p>}
                        </div>
                        <div className="grid min-w-28 flex-1 gap-1">
                            <Input
                                aria-label="Username"
                                placeholder="Username"
                                value={rule.username}
                                onChange={(event) => setRule({ ...rule, username: event.target.value })}
                            />
                            {err('rule', 'username') && <p className="text-danger text-xs">{err('rule', 'username')}</p>}
                        </div>
                        <div className="grid min-w-28 flex-1 gap-1">
                            <Input
                                type="password"
                                autoComplete="new-password"
                                aria-label="Password"
                                placeholder="Password"
                                value={rule.password}
                                onChange={(event) => setRule({ ...rule, password: event.target.value })}
                            />
                            {err('rule', 'password') && <p className="text-danger text-xs">{err('rule', 'password')}</p>}
                        </div>
                    </InlineForm>
                )}
            </Section>

            <Section
                title="Response headers"
                description="Added to every response (e.g. Strict-Transport-Security). Saving an existing name replaces it."
            >
                {data.headers.length === 0 ? (
                    <p className="text-fg-muted text-sm">No custom headers.</p>
                ) : (
                    <ul className="divide-border -my-2 divide-y">
                        {data.headers.map((item) => (
                            <Row
                                key={item.id}
                                manage={manage}
                                label={`Delete header ${item.name}`}
                                onDelete={() => remove(`/sites/${siteId}/headers/${item.id}`, `Header ${item.name}`)}
                            >
                                <code className="text-fg font-mono text-xs font-medium">{item.name}</code>
                                <code className="text-fg-muted min-w-0 flex-1 truncate font-mono text-xs">{item.value}</code>
                            </Row>
                        ))}
                    </ul>
                )}
                {manage && (
                    <InlineForm
                        action="Save header"
                        saving={saving === 'header'}
                        onSubmit={() =>
                            void submit('header', 'POST', `/sites/${siteId}/headers`, header, `${header.name} saved`, () =>
                                setHeader({ name: '', value: '' }),
                            )
                        }
                    >
                        <div className="grid min-w-36 flex-1 gap-1">
                            <Input
                                mono
                                aria-label="Header name"
                                placeholder="X-Frame-Options"
                                value={header.name}
                                onChange={(event) => setHeader({ ...header, name: event.target.value })}
                            />
                            {err('header', 'name') && <p className="text-danger text-xs">{err('header', 'name')}</p>}
                        </div>
                        <div className="grid min-w-48 flex-[2] gap-1">
                            <Input
                                mono
                                aria-label="Header value"
                                placeholder="DENY"
                                value={header.value}
                                onChange={(event) => setHeader({ ...header, value: event.target.value })}
                            />
                            {err('header', 'value') && <p className="text-danger text-xs">{err('header', 'value')}</p>}
                        </div>
                    </InlineForm>
                )}
            </Section>

            <Section
                title="Access & limits"
                description="IP rules accept addresses or CIDR ranges, one per line. With an allow list, every other client gets 403."
                footer={
                    manage && (
                        <Button
                            variant="primary"
                            loading={saving === 'limits'}
                            onClick={() =>
                                void submit(
                                    'limits',
                                    'PUT',
                                    `/sites/${siteId}/edge-settings`,
                                    {
                                        allow_ips: lines(limits.allow_ips),
                                        deny_ips: lines(limits.deny_ips),
                                        max_body_bytes: limits.max_body_mb === '' ? null : Math.round(Number(limits.max_body_mb) * MB),
                                        encode: limits.encode,
                                    },
                                    'Access & limits saved',
                                )
                            }
                        >
                            Save
                        </Button>
                    )
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Allow only" error={Object.entries(errors.limits ?? {}).find(([key]) => key.startsWith('allow_ips'))?.[1]}>
                        <Textarea
                            mono
                            rows={3}
                            placeholder="203.0.113.0/24"
                            disabled={!manage}
                            value={limits.allow_ips}
                            onChange={(event) => setLimits({ ...limits, allow_ips: event.target.value })}
                        />
                    </Field>
                    <Field label="Deny" error={Object.entries(errors.limits ?? {}).find(([key]) => key.startsWith('deny_ips'))?.[1]}>
                        <Textarea
                            mono
                            rows={3}
                            placeholder="198.51.100.7"
                            disabled={!manage}
                            value={limits.deny_ips}
                            onChange={(event) => setLimits({ ...limits, deny_ips: event.target.value })}
                        />
                    </Field>
                    <Field label="Max request body (MB)" error={errors.limits?.max_body_bytes} hint="Empty = unlimited.">
                        <Input
                            mono
                            inputMode="decimal"
                            disabled={!manage}
                            value={limits.max_body_mb}
                            onChange={(event) => setLimits({ ...limits, max_body_mb: event.target.value.replace(/[^\d.]/g, '') })}
                        />
                    </Field>
                    <Field inline label="Compress responses (gzip / zstd)">
                        <Switch checked={limits.encode} disabled={!manage} onCheckedChange={(encode) => setLimits({ ...limits, encode })} />
                    </Field>
                </div>
            </Section>
        </>
    );
}

const CACHE_LABELS = { standard: 'Standard (static files)', everything: 'Everything (HTML too)', bypass: 'Bypass' } as const;

/** Cloudflare state of a managed domain: orange / grey cloud, and whether its records are in place. */
function CloudflareTag({ cloudflare }: { cloudflare: NonNullable<EdgeDomain['cloudflare']> }) {
    const problem = cloudflare.records.find((record) => record.status === 'conflict' || record.status === 'error');
    const pending = cloudflare.records.length === 0 || cloudflare.records.some((record) => record.status === 'pending');

    return (
        <Tooltip
            content={
                problem?.error ??
                (pending
                    ? `Kiln is creating the DNS record in ${cloudflare.zone}.`
                    : `DNS managed by Kiln in ${cloudflare.zone}: ${cloudflare.records.map((record) => `${record.type} ${record.content}`).join(', ')}`)
            }
        >
            <span>
                <Tag tone={problem ? 'warning' : cloudflare.proxied ? 'info' : 'neutral'}>
                    {cloudflare.proxied ? 'Cloudflare · proxied' : 'Cloudflare · DNS only'}
                    {cloudflare.cache !== 'standard' && ` · cache ${cloudflare.cache}`}
                    {problem ? ` · ${problem.status}` : pending ? ' · syncing' : ''}
                </Tag>
            </span>
        </Tooltip>
    );
}
