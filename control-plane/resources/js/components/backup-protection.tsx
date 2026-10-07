import { Button, Dialog, Field, Input, Select, Tag, Textarea } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

/**
 * Backup protection (docs/BACKUPS.md), shared by database and volume schedules: who holds the backup keys, restore
 * drills, the badges of a backup, and exporting a backup's key.
 */

/** cp: Falak holds each backup's key (sealed); customer: encrypted to an age recipient, Falak never has it. */
export type EncryptionMode = 'cp' | 'customer';
export type DrillFrequency = 'off' | 'weekly' | 'monthly';
export type DrillStatus = 'pending' | 'passed' | 'failed' | 'skipped';

export interface DrillCheck {
    name: string;
    passed: boolean;
    detail?: string;
}

/** A restore drill: the latest backup restored into a throwaway instance (or directory) and checked. */
export interface DrillRow {
    id: string;
    backup_id: string | null;
    database_name?: string | null;
    server_name?: string | null;
    status: DrillStatus;
    reason: string | null;
    error: string | null;
    checks: DrillCheck[];
    duration_ms: number | null;
    /** Download + restore time of the drill: about what getting this backup back takes. */
    rto_estimate_seconds: number | null;
    created_at: string;
    finished_at?: string | null;
}

/** A recipient as `age-keygen` prints it (X25519, bech32). */
export const AGE_RECIPIENT = /^age1[02-9ac-hj-np-z]{58}$/;

/** An identity as in an `age-keygen` key file. */
export const AGE_IDENTITY = /^AGE-SECRET-KEY-1[02-9AC-HJ-NP-Z]{58}$/;

/** Whether a restore of this backup needs the user's age identity (customer-held key). */
export function needsIdentity(backup: { encryption_mode: EncryptionMode | null }): boolean {
    return backup.encryption_mode === 'customer';
}

export interface ProtectionValue {
    encryption_mode: EncryptionMode;
    age_recipient: string;
    /** default: the server picks by environment (weekly in production, off elsewhere). */
    drill: DrillFrequency | 'default';
    drill_query: string;
    drill_server_id: string;
}

export function protectionFrom(schedule?: {
    encryption_mode: EncryptionMode;
    age_recipient: string | null;
    drill: DrillFrequency;
    drill_query?: string | null;
    drill_server_id: string | null;
}): ProtectionValue {
    return {
        encryption_mode: schedule?.encryption_mode ?? 'cp',
        age_recipient: schedule?.age_recipient ?? '',
        drill: schedule?.drill ?? 'default',
        drill_query: schedule?.drill_query ?? '',
        drill_server_id: schedule?.drill_server_id ?? '',
    };
}

/** The request fields: an empty recipient, query or server is sent as null. */
export function protectionPayload(value: ProtectionValue, withQuery: boolean): Record<string, string | null> {
    return {
        encryption_mode: value.encryption_mode,
        age_recipient: value.encryption_mode === 'customer' ? value.age_recipient.trim() || null : null,
        ...(value.drill !== 'default' ? { drill: value.drill } : {}),
        ...(withQuery ? { drill_query: value.drill_query.trim() || null } : {}),
        drill_server_id: value.drill_server_id || null,
    };
}

const NO_SERVER = '__same__';

export function ProtectionFields({
    value,
    onChange,
    errors,
    servers,
    withQuery,
}: {
    value: ProtectionValue;
    onChange: (value: ProtectionValue) => void;
    errors: Record<string, string | undefined>;
    servers: { id: string; name: string }[];
    /** SQL engines: a check query. */
    withQuery: boolean;
}) {
    const set = (patch: Partial<ProtectionValue>) => onChange({ ...value, ...patch });

    return (
        <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
            <Field
                label="Backup keys"
                hint={
                    value.encryption_mode === 'cp'
                        ? 'Falak holds each backup’s key, sealed under your organization’s key.'
                        : 'Encrypted to your age key: Falak can’t read these backups, or check them in drills.'
                }
                error={errors.encryption_mode}
            >
                <Select
                    value={value.encryption_mode}
                    onValueChange={(mode) => set({ encryption_mode: mode as EncryptionMode })}
                    options={[
                        { value: 'cp', label: 'Held by Falak' },
                        { value: 'customer', label: 'Customer-held (age)' },
                    ]}
                />
            </Field>
            {value.encryption_mode === 'customer' ? (
                <Field label="age public key" hint="age1… from age-keygen. Keep the private key safe: restores need it." error={errors.age_recipient}>
                    <Input value={value.age_recipient} onChange={(event) => set({ age_recipient: event.target.value })} mono placeholder="age1…" />
                </Field>
            ) : (
                <span className="hidden sm:block" />
            )}
            <Field label="Restore drills" hint="The latest backup is restored on a throwaway instance and checked." error={errors.drill}>
                <Select
                    value={value.drill}
                    onValueChange={(drill) => set({ drill: drill as ProtectionValue['drill'] })}
                    options={[
                        ...(value.drill === 'default' ? [{ value: 'default', label: 'Default (weekly in production)' }] : []),
                        { value: 'off', label: 'Off' },
                        { value: 'weekly', label: 'Weekly' },
                        { value: 'monthly', label: 'Monthly' },
                    ]}
                />
            </Field>
            <Field label="Drill server" hint="When this server lacks the memory or disk for a drill." error={errors.drill_server_id}>
                <Select
                    value={value.drill_server_id || NO_SERVER}
                    onValueChange={(id) => set({ drill_server_id: id === NO_SERVER ? '' : id })}
                    options={[{ value: NO_SERVER, label: 'This server' }, ...servers.map((server) => ({ value: server.id, label: server.name }))]}
                />
            </Field>
            {withQuery && value.drill !== 'off' && (
                <div className="sm:col-span-2">
                    <Field
                        label="Check query (optional)"
                        hint="One SELECT, run read-only on the restored copy: the drill fails when it returns no row."
                        error={errors.drill_query}
                    >
                        <Textarea
                            value={value.drill_query}
                            onChange={(event) => set({ drill_query: event.target.value })}
                            rows={2}
                            className="font-mono"
                            placeholder="SELECT id FROM orders WHERE created_at > now() - interval '2 days'"
                        />
                    </Field>
                </div>
            )}
        </div>
    );
}

