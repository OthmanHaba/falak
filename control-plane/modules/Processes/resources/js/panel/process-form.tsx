import { Button, Checkbox, Field, IconButton, Input, Select, Switch } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { Plus, Trash2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { type EnvRow, type ProcessServer } from '../types';
import { endpoints, type CronConfig, type DaemonConfig, type ItemKind, type ProcessesData, type WorkerConfig } from './api';

type Editable = Extract<ItemKind, 'worker' | 'daemon' | 'cron'>;
type Values = Record<string, unknown>;

function workerDefaults(): WorkerConfig {
    return {
        connection: null,
        queue: 'default',
        command: null,
        processes: 1,
        timeout: 60,
        sleep: 3,
        tries: 3,
        backoff: null,
        max_jobs: null,
        max_time: 3600,
        memory: 256,
        env: [],
        server_ids: [],
    };
}

function daemonDefaults(data: ProcessesData): DaemonConfig {
    return {
        name: '',
        command: '',
        directory: data.defaults.directory,
        user: null,
        instances: 1,
        restart: data.options.restart[0] ?? 'always',
        stop_signal: data.options.stop_signals[0] ?? 'TERM',
        stop_timeout: 10,
        env: [],
        server_ids: [],
    };
}

function cronDefaults(data: ProcessesData): CronConfig {
    return {
        name: '',
        command: data.defaults.php ? `${data.defaults.php} artisan ` : '',
        expression: '*/5 * * * *',
        timezone: null,
        user: null,
        overlap: 'skip',
        timeout: 3600,
        heartbeat: true,
        enabled: true,
        all_servers: false,
    };
}

function EnvRows({ rows, onChange, error }: { rows: EnvRow[]; onChange: (rows: EnvRow[]) => void; error?: string }) {
    const update = (index: number, patch: Partial<EnvRow>) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    return (
        <div className="grid gap-1.5 sm:col-span-2">
            <span className="text-fg text-xs font-medium">Environment</span>
            {rows.map((row, index) => (
                <div key={index} className="flex gap-1.5">
                    <Input
                        mono
                        value={row.key}
                        aria-label="Variable name"
                        placeholder="KEY"
                        onChange={(event) => update(index, { key: event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_') })}
                    />
                    <Input
                        mono
                        value={row.value ?? ''}
                        aria-label="Variable value"
                        placeholder={row.value === null ? '(unchanged)' : 'value'}
                        onChange={(event) => update(index, { value: event.target.value })}
                    />
                    <IconButton label="Remove variable" icon={<Trash2 />} onClick={() => onChange(rows.filter((_, i) => i !== index))} />
                </div>
            ))}
            <div>
                <Button size="sm" variant="ghost" icon={<Plus />} onClick={() => onChange([...rows, { key: '', value: '' }])}>
                    Add variable
                </Button>
            </div>
            {error && <p className="text-danger text-xs">{error}</p>}
        </div>
    );
}

function ServerChoice({ servers, value, onChange }: { servers: ProcessServer[]; value: string[]; onChange: (ids: string[]) => void }) {
    if (servers.length < 2) return null;

    return (
        <div className="grid gap-1.5 sm:col-span-2">
            <span className="text-fg text-xs font-medium">Servers</span>
            <div className="flex flex-wrap gap-4">
                {servers.map((server) => (
                    <label key={server.id} className="text-fg flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={value.includes(server.id)}
                            onCheckedChange={(checked) => onChange(checked === true ? [...value, server.id] : value.filter((id) => id !== server.id))}
                        />
                        {server.name}
                        {server.role === 'leader' && <span className="text-fg-faint text-xs">leader</span>}
                    </label>
                ))}
            </div>
            <p className="text-fg-faint text-xs">None checked = every server of the site.</p>
        </div>
    );
}

function firstError(errors: Record<string, string>, prefix: string): string | undefined {
    return Object.entries(errors).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];
}

const num = (value: string) => (value === '' ? null : Number(value.replace(/\D/g, '')));

/** Inline create / edit form of a queue worker, daemon or cron job (saved through the Processes endpoints). */
export function ProcessForm({
    kind,
    id,
    initial,
    data,
    onDone,
    onCancel,
}: {
    kind: Editable;
    id: string | null;
    initial?: WorkerConfig | DaemonConfig | CronConfig;
    data: ProcessesData;
    onDone: (message: string) => void;
    onCancel: () => void;
}) {
    const [values, setValues] = useState<Values>(
        () => ({ ...(initial ?? (kind === 'worker' ? workerDefaults() : kind === 'daemon' ? daemonDefaults(data) : cronDefaults(data))) }) as Values,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const set = (key: string, value: unknown) => setValues((current) => ({ ...current, [key]: value }));
    const str = (key: string) => (values[key] === null || values[key] === undefined ? '' : String(values[key]));

    const text = (key: string, label: ReactNode, props: { hint?: ReactNode; mono?: boolean; placeholder?: string; wide?: boolean } = {}) => (
        <Field label={label} hint={props.hint} error={firstError(errors, key)} className={props.wide ? 'sm:col-span-2' : undefined}>
            <Input
                mono={props.mono}
                value={str(key)}
                placeholder={props.placeholder}
                onChange={(event) => set(key, event.target.value === '' ? null : event.target.value)}
            />
        </Field>
    );
    const number = (key: string, label: ReactNode, hint?: ReactNode) => (
        <Field label={label} hint={hint} error={firstError(errors, key)}>
            <Input inputMode="numeric" mono value={str(key)} onChange={(event) => set(key, num(event.target.value))} />
        </Field>
    );

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        const base = endpoints[kind]?.(data.site.id) ?? '';
        try {
            await requestJson(id ? `${base}/${id}` : base, id ? 'PUT' : 'POST', values);
            onDone(id ? 'Saved — the servers are being updated' : 'Created — the servers are being updated');
        } catch (e) {
            setErrors(e instanceof HttpError ? { ...e.errors, form: Object.keys(e.errors).length ? '' : e.message } : { form: errorMessage(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            onKeyDown={(event) => event.stopPropagation()}
            className="bg-surface-2/40 grid gap-4 p-4"
            aria-label={`${id ? 'Edit' : 'New'} ${kind}`}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                {kind === 'worker' && (
                    <>
                        {text('queue', 'Queues', { mono: true, placeholder: 'default', hint: 'Comma separated, highest priority first.' })}
                        {text('connection', 'Connection', { mono: true, placeholder: 'default connection' })}
                        {number('processes', 'Processes')}
                        {number('memory', 'Memory limit (MB)')}
                        {number('timeout', 'Timeout (s)')}
                        {number('tries', 'Tries')}
                        {number('sleep', 'Sleep (s)')}
                        {number('max_time', 'Max time (s)', 'Restart the worker after this long.')}
                        {text('command', 'Custom command', {
                            mono: true,
                            wide: true,
                            placeholder: data.laravel.available ? `${data.defaults.php ?? 'php'} artisan queue:work …` : 'node worker.js',
                            hint: data.laravel.available ? 'Leave empty to run queue:work with the options above.' : 'Required outside Laravel.',
                        })}
                        <ServerChoice
                            servers={data.servers}
                            value={(values.server_ids as string[]) ?? []}
                            onChange={(ids) => set('server_ids', ids)}
                        />
                        <EnvRows rows={(values.env as EnvRow[]) ?? []} onChange={(rows) => set('env', rows)} error={firstError(errors, 'env')} />
                    </>
                )}
                {kind === 'daemon' && (
                    <>
                        {text('name', 'Name', { placeholder: 'reverb' })}
                        {number('instances', 'Instances')}
                        {text('command', 'Command', { mono: true, wide: true, placeholder: 'php artisan reverb:start' })}
                        {text('directory', 'Directory', { mono: true, placeholder: data.defaults.directory })}
                        {text('user', 'User', { mono: true, placeholder: data.defaults.user })}
                        <Field label="Restart" error={firstError(errors, 'restart')}>
                            <Select
                                value={str('restart')}
                                onValueChange={(value) => set('restart', value)}
                                options={data.options.restart.map((value) => ({ value, label: value }))}
                            />
                        </Field>
                        <Field label="Stop signal" error={firstError(errors, 'stop_signal')}>
                            <Select
                                value={str('stop_signal')}
                                onValueChange={(value) => set('stop_signal', value)}
                                options={data.options.stop_signals.map((value) => ({ value, label: value }))}
                            />
                        </Field>
                        {number('stop_timeout', 'Stop timeout (s)')}
                        <ServerChoice
                            servers={data.servers}
                            value={(values.server_ids as string[]) ?? []}
                            onChange={(ids) => set('server_ids', ids)}
                        />
                        <EnvRows rows={(values.env as EnvRow[]) ?? []} onChange={(rows) => set('env', rows)} error={firstError(errors, 'env')} />
                    </>
                )}
                {kind === 'cron' && (
                    <>
                        {text('name', 'Name', { placeholder: 'Prune backups' })}
                        <Field label="Schedule" error={firstError(errors, 'expression')} hint="5-field cron, @hourly… or @every 5m">
                            <div className="flex gap-1.5">
                                <Input mono value={str('expression')} onChange={(event) => set('expression', event.target.value)} />
                                <Select
                                    size="md"
                                    className="w-32"
                                    aria-label="Presets"
                                    value={undefined}
                                    placeholder="Presets"
                                    onValueChange={(value) => set('expression', value)}
                                    options={data.options.presets.map((preset) => ({ value: preset.value, label: preset.label }))}
                                />
                            </div>
                        </Field>
                        {text('command', 'Command', { mono: true, wide: true })}
                        {text('user', 'User', { mono: true, placeholder: data.defaults.user })}
                        {number('timeout', 'Timeout (s)')}
                        <Field label="Overlapping runs" error={firstError(errors, 'overlap')}>
                            <Select
                                value={str('overlap')}
                                onValueChange={(value) => set('overlap', value)}
                                options={[
                                    { value: 'skip', label: 'Skip while running' },
                                    { value: 'allow', label: 'Allow' },
                                ]}
                            />
                        </Field>
                        <Field label="Timezone" error={firstError(errors, 'timezone')}>
                            <Select
                                value={str('timezone') || 'UTC'}
                                onValueChange={(value) => set('timezone', value === 'UTC' ? null : value)}
                                options={data.options.timezones.map((value) => ({ value, label: value }))}
                            />
                        </Field>
                        <div className="grid gap-2 sm:col-span-2">
                            <Field inline label="Report heartbeats (alert on missed or failed runs)">
                                <Switch checked={Boolean(values.heartbeat)} onCheckedChange={(on) => set('heartbeat', on)} />
                            </Field>
                            <Field inline label="Run on every server (default: the leader only)">
                                <Switch checked={Boolean(values.all_servers)} onCheckedChange={(on) => set('all_servers', on)} />
                            </Field>
                            <Field inline label="Enabled">
                                <Switch checked={Boolean(values.enabled)} onCheckedChange={(on) => set('enabled', on)} />
                            </Field>
                        </div>
                    </>
                )}
            </div>
            {errors.form && <p className="text-danger text-xs">{errors.form}</p>}
            <div className="flex justify-end gap-2">
                <Button size="sm" variant="ghost" onClick={onCancel} disabled={saving}>
                    Cancel
                </Button>
                <Button size="sm" variant="primary" type="submit" loading={saving}>
                    {id ? 'Save' : 'Create'}
                </Button>
            </div>
        </form>
    );
}
