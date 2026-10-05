import { Button, ConfirmDestructive, Field, Input, Section, Select, SkeletonRows, toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useDatabasePanel } from './api';

const AUTO = 'auto';

const PERSISTENCE: Record<string, string> = {
    rdb: 'Snapshots (RDB)',
    aof: 'Append-only file (AOF, fsync every second)',
    none: 'None (memory only)',
};

/** §5.4 Settings: engine version / port of the engine server, and dropping the database. */
export function DatabaseSettingsTab({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDatabasePanel(ctx);
    const [version, setVersion] = useState(AUTO);
    const [port, setPort] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | undefined>();
    const [memory, setMemory] = useState('');
    const [eviction, setEviction] = useState('noeviction');
    const [persistence, setPersistence] = useState('rdb');
    const [instanceErrors, setInstanceErrors] = useState<Record<string, string>>({});
    const [savingInstance, setSavingInstance] = useState(false);

    useEffect(() => {
        if (!data) return;
        setVersion(data.server.version_source === 'manual' && data.server.version ? data.server.version : AUTO);
        setPort(String(data.server.port));
        if (data.database.settings) {
            setMemory(String(data.database.settings.maxmemory_mb));
            setEviction(data.database.settings.eviction);
            setPersistence(data.database.settings.persistence);
        }
    }, [data]);

    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const keyValue = data.server.kind === 'key_value';
    const noun = keyValue ? 'instance' : 'database';

    const saveInstance = async (event: FormEvent) => {
        event.preventDefault();
        setSavingInstance(true);
        setInstanceErrors({});
        try {
            await requestJson(`/databases/databases/${data.database.id}/settings`, 'PUT', {
                maxmemory_mb: Number(memory) || null,
                eviction,
                persistence,
            });
            toast.success('Instance settings saved', 'Applied to the running instance, without a restart.');
            await reload();
        } catch (e) {
            setInstanceErrors(e instanceof HttpError ? e.errors : { form: errorMessage(e) });
        } finally {
            setSavingInstance(false);
        }
    };

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await requestJson(`/databases/servers/${data.server.id}`, 'PUT', {
                version: version === AUTO ? null : version,
                port: keyValue ? null : Number(port),
            });
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
            {keyValue && (
                <Section
                    title="Instance"
                    description={`${data.database.name} runs as its own ${data.server.engine_label} process on port ${data.database.port ?? '—'}. Changes apply to the running instance without a restart; switching between snapshots and AOF keeps the data.`}
                    footer={
                        data.can.manage && (
                            <Button variant="primary" type="submit" form="instance-settings" loading={savingInstance}>
                                Save
                            </Button>
                        )
                    }
                >
                    <form id="instance-settings" onSubmit={saveInstance} className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Memory limit (MB)"
                            hint={data.options.max_memory_mb ? `Up to ${data.options.max_memory_mb} MB on this server.` : undefined}
                            error={instanceErrors.maxmemory_mb}
                        >
                            <Input
                                value={memory}
                                onChange={(event) => setMemory(event.target.value.replace(/\D/g, ''))}
                                inputMode="numeric"
                                mono
                                disabled={!data.can.manage}
                            />
                        </Field>
                        <Field
                            label="Eviction"
                            hint="noeviction suits queues and sessions; allkeys-lru suits caches."
                            error={instanceErrors.eviction}
                        >
                            <Select
                                value={eviction}
                                onValueChange={setEviction}
                                disabled={!data.can.manage}
                                options={data.options.evictions.map((item) => ({ value: item, label: item }))}
                            />
                        </Field>
                        <Field label="Persistence" error={instanceErrors.persistence}>
                            <Select
                                value={persistence}
                                onValueChange={setPersistence}
                                disabled={!data.can.manage}
                                options={data.options.persistences.map((item) => ({ value: item, label: PERSISTENCE[item] ?? item }))}
                            />
                        </Field>
                        {persistence === 'none' && (
                            <p role="alert" className="bg-warning-soft text-warning rounded-md px-3 py-2 text-xs sm:col-span-2">
                                Nothing is written to disk: every restart of the instance (server reboot, a port change, an upgrade) starts it empty.
                                {data.database.settings?.persistence !== 'none' && ' Files of the current mode are moved aside.'}
                            </p>
                        )}
                        {instanceErrors.form && <p className="text-danger text-xs sm:col-span-2">{instanceErrors.form}</p>}
                    </form>
                </Section>
            )}

            <Section
                title="Engine"
                description={
                    keyValue
                        ? `${data.server.engine_label} on ${data.server.server_name}. The version applies to every instance on this server.`
                        : `${data.server.engine_label} on ${data.server.server_name}. These settings apply to every database on this engine server.`
                }
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
                    {!keyValue && (
                        <Field label="Port" error={errors.port}>
                            <Input
                                value={port}
                                onChange={(event) => setPort(event.target.value.replace(/\D/g, ''))}
                                inputMode="numeric"
                                mono
                                disabled={!data.can.manage}
                            />
                        </Field>
                    )}
                    {errors.form && <p className="text-danger text-xs sm:col-span-2">{errors.form}</p>}
                </form>
            </Section>

            {data.can.manage && (
                <Section title="Danger zone" tone="danger">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="grid gap-0.5">
                            <span className="text-fg text-sm font-medium">{keyValue ? 'Delete this instance' : 'Drop this database'}</span>
                            <span className="text-fg-muted text-sm">
                                {keyValue
                                    ? `The ${data.server.engine_label} process, its configuration and all its data are removed from the server.`
                                    : `All data in ${data.database.name} is deleted. Backups in storage are kept.`}
                            </span>
                        </div>
                        <Button variant="danger" icon={<Trash2 />} onClick={() => setDeleting(true)}>
                            {keyValue ? 'Delete instance' : 'Drop database'}
                        </Button>
                    </div>
                </Section>
            )}

            <ConfirmDestructive
                open={deleting}
                onOpenChange={setDeleting}
                title={`${keyValue ? 'Delete' : 'Drop'} ${data.database.name}?`}
                description={`The ${noun} and its data are deleted on the server. This cannot be undone.`}
                confirmText={data.database.name}
                confirmLabel={keyValue ? 'Delete instance' : 'Drop database'}
                error={deleteError}
                onConfirm={async (confirm) => {
                    try {
                        await requestJson(`/databases/databases/${data.database.id}`, 'DELETE', { confirm });
                        toast.success(`${keyValue ? 'Deleting' : 'Dropping'} ${data.database.name}`);
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
