import { ConfirmDestructive, toast } from '@/components/kiln';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceActionDialogProps } from '@/lib/registry';
import { useState } from 'react';

/** Header `⋯` → Delete: type the service name; the site / database is deleted by its module. */
export function DeleteServiceDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const [error, setError] = useState<string | undefined>();
    const { service } = ctx;

    return (
        <ConfirmDestructive
            open={open}
            onOpenChange={onOpenChange}
            title={`Delete ${service.name}?`}
            description={
                service.kind === 'site'
                    ? 'The site is removed from its servers with its releases, domains, processes and variables. This cannot be undone.'
                    : 'The database is dropped on its server with all of its data. Backups in storage are kept. This cannot be undone.'
            }
            confirmText={service.name}
            confirmLabel={service.kind === 'site' ? 'Delete service' : 'Drop database'}
            error={error}
            onConfirm={async (confirm) => {
                try {
                    await requestJson(`${ctx.canvasUrl}/services/${service.id}`, 'DELETE', { confirm });
                    toast.success(`${service.name} ${service.kind === 'site' ? 'deleted' : 'is being dropped'}`);
                    onOpenChange(false);
                    ctx.close();
                } catch (e) {
                    setError(e instanceof HttpError ? (e.errors.confirm ?? e.message) : errorMessage(e));
                }
            }}
        />
    );
}
