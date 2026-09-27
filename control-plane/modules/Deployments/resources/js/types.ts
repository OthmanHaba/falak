export type DeploymentStatus = 'queued' | 'building' | 'deploying' | 'succeeded' | 'failed' | 'cancelled';
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
    url: string;
    error: string | null;
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
