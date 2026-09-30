export interface FunctionVersionSummary {
    id: string;
    number: number;
    hash: string;
    short_hash: string;
    message: string | null;
    author: string | null;
    size: number;
    created_at: string;
}

export type FunctionFiles = Record<string, string>;

export interface FunctionSettings {
    min_instances: number;
    max_instances: number;
    concurrency: number;
    idle_timeout_s: number;
    memory_mb: number;
    cpus: number;
    request_timeout_s: number;
}

/** GET /sites/{site}/function */
export interface FunctionState {
    site: { id: string; name: string; slug: string };
    runtime: { key: string; label: string; language: string };
    entrypoint: string;
    head: (FunctionVersionSummary & { files: FunctionFiles }) | null;
    live: FunctionVersionSummary | null;
    draft: { files: FunctionFiles; base_version_id: string | null; updated_at: string } | null;
    settings: FunctionSettings;
    bounds: Record<keyof FunctionSettings, [number, number]>;
    limits: { max_bytes: number; max_files: number };
    can: { edit: boolean; deploy: boolean };
}

export interface Starter {
    key: string;
    title: string;
    description: string;
}

export const functionUrl = (siteId: string, path = '') => `/sites/${siteId}/function${path}`;
