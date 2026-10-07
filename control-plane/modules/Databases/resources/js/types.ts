export type EngineName = 'mysql' | 'mariadb' | 'postgresql' | 'redis' | 'valkey';
/** sql: databases, users and grants; key_value: Redis / Valkey (one keyspace, one `default` user). */
export type EngineKind = 'sql' | 'key_value';

export const KEY_VALUE_ENGINES: readonly string[] = ['redis', 'valkey'];

export function isKeyValue(engine: string | null | undefined): boolean {
    return engine !== null && engine !== undefined && KEY_VALUE_ENGINES.includes(engine);
}

/** A running database container a backup can be restored into, with its active databases (a restore goes into one). */
export interface RestoreTarget {
    id: string;
    label: string;
    engine: EngineName;
    databases: string[];
}

export type ResourceStatus = 'pending' | 'active' | 'failed' | 'deleting';
export type InstanceStatus = 'pending' | 'active' | 'failed' | 'upgrading' | 'retired' | 'deleting';
/** Heartbeat health of the container (null before the first report). */
export type InstanceHealth = 'healthy' | 'unhealthy' | 'starting' | 'none' | 'stopped' | 'missing';
export type BackupStatus = 'pending' | 'running' | 'succeeded' | 'failed' | 'pruned';
export type RestoreStatus = 'pending' | 'running' | 'succeeded' | 'failed';

/** falak-db settings of a container (docs/DB_IMAGES.md "Settings"). */
export interface InstanceSettings {
    max_connections?: number;
    slow_query_ms?: number;
    /** Redis / Valkey */
    eviction?: string;
    persistence?: 'rdb' | 'aof' | 'none';
}

/** One database container (falak-db-<id>) on a server. */
export interface DatabaseInstance {
    id: string;
    name: string;
    server_id: string;
    server_name: string;
    environment_id: string | null;
    engine: EngineName;
    engine_label: string;
    kind: EngineKind;
    version: string;
    image: string;
    image_digest: string | null;
    /** DNS name on the environment's Docker network */
    hostname: string;
    /** Port inside the container */
    port: number;
    /** Published on 127.0.0.1 (and private addresses) */
    host_port: number | null;
    published_addresses: string[];
    public_access: boolean;
    require_tls: boolean;
    memory_mb: number;
    cpus: number | null;
    settings: InstanceSettings;
    pitr_enabled: boolean;
    volume_id: string | null;
    tls_expires_at: string | null;
    status: InstanceStatus;
    status_message: string | null;
    health: InstanceHealth | null;
    health_at: string | null;
    rotating_password: boolean;
    upgrade_of: string | null;
    retire_at: string | null;
    databases_count: number | null;
    users_count: number | null;
    created_at: string;
}

/** Create options of the Databases page and the canvas picker. */
export interface EngineOption {
    value: EngineName;
    label: string;
    kind: EngineKind;
    versions: string[];
    default_version: string;
    default_memory_mb: number;
    min_memory_mb: number;
    default_disk_gb: number;
}

export interface CreateOptions {
    engines: EngineOption[];
    servers: { id: string; name: string }[];
}

export interface DatabaseRow {
    id: string;
    instance_id: string;
    name: string;
    charset: string | null;
    collation: string | null;
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
    instance_id: string | null;
    instance_name: string | null;
    engine: EngineName;
    engine_version: string | null;
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
    /** When the backup was handed to the agent. */
    started_at: string | null;
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
    warnings: string[];
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

/** An address applications reach the container on (the port differs: the container's own on the network, the host port elsewhere). */
export interface ConnectionHost {
    label: string;
    value: string;
    port: number;
    hint: string;
}

/** A site of the container's environment, and the host its references resolve to (or why not). */
export interface ConnectionAccess {
    name: string;
    host: string | null;
    port: number;
    reason: string | null;
}

export interface Connection {
    engine: EngineName;
    kind: EngineKind;
    driver: string;
    port: number;
    host_port: number | null;
    hosts: ConnectionHost[];
    access: ConnectionAccess[];
}

/** Options of one container's page / panel. */
export interface InstanceOptions {
    privileges: string[];
    /** The current major and newer ones (upgrades only go forward). */
    versions: string[];
    compressions: string[];
    default_charset?: string | null;
    default_collation?: string | null;
    evictions: string[];
    persistences: string[];
    min_memory_mb: number;
    upgradable: boolean;
}

/**
 * The restore form after another target container is picked: the database chosen for the previous target is kept only
 * when the new one has a database of that name (a restore goes into an existing database), else it is cleared, and
 * with it the confirmation.
 */
export function retargetRestore<T extends { database_instance_id: string; database: string; confirm: string }>(
    data: T,
    instanceId: string,
    targets: RestoreTarget[],
): T {
    const databases = targets.find((target) => target.id === instanceId)?.databases ?? [];

    return databases.includes(data.database)
        ? { ...data, database_instance_id: instanceId }
        : { ...data, database_instance_id: instanceId, database: '', confirm: '' };
}

/** "PostgreSQL 17 · 512 MB" */
export function instanceSummary(instance: Pick<DatabaseInstance, 'engine_label' | 'version' | 'memory_mb'>): string {
    return `${instance.engine_label} ${instance.version} · ${instance.memory_mb} MB`;
}

/** The Falak status language for a container: health wins once it is running. */
export function instanceState(instance: Pick<DatabaseInstance, 'status' | 'health'>): string {
    if (instance.status === 'active' && instance.health && ['unhealthy', 'stopped', 'missing'].includes(instance.health)) {
        return instance.health === 'unhealthy' ? 'degraded' : 'offline';
    }

    return instance.status === 'pending' ? 'provisioning' : instance.status;
}
