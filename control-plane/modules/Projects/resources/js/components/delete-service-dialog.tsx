import { Checkbox, ConfirmDestructive, toast } from '@/components/kiln';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceActionDialogProps } from '@/lib/registry';
import { useEffect, useId, useState } from 'react';

/** Header `⋯` → Delete: type the service name; the site / database is deleted by its module. */
export function DeleteServiceDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const [error, setError] = useState<string | undefined>();
    const [deleteVolumes, setDeleteVolumes] = useState(false);
    const volumesId = useId();
    const { service } = ctx;
    // Compose sites keep named volumes (their data) unless asked; docker sites mount host paths only.
    const hasVolumes = service.kind === 'site' && !!service.compose;

    useEffect(() => {
        if (!open) setDeleteVolumes(false);
    }, [open]);

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
                        ...(hasVolumes ? { delete_volumes: deleteVolumes } : {}),
                    });
                    toast.success(`${service.name} ${service.kind === 'site' ? 'deleted' : 'is being dropped'}`);
                    onOpenChange(false);
                    ctx.close();
                } catch (e) {
                    setError(e instanceof HttpError ? (e.errors.confirm ?? e.message) : errorMessage(e));
                }
            }}
        >
            {hasVolumes && (
                <label htmlFor={volumesId} className="text-fg-muted flex items-start gap-2 text-sm">
                    <Checkbox
                        id={volumesId}
                        checked={deleteVolumes}
                        onCheckedChange={(checked) => setDeleteVolumes(checked === true)}
                        className="mt-0.5"
                    />
                    <span>
                        <span className="text-fg">Also delete data volumes.</span> Otherwise the containers are removed and their volumes stay on the
                        server.
                    </span>
                </label>
            )}
        </ConfirmDestructive>
    );
}
