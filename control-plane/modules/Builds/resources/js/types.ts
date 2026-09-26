export type BuildStatus = 'queued' | 'assigned' | 'running' | 'succeeded' | 'failed' | 'cancelled' | 'timed_out';

export interface Build {
    id: string;
    site_id: string;
    site_name: string;
    deployment_id: string | null;
    mode: 'native' | 'docker';
    status: BuildStatus;
    branch: string | null;
    commit: string | null;
    reused_build_id: string | null;
    builder: string | null;
    progress: number | null;
    error: string | null;
    exit_code: number | null;
    duration_ms: number | null;
    attempts: number;
    artifact: { sha256: string; size_bytes: number | null; format: string | null; pruned: boolean } | null;
    image: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
}

export interface BuildLine {
    seq: number;
    stream: string;
    data: string;
    at: string;
}

export const TERMINAL_BUILD: BuildStatus[] = ['succeeded', 'failed', 'cancelled', 'timed_out'];

const STYLES: Record<BuildStatus, string> = {
    queued: 'bg-muted text-muted-foreground',
    assigned: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    running: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    cancelled: 'bg-muted text-muted-foreground',
    timed_out: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};

export function statusClass(status: BuildStatus): string {
    return STYLES[status];
}

export function ms(value: number | null): string {
    if (value === null) {
        return '';
    }

    return value < 60000 ? `${Math.round(value / 100) / 10}s` : `${Math.floor(value / 60000)}m ${Math.round((value % 60000) / 1000)}s`;
}
