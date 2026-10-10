/** `waiting`: claimed but held until the site's servers finish preparing (see `waiting_reason`). */
export type DeploymentStatus = 'queued' | 'waiting' | 'building' | 'deploying' | 'succeeded' | 'failed' | 'cancelled';
export type StepStatus = 'pending' | 'running' | 'succeeded' | 'failed' | 'skipped';
export type Phase = 'build' | 'fetch' | 'prepare' | 'migrate' | 'activate' | 'restart' | 'healthcheck' | 'rollback';

export const PHASES: Phase[] = ['fetch', 'prepare', 'migrate', 'activate', 'restart', 'healthcheck', 'rollback'];

export interface Deployment {
    id: string;
    site_id: string;
    number: number;
    status: DeploymentStatus;
    phase: Phase | null;
    trigger: string;
    strategy: string | null;
    branch: string | null;
    commit: string | null;
    message: string | null;
    author: string | null;
    release_id: string | null;
    build_id: string | null;
    rolled_back: boolean;
    /** Why the watch after the release went live rolled it back (or only alerted). */
    rolled_back_reason?: string | null;
    rolled_back_at?: string | null;
    /** A rollback deployment started by the watch of that deployment. */
    auto_rollback_of?: string | null;
    watch?: ReleaseWatch | null;
    /** A compose stack's bootstrap pass: only these services run until its split-out sites are live. */
    partial?: { services: string[]; awaits_sites: string[] } | null;
    url: string;
    error: string | null;
    /** "Waiting for 2 servers to finish preparing: web-1, web-2" while status is `waiting`. */
    waiting_reason: string | null;
    waiting_since: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
}

export interface Step {
    id: string;
    key: string;
    kind: string;
    label: string;
    phase: Phase;
    rollback: boolean;
    batch: number;
    status: StepStatus;
    command_id: string | null;
    build_id: string | null;
    exit_code: number | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
}

export interface Target {
    id: string;
    server_id: string;
    server_name: string;
    role: 'leader' | 'member';
    batch: number;
    status: string;
    activated: boolean;
    error: string | null;
    steps: Step[];
}

export interface OutputLine {
    seq: number;
    at: string;
    server: string | null;
    server_id: string | null;
    step_id: string | null;
    phase: Phase | null;
    stream: 'stdout' | 'stderr';
    data: string;
}

export interface Release {
    id: string;
    commit: string | null;
    branch: string | null;
    message: string | null;
    author: string | null;
    deployment_id: string;
    build_id: string | null;
    image: string | null;
    status: 'active' | 'inactive' | 'failed' | 'pruned';
    active: boolean;
    can_rollback: boolean;
    activated_at: string | null;
    created_at: string;
}

export type WatchStatus = 'watching' | 'passed' | 'rolled_back' | 'alerted' | 'stopped';
export type WatchTrigger = 'health' | 'crash' | 'errors' | 'issue';

/** The watch window after a release went live (opt-in per site): its triggers roll the site back, or only alert. */
export interface ReleaseWatch {
    status: WatchStatus;
    on_trigger: 'rollback' | 'alert_only';
    triggers: { health: boolean; health_failures: number; crashes: boolean; errors: boolean; issues: boolean };
    /** The deployment ran database migrations, which a rollback doesn't reverse. */
    migrations: boolean;
    started_at: string;
    ends_at: string;
    remaining_s: number;
    checked_at: string | null;
    checks: {
        /** `unavailable`: the site's health check is off, so the trigger is skipped. */
        health?: { ok?: boolean; failures?: number; threshold?: number; message?: string | null; unavailable?: string };
        errors?: {
            total?: number;
            errors?: number;
            rate?: number;
            threshold: number;
            min_requests: number;
            min_errors?: number;
            unavailable?: string;
        };
        crash?: { events: number; message: string };
        issue?: { events: number; message: string };
    };
    baseline: { total: number; errors: number; rate: number } | null;
    trigger: WatchTrigger | null;
    reason: string | null;
    rollback_deployment_id: string | null;
    finished_at: string | null;
}
