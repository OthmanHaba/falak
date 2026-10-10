import { Field, Input, Select } from '@/components/falak';

/** A service's resource limits (Limits' ResourceLimits JSON): memory in MB, CPUs in cores; unset keys are absent. */
export interface ResourceLimits {
    memory_limit?: number | null;
    memory_reservation?: number | null;
    cpus?: number | null;
    pids_limit?: number | null;
    restart_policy?: 'always' | 'unless-stopped' | 'on-failure' | null;
    max_restarts?: number | null;
    log_max_size?: number | null;
    log_max_files?: number | null;
    oom?: 'protect' | 'normal' | null;
}

export const RESTART_POLICIES = ['always', 'unless-stopped', 'on-failure'] as const;

/** "512 MB · 1.5 CPUs" (null without memory or CPU limits), like the canvas card. */
export function limitsSummary(limits: ResourceLimits | null | undefined): string | null {
    if (!limits) return null;
    const parts = [
        limits.memory_limit ? memoryLabel(limits.memory_limit) : null,
        limits.cpus ? `${Number(limits.cpus)} ${Number(limits.cpus) === 1 ? 'CPU' : 'CPUs'}` : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' · ') : null;
}

export function memoryLabel(mb: number): string {
    return mb >= 1024 && mb % 1024 === 0 ? `${mb / 1024} GB` : `${mb} MB`;
}

/** Drops unset values: what is sent (an empty object clears the limits). */
export function cleanLimits(limits: ResourceLimits): ResourceLimits {
    return Object.fromEntries(Object.entries(limits).filter(([, value]) => value !== null && value !== undefined && value !== '')) as ResourceLimits;
}

const integer = (value: string) => (value.trim() === '' ? null : Number(value.replace(/\D/g, '')));
const decimal = (value: string) => {
    const cleaned = value.replace(/[^0-9.]/g, '');

    return cleaned === '' ? null : Number(cleaned);
};

/**
 * Limits fields of a service form (site, compose service, worker, daemon). `defaults` are what applies when a value
 * is left empty (the environment's), shown as placeholders; `policyOnly` hides memory / CPUs / processes (FrankenPHP
 * sites run inside the shared edge). `errors` are keyed `<prefix>.<key>`.
 */
export function LimitsFields({
    value,
    onChange,
    errors = {},
    prefix = 'limits',
    defaults = {},
    bounds,
    policyOnly = false,
    disabled = false,
}: {
    value: ResourceLimits;
    onChange: (value: ResourceLimits) => void;
    errors?: Record<string, string>;
    prefix?: string;
    defaults?: ResourceLimits;
    bounds?: { memory_mb: number | null; cpus: number | null };
    policyOnly?: boolean;
    disabled?: boolean;
}) {
    const set = (key: keyof ResourceLimits, next: unknown) => onChange({ ...value, [key]: next });
    const error = (key: keyof ResourceLimits) => errors[`${prefix}.${key}`];
    const text = (key: keyof ResourceLimits) => (value[key] === null || value[key] === undefined ? '' : String(value[key]));
    const placeholder = (key: keyof ResourceLimits, none = 'none') =>
        defaults[key] !== undefined && defaults[key] !== null ? `default ${defaults[key]}` : none;

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {!policyOnly && (
                <>
                    <Field
                        label="Memory limit (MB)"
                        error={error('memory_limit')}
                        hint={
                            bounds?.memory_mb
                                ? `The server has ${memoryLabel(bounds.memory_mb)}. Over it, the service is OOM-killed.`
                                : 'Over it, the service is OOM-killed.'
                        }
                    >
                        <Input
                            inputMode="numeric"
                            mono
                            disabled={disabled}
                            value={text('memory_limit')}
                            placeholder={placeholder('memory_limit', 'unlimited')}
                            onChange={(event) => set('memory_limit', integer(event.target.value))}
                        />
                    </Field>
                    <Field label="Memory reservation (MB)" error={error('memory_reservation')} hint="Kept for it when the server is short of memory.">
                        <Input
                            inputMode="numeric"
                            mono
                            disabled={disabled}
                            value={text('memory_reservation')}
                            placeholder={placeholder('memory_reservation')}
                            onChange={(event) => set('memory_reservation', integer(event.target.value))}
                        />
                    </Field>
                    <Field
                        label="CPUs"
                        error={error('cpus')}
                        hint={bounds?.cpus ? `Cores, e.g. 0.5 or 2 (the server has ${bounds.cpus}).` : 'Cores, e.g. 0.5 or 2.'}
                    >
                        <Input
                            inputMode="decimal"
                            mono
                            disabled={disabled}
                            value={text('cpus')}
                            placeholder={placeholder('cpus', 'unlimited')}
                            onChange={(event) => set('cpus', decimal(event.target.value))}
                        />
                    </Field>
                    <Field label="Processes" error={error('pids_limit')} hint="Most processes and threads (stops fork bombs).">
                        <Input
                            inputMode="numeric"
                            mono
                            disabled={disabled}
                            value={text('pids_limit')}
                            placeholder={placeholder('pids_limit', 'unlimited')}
                            onChange={(event) => set('pids_limit', integer(event.target.value))}
                        />
                    </Field>
                </>
            )}
            <Field label="Restart policy" error={error('restart_policy')}>
                <Select
                    disabled={disabled}
                    value={value.restart_policy ?? 'default'}
                    onValueChange={(next) =>
                        onChange({
                            ...value,
                            restart_policy: next === 'default' ? null : (next as ResourceLimits['restart_policy']),
                            ...(next !== 'on-failure' ? { max_restarts: null } : {}),
                        })
                    }
                    options={[{ value: 'default', label: 'Default' }, ...RESTART_POLICIES.map((policy) => ({ value: policy, label: policy }))]}
                />
            </Field>
            {value.restart_policy === 'on-failure' && (
                <Field label="Max restarts" error={error('max_restarts')} hint="Then it stays down (and alerts).">
                    <Input
                        inputMode="numeric"
                        mono
                        disabled={disabled}
                        value={text('max_restarts')}
                        placeholder="unlimited"
                        onChange={(event) => set('max_restarts', integer(event.target.value))}
                    />
                </Field>
            )}
            <Field label="Log size (MB)" error={error('log_max_size')} hint="Per file, rotated.">
                <Input
                    inputMode="numeric"
                    mono
                    disabled={disabled}
                    value={text('log_max_size')}
                    placeholder={placeholder('log_max_size', 'unlimited')}
                    onChange={(event) => set('log_max_size', integer(event.target.value))}
                />
            </Field>
            <Field label="Log files" error={error('log_max_files')}>
                <Input
                    inputMode="numeric"
                    mono
                    disabled={disabled}
                    value={text('log_max_files')}
                    placeholder={placeholder('log_max_files', '1')}
                    onChange={(event) => set('log_max_files', integer(event.target.value))}
                />
            </Field>
            <Field label="When the server runs out of memory" error={error('oom')}>
                <Select
                    disabled={disabled}
                    value={value.oom ?? 'normal'}
                    onValueChange={(next) => set('oom', next === 'normal' ? null : next)}
                    options={[
                        { value: 'normal', label: 'Normal' },
                        { value: 'protect', label: 'Protect (killed last)' },
                    ]}
                />
            </Field>
        </div>
    );
}
