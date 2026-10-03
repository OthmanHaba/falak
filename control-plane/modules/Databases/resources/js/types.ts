export type EngineName = 'mysql' | 'mariadb' | 'postgresql' | 'redis' | 'valkey';
/** sql: databases, users and grants; key_value: Redis / Valkey instances (own port, one `default` user). */
export type EngineKind = 'sql' | 'key_value';

export const KEY_VALUE_ENGINES: readonly string[] = ['redis', 'valkey'];

export function isKeyValue(engine: string | null | undefined): boolean {
    return engine !== null && engine !== undefined && KEY_VALUE_ENGINES.includes(engine);
}

export interface KeyValueSettings {
    maxmemory_mb: number;
    eviction: string;
    persistence: 'rdb' | 'aof' | 'none';
}
export type ResourceStatus = 'pending' | 'active' | 'failed' | 'deleting';
export type BackupStatus = 'pending' | 'running' | 'succeeded' | 'failed' | 'pruned';
export type RestoreStatus = 'pending' | 'running' | 'succeeded' | 'failed';

export interface DatabaseServer {
    id: string;
    server_id: string;
    server_name: string;
    engine: EngineName;
    engine_label: string;
    kind: EngineKind;
    version: string | null;
    version_source: 'default' | 'facts' | 'manual';
    dedicated: boolean;
    port: number;
    databases_count: number | null;
    users_count: number | null;
    /** Redis / Valkey: the instances' ports (Databases index) */
    instance_ports?: number[];
}

export interface DatabaseRow {
    id: string;
    name: string;
    charset: string | null;
    collation: string | null;
    /** Redis / Valkey: the instance's own port and settings */
    port: number | null;
    settings: KeyValueSettings | null;
    site_id: string | null;
    status: ResourceStatus;
    status_message: string | null;
    command_id: string | null;
    created_at: string;
}

export interface GrantRow {
    database_id: string;
    database: string | null;
    privileges: string[];
}

export interface DatabaseUserRow {
    id: string;
    username: string;
    host: string;
    site_id: string | null;
    status: ResourceStatus;
    status_message: string | null;
    command_id: string | null;
    grants: GrantRow[];
    created_at: string;
}

export interface ScheduleRow {
    id: string;
    name: string;
    cron: string;
    storage_provider_id: string;
    storage_provider: string | null;
    database_ids: string[];
    databases: string[];
    retention_count: number | null;
    retention_days: number | null;
    compression: 'gzip' | 'none';
    enabled: boolean;
    last_run_at: string | null;
    next_run_at: string | null;
}

export interface BackupRow {
    id: string;
    database_name: string;
    server_id: string;
    server_name: string;
    database_server_id: string | null;
    engine: EngineName;
    storage_provider: string | null;
    object_key: string;
    compression: 'gzip' | 'none';
    trigger: 'manual' | 'scheduled';
    schedule_id: string | null;
    status: BackupStatus;
    size_bytes: number | null;
    sha256: string | null;
    duration_ms: number | null;
    error: string | null;
    prune_error: string | null;
    command_id: string | null;
    restorable: boolean;
    created_at: string;
    finished_at: string | null;
    pruned_at: string | null;
}

export interface RestoreRow {
    id: string;
    backup_id: string;
    source_database: string;
    database_name: string;
    status: RestoreStatus;
    bytes: number | null;
    duration_ms: number | null;
    error: string | null;
    command_id: string | null;
    created_at: string;
    finished_at: string | null;
}

export interface StorageProviderRow {
    id: string;
    name: string;
    driver: 's3' | 'r2' | 'b2' | 'spaces' | 'minio';
    driver_label: string;
    endpoint: string | null;
    region: string;
    bucket: string;
    prefix: string | null;
    path_style: boolean;
    access_key_hint: string;
    verified_at: string | null;
    created_at: string;
}

export interface StorageOption {
    id: string;
    name: string;
    driver: string;
    bucket: string;
}

export interface ConnectionHost {
    label: string;
    value: string;
    hint: string;
}

export interface Connection {
    engine: EngineName;
    kind: EngineKind;
    driver: string;
    port: number;
    hosts: ConnectionHost[];
}
