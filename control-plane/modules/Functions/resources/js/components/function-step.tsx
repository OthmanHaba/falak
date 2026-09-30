import { domainPayload, DomainPicker, type DomainChoice } from '@/components/domain-picker';
import { Button, Callout, Field, Input, Select, Skeleton, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, HttpError, requestJson } from '@/lib/http';
import { type CreateOptionProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type Canvas } from '@/types';
import { Link } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { type Starter } from '../types';

interface SiteOptions {
    options: { servers: { id: string; name: string; type_label: string; status: string; docker: boolean; functions?: boolean }[] };
}

function slugify(value: string): string {
    return (
        value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 50) || 'function'
    );
}

/**
 * Create picker → Function: name, server, starter and domain. The function is created with the starter as v1 and
 * deployed right away; its panel opens on the Code tab.
 */
export function FunctionStep({ projectId, environmentSlug, position, onCreated }: CreateOptionProps) {
    const sites = useJson<SiteOptions>('/sites/create');
    const starters = useJson<Starter[]>('/functions/starters');
    const [name, setName] = useState('');
    const [serverId, setServerId] = useState<string | null>(null);
    const [starter, setStarter] = useState('hello');
    const [domain, setDomain] = useState<DomainChoice | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    const servers = useMemo(() => (sites.data?.options.servers ?? []).filter((server) => server.docker), [sites.data]);
    const capable = servers.filter((server) => server.functions);
    const selected = serverId ?? capable.find((server) => server.status === 'active')?.id ?? capable[0]?.id ?? null;

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!selected) return;
        setSubmitting(true);
        setErrors({});
        try {
            const response = await requestJson<{
                data: { site_id: string; name: string; deployment_id: string | null; panel_url: string | null };
                warnings: string[];
            }>(`/projects/${projectId}/${environmentSlug}/functions`, 'POST', {
                name: name.trim(),
                server_id: selected,
                starter,
                domain: domainPayload(domain),
                position,
            });
            response.warnings.forEach((warning) => toast.warning(warning));
            const canvas = await requestJson<Canvas>(`/projects/${projectId}/${environmentSlug}/canvas`);
            const card = canvas.services.find((service) => service.kind === 'site' && service.ref_id === response.data.site_id);
            toast.success(`${response.data.name} created`, response.data.deployment_id ? 'First deploy started.' : undefined);
            if (card) onCreated(card, response.data.deployment_id);
            else if (response.data.panel_url) window.location.assign(response.data.panel_url);
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { form: errorMessage(error) });
        } finally {
            setSubmitting(false);
        }
    };

    if (!sites.data || !starters.data) return <Skeleton className="m-4 h-64" />;

    const shown = ['name', 'server_id', 'server_ids', 'starter', 'domain'];
    const other = Object.entries(errors)
        .filter(([key]) => !shown.includes(key))
        .map(([, message]) => message);

    return (
        <form onSubmit={submit} className="grid gap-4 p-4">
            <Field label="Name" error={errors.name}>
                <Input autoFocus value={name} placeholder="webhooks" maxLength={64} onChange={(event) => setName(event.target.value)} />
            </Field>

            <Field label="Starter" error={errors.starter}>
                <div className="grid gap-2 sm:grid-cols-3">
                    {starters.data.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => setStarter(option.key)}
                            aria-pressed={starter === option.key}
                            className={cn(
                                'border-border hover:border-border-strong grid gap-1 rounded-md border p-3 text-left',
                                starter === option.key && 'border-primary bg-primary-soft',
                            )}
                        >
                            <span className="text-fg text-sm font-medium">{option.title}</span>
                            <span className="text-fg-muted text-xs">{option.description}</span>
                        </button>
                    ))}
                </div>
            </Field>

            <Field
                label="Server"
                error={errors.server_id ?? errors.server_ids}
                hint="Bun + Hono. The function sleeps when idle and wakes on the first request."
            >
                {capable.length === 0 ? (
                    <Callout tone="warning" title="No server can run functions yet">
                        Functions need Docker and a Kiln agent with the function gateway (0.4 or newer).{' '}
                        {servers.length > 0 ? 'Update the agent on a Docker server.' : <Link href="/servers/create">Add a server</Link>}
                    </Callout>
                ) : (
                    <Select
                        value={selected ?? undefined}
                        onValueChange={setServerId}
                        options={capable.map((server) => ({
                            value: server.id,
                            label: `${server.name}${server.status !== 'active' ? ` (${server.status})` : ''}`,
                        }))}
                        aria-label="Server"
                    />
                )}
            </Field>

            {selected && (
                <Field label="Domain" error={errors.domain}>
                    <DomainPicker label={slugify(name)} serverIds={[selected]} value={domain} onChange={setDomain} ariaLabel="Domain" />
                </Field>
            )}

            {other.length > 0 && (
                <Callout tone="danger" title="Could not create the function">
                    {other.join(' ')}
                </Callout>
            )}

            <div className="flex justify-end">
                <Button type="submit" variant="primary" icon={<Rocket />} loading={submitting} disabled={!selected || !name.trim()}>
                    Create and deploy
                </Button>
            </div>
        </form>
    );
}
