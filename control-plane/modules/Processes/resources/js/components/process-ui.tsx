import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { Plus, Trash2 } from 'lucide-react';
import { type ApplyStatus, type EnvRow, type ProcessServer, type ProcessState } from '../types';

const STATE_STYLES: Record<string, string> = {
    running: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
    starting: 'border-sky-500/40 text-sky-700 dark:text-sky-400',
    stopping: 'border-sky-500/40 text-sky-700 dark:text-sky-400',
    backoff: 'border-amber-500/50 text-amber-700 dark:text-amber-400',
    fatal: 'border-destructive/50 text-destructive',
    exited: 'border-destructive/50 text-destructive',
};

export function StateBadge({ state }: { state: ProcessState | string }) {
    return (
        <Badge variant="outline" className={cn('font-mono text-[11px]', STATE_STYLES[state])}>
            {state}
        </Badge>
    );
}

const APPLY_LABELS: Record<ApplyStatus, string> = { pending: 'applying', applied: 'applied', failed: 'failed', error: 'not delivered' };

export function ApplyBadge({ status, error }: { status: ApplyStatus | null; error?: string | null }) {
    if (status === null) {
        return <span className="text-muted-foreground text-xs">not applied</span>;
    }

    return (
        <Badge
            variant={status === 'failed' || status === 'error' ? 'destructive' : status === 'pending' ? 'secondary' : 'outline'}
            title={error ?? undefined}
        >
            {APPLY_LABELS[status]}
        </Badge>
    );
}

/** Environment variables; stored values are never shown, an empty value field keeps them. */
export function EnvEditor({ rows, onChange, error }: { rows: EnvRow[]; onChange: (rows: EnvRow[]) => void; error?: string }) {
    const update = (index: number, patch: Partial<EnvRow>) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    return (
        <div className="space-y-2">
            <Label>Environment</Label>
            {rows.map((row, index) => (
                <div key={index} className="flex gap-2">
                    <Input
                        value={row.key}
                        onChange={(event) => update(index, { key: event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_') })}
                        placeholder="KEY"
                        className="font-mono"
                        aria-label="Variable name"
                    />
                    <Input
                        value={row.value ?? ''}
                        onChange={(event) => update(index, { value: event.target.value })}
                        placeholder={row.value === null ? '(unchanged)' : 'value'}
                        className="font-mono"
                        aria-label="Variable value"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => onChange(rows.filter((_, i) => i !== index))}
                        aria-label="Remove variable"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={() => onChange([...rows, { key: '', value: '' }])}>
                <Plus /> Add variable
            </Button>
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}

/** Restrict a process to some of the site's servers (none selected = all). */
export function ServerPicker({ servers, value, onChange }: { servers: ProcessServer[]; value: string[]; onChange: (ids: string[]) => void }) {
    if (servers.length < 2) return null;

    const toggle = (id: string, checked: boolean) => onChange(checked ? [...value, id] : value.filter((v) => v !== id));

    return (
        <div className="space-y-2">
            <Label>Servers</Label>
            <p className="text-muted-foreground text-xs">Leave all unchecked to run on every server of the site.</p>
            <div className="flex flex-wrap gap-4">
                {servers.map((server) => (
                    <label key={server.id} className="flex items-center gap-2 text-sm">
                        <Checkbox checked={value.includes(server.id)} onCheckedChange={(checked) => toggle(server.id, checked === true)} />
                        {server.name}
                        {server.role === 'leader' && <span className="text-muted-foreground text-xs">(leader)</span>}
                    </label>
                ))}
            </div>
        </div>
    );
}

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}

/** Form errors keyed like `env.0.key` collapse to the first message for the field. */
export function firstError(errors: Record<string, string>, prefix: string): string | undefined {
    return Object.entries(errors).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];
}
