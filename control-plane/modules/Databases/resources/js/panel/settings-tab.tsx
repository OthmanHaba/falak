import { Button, ConfirmDestructive, Field, Input, Section, Select, SkeletonRows, toast } from '@/components/kiln';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useDatabasePanel } from './api';

const AUTO = 'auto';

/** §5.4 Settings: engine version / port of the engine server, and dropping the database. */
export function DatabaseSettingsTab({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDatabasePanel(ctx);
    const [version, setVersion] = useState(AUTO);
    const [port, setPort] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | undefined>();

    useEffect(() => {
        if (!data) return;
        setVersion(data.server.version_source === 'manual' && data.server.version ? data.server.version : AUTO);
        setPort(String(data.server.port));
    }, [data]);

    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await requestJson(`/databases/servers/${data.server.id}`, 'PUT', { version: version === AUTO ? null : version, port: Number(port) });
            toast.success('Engine settings saved');
            await reload();
        } catch (e) {
            setErrors(e instanceof HttpError ? e.errors : { form: errorMessage(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="grid gap-8">
            <Section
                title="Engine"
                description={`${data.server.engine_label} on ${data.server.server_name}. These settings apply to every database on this engine server.`}
                footer={
                    data.can.manage && (
                        <Button variant="primary" type="submit" form="engine-settings" loading={saving}>
                            Save
                        </Button>
                    )
                }
            >
                <form id="engine-settings" onSubmit={save} className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Version"
                        hint={data.server.version_source === 'facts' ? `Detected ${data.server.version ?? 'unknown'} from the server.` : undefined}
                        error={errors.version}
                    >
                        <Select
                            value={version}
                            onValueChange={setVersion}
                            disabled={!data.can.manage}
                            options={[{ value: AUTO, label: 'Auto-detect' }, ...data.options.versions.map((item) => ({ value: item, label: item }))]}
                        />
                    </Field>
                    <Field label="Port" error={errors.port}>
                        <Input
                            value={port}
                            onChange={(event) => setPort(event.target.value.replace(/\D/g, ''))}
                            inputMode="numeric"
                            mono
                            disabled={!data.can.manage}
                        />
                    </Field>
                    {errors.form && <p className="text-danger text-xs sm:col-span-2">{errors.form}</p>}
                </form>
            </Section>

            {data.can.manage && (
                <Section title="Danger zone" tone="danger">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="grid gap-0.5">
                            <span className="text-fg text-sm font-medium">Drop this database</span>
                            <span className="text-fg-muted text-sm">All data in {data.database.name} is deleted. Backups in storage are kept.</span>
                        </div>
                        <Button variant="danger" icon={<Trash2 />} onClick={() => setDeleting(true)}>
                            Drop database
                        </Button>
                    </div>
                </Section>
            )}

            <ConfirmDestructive
                open={deleting}
                onOpenChange={setDeleting}
                title={`Drop ${data.database.name}?`}
                description="The database and its data are deleted on the server. This cannot be undone."
                confirmText={data.database.name}
                confirmLabel="Drop database"
                error={deleteError}
                onConfirm={async (confirm) => {
                    try {
                        await requestJson(`/databases/databases/${data.database.id}`, 'DELETE', { confirm });
                        toast.success(`Dropping ${data.database.name}`);
                        setDeleting(false);
                        ctx.close();
                    } catch (e) {
                        setDeleteError(errorMessage(e));
                    }
                }}
            />
        </div>
    );
}
