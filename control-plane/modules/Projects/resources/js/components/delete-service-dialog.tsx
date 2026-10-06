import { Checkbox, ConfirmDestructive, toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceActionDialogProps } from '@/lib/registry';
import { Lock } from 'lucide-react';
import { useEffect, useState } from 'react';

/** The volumes GET /sites/{id}/volumes lists (only what this dialog needs). */
interface SiteVolume {
    id: string;
    name: string;
    kind: string;
    protected: boolean;
}

/**
 * Header `⋯` → Delete: type the service name; the site / database is deleted by its module. A site's volumes are kept
 * unless picked one by one (protected ones never go; shared paths stay on the servers with the site's files).
 */
export function DeleteServiceDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const [error, setError] = useState<string | undefined>();
    const [volumes, setVolumes] = useState<SiteVolume[]>([]);
    const [picked, setPicked] = useState<string[]>([]);
    const { service } = ctx;

    useEffect(() => {
        setPicked([]);
        setError(undefined);
        if (!open || service.kind !== 'site' || !ctx.can('volumes.view')) {
            setVolumes([]);

            return;
        }

        const controller = new AbortController();
        requestJson<{ data: { volumes: SiteVolume[] } }>(`/sites/${service.ref_id}/volumes`, 'GET', undefined, { signal: controller.signal })
            .then((response) => setVolumes(response.data.volumes.filter((volume) => volume.kind !== 'shared_path')))
            .catch(() => setVolumes([]));

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, service.kind, service.ref_id]);

    return (
        <ConfirmDestructive
            open={open}
            onOpenChange={onOpenChange}
            title={`Delete ${service.name}?`}
            description={
                service.kind === 'site'
                    ? 'The site is removed from its servers with its containers, releases, domains, processes and variables. This cannot be undone.'
                    : 'The database is dropped on its server with all of its data. Backups in storage are kept. This cannot be undone.'
            }
            confirmText={service.name}
            confirmLabel={service.kind === 'site' ? 'Delete service' : 'Drop database'}
            error={error}
            onConfirm={async (confirm) => {
                try {
                    await requestJson(`${ctx.canvasUrl}/services/${service.id}`, 'DELETE', {
                        confirm,
                        ...(service.kind === 'site' ? { delete_volumes: picked } : {}),
                    });
                    toast.success(`${service.name} ${service.kind === 'site' ? 'deleted' : 'is being dropped'}`);
                    onOpenChange(false);
                    ctx.close();
                } catch (e) {
                    setError(e instanceof HttpError ? (e.errors.confirm ?? e.errors.delete_volumes ?? e.message) : errorMessage(e));
                }
            }}
        >
            {volumes.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="text-fg mb-1 text-sm">Also delete these volumes</legend>
                    <p className="text-fg-muted text-xs">Unchecked volumes and their data stay on the server.</p>
                    {volumes.map((volume) => (
                        <label key={volume.id} className="text-fg-muted flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={picked.includes(volume.id)}
                                disabled={volume.protected}
                                onCheckedChange={(checked) =>
                                    setPicked((current) => (checked === true ? [...current, volume.id] : current.filter((id) => id !== volume.id)))
                                }
                            />
                            <span className="text-fg font-mono text-xs">{volume.name}</span>
                            {volume.protected && (
                                <span className="text-fg-faint flex items-center gap-1 text-xs">
                                    <Lock className="size-3" aria-hidden /> protected
                                </span>
                            )}
                        </label>
                    ))}
                </fieldset>
            )}
        </ConfirmDestructive>
    );
}