/** Encryption and verification badges of one backup. */
export function BackupBadges({
    backup,
}: {
    backup: { encryption_mode: EncryptionMode | null; verified_at: string | null; drill_status: string | null };
}) {
    return (
        <span className="inline-flex flex-wrap gap-1">
            {backup.encryption_mode === null ? (
                <Tag tone="warning">unencrypted</Tag>
            ) : (
                <Tag
                    icon={<KeyRound />}
                    title={backup.encryption_mode === 'customer' ? 'Encrypted with your age key' : 'Encrypted, key held by Falak'}
                >
                    {backup.encryption_mode === 'customer' ? 'your key' : 'encrypted'}
                </Tag>
            )}
            {backup.verified_at ? (
                <Tag tone="success" icon={<ShieldCheck />} title={`Restored by a drill ${new Date(backup.verified_at).toLocaleString()}`}>
                    verified
                </Tag>
            ) : backup.drill_status === 'failed' ? (
                <Tag tone="danger" icon={<ShieldAlert />}>
                    drill failed
                </Tag>
            ) : null}
        </span>
    );
}

const DRILL_TONE = { passed: 'success', failed: 'danger', skipped: 'faint', pending: 'info' } as const;

/** A schedule's last drills. */
export function DrillHistory({ drills }: { drills: DrillRow[] }) {
    if (drills.length === 0) return <p className="text-fg-faint text-xs">No drill yet.</p>;

    return (
        <ul className="grid gap-1.5">
            {drills.map((drill) => (
                <li key={drill.id} className="grid gap-0.5 text-xs">
                    <span className="flex flex-wrap items-center gap-2">
                        <Tag tone={DRILL_TONE[drill.status]}>{drill.status}</Tag>
                        <span className="text-fg-muted">{new Date(drill.created_at).toLocaleString()}</span>
                        {drill.database_name && <span className="font-mono">{drill.database_name}</span>}
                        {drill.rto_estimate_seconds !== null && (
                            <span className="text-fg-muted">RTO ≈ {formatSeconds(drill.rto_estimate_seconds)}</span>
                        )}
                    </span>
                    {(drill.error || drill.reason) && (
                        <span className={drill.error ? 'text-danger' : 'text-fg-faint'}>{drill.error ?? drill.reason}</span>
                    )}
                    {drill.status === 'failed' &&
                        drill.checks
                            .filter((check) => !check.passed)
                            .map((check) => (
                                <span key={check.name} className="text-danger">
                                    {check.name}: {check.detail ?? 'failed'}
                                </span>
                            ))}
                </li>
            ))}
        </ul>
    );
}

export function formatSeconds(seconds: number): string {
    if (seconds < 90) return `${seconds}s`;
    if (seconds < 5400) return `${Math.round(seconds / 60)} min`;
    return `${(seconds / 3600).toFixed(1)} h`;
}

/** Re-authentication before exporting a key: the password, plus the authenticator code when 2FA is on. */
function ReauthDialog({ open, onClose, onConfirmed }: { open: boolean; onClose: () => void; onConfirmed: () => void }) {
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setPassword('');
            setCode('');
            setError(null);
        }
    }, [open]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        try {
            await requestJson('/confirm-password', 'POST', { password, code: code || null });
            onConfirmed();
        } catch (e) {
            setError(e instanceof HttpError ? (Object.values(e.errors)[0] ?? e.message) : errorMessage(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Confirm it's you"
            description="Exporting a backup key needs a recent confirmation. It is recorded in the audit log."
            size="sm"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="backup-key-reauth" loading={busy} disabled={password === ''}>
                        Confirm
                    </Button>
                </>
            }
        >
            <form id="backup-key-reauth" onSubmit={submit} className="grid gap-4">
                <Field label="Password" error={error}>
                    <Input
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        autoFocus
                    />
                </Field>
                <Field label="Authentication code" hint="Only when two-factor authentication is on.">
                    <Input
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        mono
                        value={code}
                        onChange={(event) => setCode(event.target.value.replace(/\s+/g, ''))}
                    />
                </Field>
            </form>
        </Dialog>
    );
}

function saveFile(name: string, content: string) {
    const url = URL.createObjectURL(new Blob([content], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
}

/**
 * "Export backup key": POST the export URL; a 423 asks for re-authentication first. The key file downloads for
 * `falak-restore --key-file`.
 */
export function useKeyExport(onError: (message: string) => void) {
    const [pending, setPending] = useState<string | null>(null);

    const run = async (url: string) => {
        try {
            const file = await requestJson<{ filename: string; content: string }>(url, 'POST');
            saveFile(file.filename, file.content);
            setPending(null);
        } catch (e) {
            if (e instanceof HttpError && e.status === 423) {
                setPending(url);
            } else {
                setPending(null);
                onError(e instanceof HttpError ? (Object.values(e.errors)[0] ?? e.message) : errorMessage(e));
            }
        }
    };

    const dialog = <ReauthDialog open={pending !== null} onClose={() => setPending(null)} onConfirmed={() => pending && void run(pending)} />;

    return { exportKey: run, dialog };
}
