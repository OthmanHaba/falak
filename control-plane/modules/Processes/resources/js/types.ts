import { type SiteHeader } from '@/layouts/site-layout';

export type ApplyStatus = 'pending' | 'applied' | 'failed' | 'error';

export type ProcessState = 'starting' | 'running' | 'backoff' | 'stopping' | 'stopped' | 'exited' | 'fatal' | 'unknown';

export interface ProcessInstance {
    instance: number;
    state: ProcessState;
    pid: number | null;
    restarts: number | null;
    started_at: string | null;
    last_exit_code: number | null;
}

export interface ProgramStatus {
    name: string;
    kind: 'app' | 'horizon' | 'octane' | 'worker' | 'daemon';
    label: string;
    numprocs: number;
    server_id: string;
    applied: boolean;
    crash_looping: boolean;
    instances: ProcessInstance[];
}

export interface ProcessServer {
    id: string;
    name: string;
    role: 'leader' | 'member';
    target_status: string;
    proc: { status: ApplyStatus | null; error: string | null; applied_at: string | null };
    cron: { status: ApplyStatus | null; error: string | null; applied_at: string | null };
    status_at: string | null;
    logs_url: string | null;
}

/** Stored values are never sent back: `value: null` keeps the stored value on save. */
export interface EnvRow {
    key: string;
    value: string | null;
}

export interface SharedProps {
    site: SiteHeader;
    servers: ProcessServer[];
    programs: ProgramStatus[];
    logsUrl: string | null;
    can: { manage: boolean };
}
