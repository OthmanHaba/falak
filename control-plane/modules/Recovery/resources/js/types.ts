import { type SharedData } from '@/types';

/** The `disasterRecovery` shared prop: only the install's operators (owners / admins of its operator organization) get it. */
export interface DisasterRecoveryShared {
    configured: boolean;
    needs_setup: boolean;
    banner: boolean;
    settings_url: string;
}

export function disasterRecoveryOf(props: SharedData): DisasterRecoveryShared | null {
    return (props.disasterRecovery as DisasterRecoveryShared | null | undefined) ?? null;
}

/** Settings → Disaster recovery: state/dr.json as the panel reads it. */
export interface ControlPlaneStatus {
    available: boolean;
    configured: boolean;
    needs_setup: boolean;
    updated_at: string | null;
    falak_version: string | null;
    encrypted: boolean;
    target: string | null;
    endpoint: string | null;
    schedule_hours: number;
    drill_schedule: string | null;
    include_registry: boolean;
    last_backup: { name: string; at: string; age_seconds: number; size_bytes: number | null; uploaded: boolean; encrypted: boolean } | null;
    backup_failing: boolean;
    backup_missing: boolean;
    last_failure: { at: string; error: string | null } | null;
    last_drill: { at: string; ok: boolean; backup: string | null; duration_s: number | null; message: string | null } | null;
}

export type ItemState = 'pending' | 'running' | 'succeeded' | 'skipped' | 'manual' | 'failed';

export interface RecoveryStep {
    key: string;
    title: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    message: string | null;
    items: { id: string; label: string; state: ItemState; message: string | null; phase?: string }[];
}

export interface RecoveryPlan {
    lost: { id: string; name: string; ipv4: string | null; ipv6: string | null; status: string };
    target: { id: string; name: string; ipv4: string | null; ipv6: string | null; status: string } | null;
    problems: string[];
    /** The server is not gone (its agent still reports): the recovery can't start. */
    blocking: string[];
    sites: { id: string; name: string; runtime: string; other_servers: number }[];
    databases: {
        id: string;
        name: string;
        engine: string;
        version: string;
        pitr_enabled: boolean;
        method: 'pitr' | 'backup';
        worst_loss_seconds: number | null;
        databases: {
            id: string;
            name: string;
            backup_at: string | null;
            data_loss_seconds: number | null;
            customer_held: boolean;
            pitr_enabled: boolean;
            pitr_latest_at: string | null;
            method: 'pitr' | 'backup' | 'manual' | 'none';
        }[];
    }[];
    volumes: {
        id: string;
        name: string;
        kind: string;
        backup_at: string | null;
        data_loss_seconds: number | null;
        method: 'backup' | 'manual' | 'none';
    }[];
    domains: { site_id: string; name: string; managed: boolean; status: string | null; site: string | null }[];
}

export interface ServerRecovery {
    id: string;
    lost_server: { id: string; name: string };
    target_server: { id: string; name: string };
    status: 'running' | 'failed' | 'succeeded';
    current_step: string | null;
    steps: RecoveryStep[];
    plan: RecoveryPlan;
    created_at: string;
    finished_at: string | null;
}

export interface ProjectReadiness {
    project_id: string;
    name: string;
    score: number;
    checks: number;
    passed: number;
    gaps: { kind: 'database' | 'volume'; id: string; name: string; environment: string; problem: string; fix: string; url: string | null }[];
}

/** "3 h 20 min" style data-loss label; null = everything since creation. */
export function lossLabel(seconds: number | null): string {
    if (seconds === null) return 'all data (no backup)';
    if (seconds < 60) return 'under a minute';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `${hours} h ${minutes % 60} min`;

    return `${Math.floor(hours / 24)} days`;
}

export function bytesLabel(bytes: number | null): string {
    if (bytes === null) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}
