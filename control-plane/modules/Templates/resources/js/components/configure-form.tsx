import { domainPayload, DomainPicker, type DomainChoice } from '@/components/domain-picker';
import { Button, Callout, Checkbox, CodeBlock, Field, IconButton, Input, Select, Skeleton, Switch, Tag, Tooltip } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { BookOpen, Cpu, Eye, EyeOff, RefreshCw, Rocket, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { detailUrl, slugify, type DeployResult, type TemplateDetail, type TemplateInput, type TemplateSummary } from '../types';
import { TemplateIconTile } from './template-icon';

/** Sites' GET /sites/create JSON (servers the new site can target). */
interface SiteOptions {
    options: { servers: { id: string; name: string; type_label: string; status: string; docker: boolean }[] };
}

export interface DeployTarget {
    projectId: string;
    environmentSlug: string;
}

function Errors({ errors }: { errors: string[] }) {
    if (errors.length === 0) return null;

    return (
        <div role="alert" className="bg-danger-soft text-danger grid gap-0.5 rounded-md px-3 py-2 text-xs">
            {errors.map((message) => (
                <span key={message}>{message}</span>
            ))}
        </div>
    );
}

/** A generated secret: masked, with Show and Regenerate (docs/COMPOSE_TEMPLATES.md §3). */
function GeneratedSecret({ value, onChange, onRegenerate }: { value: string; onChange: (value: string) => void; onRegenerate: () => Promise<void> }) {
    const [visible, setVisible] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex min-w-0 items-center gap-1">
            <Input
                type={visible ? 'text' : 'password'}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                autoComplete="new-password"
                spellCheck={false}
                mono
                className="min-w-0 flex-1"
            />
            <IconButton
                size="sm"
                label={visible ? 'Hide' : 'Show'}
                icon={visible ? <EyeOff /> : <Eye />}
                onClick={() => setVisible((current) => !current)}
            />
            <IconButton
                size="sm"
                label="Regenerate"
                icon={<RefreshCw className={cn(busy && 'animate-spin')} />}
                disabled={busy}
                onClick={async () => {
                    setBusy(true);
                    try {
                        await onRegenerate();
                    } finally {
                        setBusy(false);
                    }
                }}
            />
        </div>
    );
}

function InputControl({
    input,
    value,
    onChange,
    onRegenerate,
}: {
    input: TemplateInput;
    value: string;
    onChange: (value: string) => void;
    onRegenerate: () => Promise<void>;
}) {
    if (input.generated) return <GeneratedSecret value={value} onChange={onChange} onRegenerate={onRegenerate} />;

    switch (input.type) {
        case 'boolean':
            return <Switch checked={value === 'true'} onCheckedChange={(checked) => onChange(checked ? 'true' : 'false')} />;
        case 'select':
            return (
                <Select
                    value={value}
                    // Radix can report '' while its items mount; an input with options never becomes empty.
                    onValueChange={(next) => next !== '' && onChange(next)}
                    options={(input.options ?? []).map((option) => ({ value: option, label: option }))}
                />
            );
        case 'secret':
            return <Input type="password" value={value} onChange={(event) => onChange(event.target.value)} autoComplete="new-password" mono />;
        case 'number':
            return <Input value={value} onChange={(event) => onChange(event.target.value)} inputMode="decimal" placeholder={input.placeholder} />;
        case 'email':
            return (
                <Input
                    type="email"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={input.placeholder ?? 'you@example.com'}
                />
            );
        default:
            return (
                <Input
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={input.placeholder}
                    mono={input.type === 'domain' || value.includes('${{')}
                />
            );
    }
}

function initialValues(detail: TemplateDetail): Record<string, string> {
    return Object.fromEntries(
        detail.inputs.map((input) => [
            input.key,
            detail.generated[input.key] ??
                input.default ??
                (input.type === 'boolean' ? 'false' : input.type === 'select' ? (input.options?.[0] ?? '') : ''),
        ]),
    );
}

export interface ConfigureFormProps {
    template: TemplateSummary;
    /** Where to deploy; null renders nothing deployable (e.g. no project picked yet). */
    target: DeployTarget | null;
    position?: { x: number; y: number } | null;
    onDeployed: (result: DeployResult, warnings: string[]) => void | Promise<void>;
    /** Extra fields above the form (the /templates page's project + environment pickers). */
    header?: ReactNode;
    /** Tighter spacing for the canvas picker. */
    compact?: boolean;
}

/**
 * Template detail → Configure → Deploy: inputs (generated secrets masked), a domain per public service (generated
 * sslip.io name, test domain or the user's own with DNS instructions), servers (warning for stateful templates on several
 * servers), the memory hint.
 */
