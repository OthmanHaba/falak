import { Button, Checkbox, Field, IconButton, Input, Select, SkeletonRows, Tag, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Link2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface PathsState {
    mounts: { id: string; site_id: string; site_name: string | null; path_prefix: string; strip_prefix: boolean; urls: string[] }[];
    sites: { id: string; name: string; domains: string[] }[];
    can: { manage: boolean };
}

/**
 * Settings → Paths of a function: serve it on a path of another site (`app.example.com/api/*`), next to that site's
 * own pages. Edge (Caddy) routes the path to the function.
 */
export function PathsSettings({ ctx }: ServiceTabProps) {
    const url = `/sites/${ctx.service.ref_id}/function-mounts`;
    const state = useJson<PathsState>(url);
    const [siteId, setSiteId] = useState<string | undefined>();
    const [path, setPath] = useState('/api');
    const [strip, setStrip] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);

    if (!state.data) return <SkeletonRows rows={2} />;
    const { mounts, sites, can } = state.data;

    const add = async () => {
        if (!siteId) return;
        setAdding(true);
        setErrors({});
        try {
            await requestJson(url, 'POST', { site_id: siteId, path_prefix: path, strip_prefix: strip });
            toast.success(`Serving ${path}`, 'The site’s servers are updated in a few seconds.');
            await state.reload();
        } catch (e) {
            if (e instanceof HttpError && Object.keys(e.errors).length > 0) setErrors(e.errors);
            else toast.error('Could not add the path', errorMessage(e));
        } finally {
            setAdding(false);
        }
    };

    const remove = async (id: string, prefix: string) => {
        try {
            await requestJson(`${url}/${id}`, 'DELETE');
            toast.success(`${prefix} removed`);
            await state.reload();
        } catch (e) {
            toast.error('Could not remove the path', errorMessage(e));
        }
    };

    return (
        <div className="grid gap-3">
            <p className="text-fg-muted text-xs">
                Serve this function on a path of another site, e.g. <code className="text-fg">shop.example.com/api/*</code>. Requests for that path go
                to the function; everything else stays with the site. The site’s own IP rules and basic auth still apply.
            </p>

            {mounts.length > 0 && (
                <ul className="border-border divide-border divide-y rounded-md border">
                    {mounts.map((mount) => (
                        <li key={mount.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                            <Link2 className="text-fg-muted size-4" aria-hidden />
                            <span className="text-fg font-medium">{mount.site_name ?? mount.site_id}</span>
                            <span className="text-fg font-mono">{mount.path_prefix}/*</span>
                            {mount.strip_prefix && <Tag tone="neutral">prefix stripped</Tag>}
                            <span className="text-fg-faint min-w-0 truncate text-xs">{mount.urls[0] ?? 'the site has no domain yet'}</span>
                            {can.manage && (
                                <IconButton
                                    size="sm"
                                    className="ml-auto"
                                    label={`Remove ${mount.path_prefix}`}
                                    icon={<Trash2 />}
                                    onClick={() => remove(mount.id, mount.path_prefix)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can.manage && (
                <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_160px_auto] sm:items-end">
                    <Field label="Site" error={errors.site_id}>
                        <Select
                            value={siteId}
                            placeholder="Pick a site"
                            onValueChange={setSiteId}
                            options={sites.map((s) => ({ value: s.id, label: s.domains[0] ? `${s.name} (${s.domains[0]})` : s.name }))}
                            aria-label="Site"
                        />
                    </Field>
                    <Field label="Path" error={errors.path_prefix}>
                        <Input className="font-mono" value={path} maxLength={200} onChange={(e) => setPath(e.target.value)} />
                    </Field>
                    <Button icon={<Plus />} loading={adding} disabled={!siteId} onClick={add}>
                        Add path
                    </Button>
                    <label className="text-fg-muted flex items-center gap-2 text-xs sm:col-span-3">
                        <Checkbox checked={strip} onCheckedChange={(v) => setStrip(v === true)} />
                        Strip the path: the function sees <code className="text-fg">/users</code> for{' '}
                        <code className="text-fg">{path || '/api'}/users</code>
                    </label>
                </div>
            )}
        </div>
    );
}
