export type RunStatus = 'pending' | 'running' | 'succeeded' | 'failed' | 'partial';
export type TargetStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'unavailable';

export interface RecipeRow {
    id: string;
    name: string;
    description: string | null;
    script: string;
    user: string;
    updated_at: string;
}

export interface BuiltinRecipeRow {
    key: string;
    name: string;
    description: string;
    script: string;
    user: string;
    variables: Record<string, string>;
}

export interface RunSummary {
    id: string;
    recipe_id: string | null;
    builtin: string | null;
    recipe_name: string;
    user: string;
    status: RunStatus;
    servers: number;
    succeeded: number;
    failed: number;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
}

export interface RunTargetRow {
    id: string;
    server_id: string;
    server_name: string;
    status: TargetStatus;
    command_id: string | null;
    exit_code: number | null;
    error: string | null;
    duration_ms: number | null;
    started_at: string | null;
    finished_at: string | null;
}

export interface Option {
    value: string;
    label: string;
}