export function ConfigureForm({ template, target, position = null, onDeployed, header, compact = false }: ConfigureFormProps) {
    const detail = useJson<TemplateDetail>(detailUrl(template));
    const sites = useJson<SiteOptions>('/sites/create');
    const [values, setValues] = useState<Record<string, string>>({});
    const [domains, setDomains] = useState<Record<string, DomainChoice | null>>({});
    const [name, setName] = useState(template.name);
    const [serverIds, setServerIds] = useState<string[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    const [showCompose, setShowCompose] = useState(false);

    useEffect(() => {
        if (detail.data) setValues((current) => (Object.keys(current).length > 0 ? current : initialValues(detail.data!)));
    }, [detail.data]);

    const servers = useMemo(() => (sites.data?.options.servers ?? []).filter((server) => server.docker), [sites.data]);
    const selected =
        serverIds ??
        servers
            .filter((server) => server.status === 'active')
            .slice(0, 1)
            .map((server) => server.id);
    const slug = slugify(name || template.slug);
    const testDomain = (service: string, index: number) =>
        detail.data?.test_domain ? (index === 0 ? `${slug}.${detail.data.test_domain}` : `${service}-${slug}.${detail.data.test_domain}`) : null;

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!target) return;
        setSubmitting(true);
        setErrors({});
        try {
            const response = await requestJson<{ data: DeployResult; warnings: string[] }>(
                `/projects/${target.projectId}/${target.environmentSlug}/templates/${template.slug}/deploy`,
                'POST',
                {
                    source: template.source,
                    name,
                    inputs: values,
                    // Services without a choice get the organization default (test domain, else a generated one).
                    domains: Object.fromEntries(
                        Object.entries(domains)
                            .map(([service, choice]) => [service, domainPayload(choice)] as const)
                            .filter(([, choice]) => choice !== undefined),
                    ),
                    server_ids: selected,
                    position,
                },
            );
            await onDeployed(response.data, response.warnings);
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { form: errorMessage(error) });
        } finally {
            setSubmitting(false);
        }
    };

    const shown = new Set(['name', 'server_ids', 'server_ids.0']);
    detail.data?.inputs.forEach((input) => shown.add(`inputs.${input.key}`));
    template.public.forEach((entry) => shown.add(`domains.${entry.service}`));
    const other = Object.entries(errors)
        .filter(([key]) => !shown.has(key))
        .map(([, message]) => message);

    return (
        <form className={cn('grid gap-5', compact ? 'p-4' : '')} onSubmit={submit} aria-label={`Configure ${template.name}`}>
            <div className="flex items-start gap-3">
                <TemplateIconTile icon={template.icon} name={template.name} size="lg" />
                <div className="grid min-w-0 flex-1 gap-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <h2 className="text-fg text-sm font-semibold">{template.name}</h2>
                        <Tag mono>v{template.version}</Tag>
                        {template.source === 'custom' && <Tag tone="accent">Custom</Tag>}
                    </div>
                    <p className="text-fg-muted text-xs">{template.description}</p>
                    <div className="text-fg-faint flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        {template.docs && (
                            <a
                                href={template.docs}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="hover:text-fg inline-flex items-center gap-1"
                            >
                                <BookOpen className="size-3.5" aria-hidden /> Docs
                            </a>
                        )}
                        {template.min_memory_mb && (
                            <span className="inline-flex items-center gap-1" title="Free memory each server needs">
                                <Cpu className="size-3.5" aria-hidden /> Needs ~
                                {template.min_memory_mb >= 1024 ? `${template.min_memory_mb / 1024} GB` : `${template.min_memory_mb} MB`} RAM
                            </span>
                        )}
                        <button
                            type="button"
                            className="hover:text-fg underline-offset-2 hover:underline"
                            onClick={() => setShowCompose((current) => !current)}
                        >
                            {showCompose ? 'Hide compose file' : 'View compose file'}
                        </button>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap gap-1.5" aria-label="Services">
                {template.services.map((service) => (
                    <Tooltip key={service.name} content={service.image}>
                        <span className="border-border bg-surface-2 text-fg-muted inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs">
                            <span className="text-fg font-medium">{service.name}</span>
                            <span className="text-fg-faint truncate font-mono text-[11px]">{service.image.split('/').pop()}</span>
                        </span>
                    </Tooltip>
                ))}
            </div>

            {showCompose && detail.data && <CodeBlock code={detail.data.compose} title="compose.yaml" maxHeight={260} />}

            {header}

            <Field label="Service name" hint="Name on the canvas; the site slug and test domains derive from it." error={errors.name}>
                <Input value={name} onChange={(event) => setName(event.target.value)} />
            </Field>

            {detail.error && <Errors errors={[detail.error]} />}
            {!detail.data && !detail.error && (
                <div className="grid gap-3">
                    {[0, 1, 2].map((i) => (
                        <Skeleton key={i} className="h-12" />
                    ))}
                </div>
            )}

            {detail.data && detail.data.inputs.length > 0 && (
                <fieldset className="grid gap-4">
                    <legend className="text-fg-muted mb-3 text-xs font-medium tracking-wide uppercase">Variables</legend>
                    {detail.data.inputs.map((input) => (
                        <Field
                            key={input.key}
                            label={
                                <span className="inline-flex items-center gap-1.5">
                                    {input.label}
                                    <span className="text-fg-faint font-mono text-[11px] font-normal">{input.key}</span>
                                </span>
                            }
                            required={input.required}
                            inline={input.type === 'boolean'}
                            hint={
                                input.generated
                                    ? `${input.description ? `${input.description} ` : ''}Generated (${input.generate}) — stored encrypted, stable across deploys.`
                                    : (input.description ??
                                      ((values[input.key] ?? '').includes('${{') ? 'Resolved when the site deploys.' : undefined))
                            }
                            error={errors[`inputs.${input.key}`]}
                        >
                            <InputControl
                                input={input}
                                value={values[input.key] ?? ''}
                                onChange={(value) => setValues((current) => ({ ...current, [input.key]: value }))}
                                onRegenerate={async () => {
                                    const fresh = await requestJson<{ data: { value: string } }>(`${detailUrl(template)}/generate/${input.key}`);
                                    setValues((current) => ({ ...current, [input.key]: fresh.data.value }));
                                }}
                            />
                        </Field>
                    ))}
                </fieldset>
            )}

            <Field
                label="Servers"
                error={errors.server_ids ?? errors['server_ids.0']}
                hint={selected.length > 1 ? 'The stack runs on every selected server (replicated).' : undefined}
            >
                {!sites.data ? (
                    sites.error ? (
                        <Errors errors={[sites.error]} />
                    ) : (
                        <Skeleton className="h-9" />
                    )
                ) : servers.length === 0 ? (
                    <p className="text-fg-muted text-xs">
                        No Docker-capable servers yet.{' '}
                        <Link href="/servers/create" className="text-primary hover:underline">
                            Add a server
                        </Link>
                    </p>
                ) : (
                    <div className="border-border grid max-h-40 overflow-y-auto rounded-md border">
                        {servers.map((server) => {
                            const checked = selected.includes(server.id);

                            return (
                                <label key={server.id} className="hover:bg-surface-2 flex cursor-pointer items-center gap-2.5 px-2.5 py-1.5 text-sm">
                                    <Checkbox
                                        checked={checked}
                                        onCheckedChange={(next) =>
                                            setServerIds(next ? [...selected, server.id] : selected.filter((id) => id !== server.id))
                                        }
                                        aria-label={server.name}
                                    />
                                    <span className="text-fg font-mono text-xs">{server.name}</span>
                                    <span className="text-fg-faint text-xs">{server.type_label}</span>
                                    {server.status !== 'active' && (
                                        <Tag tone="warning" className="ml-auto">
                                            {server.status}
                                        </Tag>
                                    )}
                                </label>
                            );
                        })}
                    </div>
                )}
            </Field>

            {template.stateful && selected.length > 1 && (
                <Callout tone="warning" title="Stateful template on several servers">
                    Each server runs its own copy with its own volumes — data is not shared or replicated between them. Pick one server unless the app
                    clusters on its own.
                </Callout>
            )}

            <fieldset className="grid gap-4">
                <legend className="text-fg-muted mb-3 text-xs font-medium tracking-wide uppercase">Domains</legend>
                {template.public.map((entry, index) => (
                    <Field
                        key={entry.service}
                        label={
                            <span className="inline-flex items-center gap-1.5">
                                {entry.service}
                                <span className="text-fg-faint font-mono text-[11px] font-normal">:{entry.port}</span>
                            </span>
                        }
                        error={errors[`domains.${entry.service}`]}
                    >
                        {selected.length === 0 ? (
                            <p className="text-fg-muted text-xs">Pick a server above: the domain options depend on it.</p>
                        ) : (
                            <DomainPicker
                                label={entry.service === slug ? slug : `${entry.service}-${slug}`}
                                serverIds={selected}
                                testDomain={testDomain(entry.service, index)}
                                value={domains[entry.service] ?? null}
                                onChange={(choice) => setDomains((current) => ({ ...current, [entry.service]: choice }))}
                                ariaLabel={`${entry.service} domain`}
                            />
                        )}
                    </Field>
                ))}
            </fieldset>

            <Errors errors={other} />

            <div className="flex items-center justify-between gap-3">
                <span className="text-fg-faint inline-flex items-center gap-1.5 text-xs">
                    {template.stateful && (
                        <>
                            <TriangleAlert className="size-3.5" aria-hidden /> Keeps data in volumes
                        </>
                    )}
                </span>
                <Button
                    variant="primary"
                    type="submit"
                    icon={<Rocket />}
                    loading={submitting}
                    disabled={!target || !detail.data || selected.length === 0 || !name.trim()}
                >
                    Deploy
                </Button>
            </div>
        </form>
    );
}
