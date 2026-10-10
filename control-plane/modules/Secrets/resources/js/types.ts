export type SecretScope = 'organization' | 'project' | 'environment' | 'service';

export interface SecretUser {
    service_id: string;
    name: string;
    site_id: string;
    variables: string[];
    url: string | null;
}

/** Metadata only: values never come with it. */
export interface SecretRow {
    id: string;
    name: string;
    scope: SecretScope;
    scope_id: string;
    scope_label: string;
    kind: 'managed' | 'linked';
    provider_id: string | null;
    /** Linked secrets: poll the provider every N minutes (null: not watched). */
    watch_minutes: number | null;
    on_change: OnChange;
    last_polled_at: string | null;
    sensitive: boolean;
    available_to_previews: boolean;
    description: string | null;
    rotation_days: number | null;
    rotation_due_at: string | null;
    current_version: number;
    last_accessed_at: string | null;
    created_at: string;
    updated_at: string;
    used_by?: SecretUser[];
}

export interface SecretVersionRow {
    version: number;
    current: boolean;
    restored_from: number | null;
    note: string | null;
    /** A linked secret restored to a recorded value: deployments use it instead of asking the provider. */
    pinned: boolean;
    created_by: string | null;
    created_at: string;
    disabled_at: string | null;
}

export interface SecretAccessRow {
    id: number;
    version: number;
    actor_type: 'user' | 'deployment' | 'build' | 'api_token' | 'system';
    actor: string;
    actor_id: string | null;
    reason: string;
    ip: string | null;
    created_at: string;
}

export interface SecretDetail extends SecretRow {
    versions: SecretVersionRow[];
    access_log: SecretAccessRow[];
}

/** A scope secrets can be created in (the project page: project, environments, site services). */
export interface ScopeOption {
    scope: SecretScope;
    id: string;
    label: string;
    environment_id: string | null;
}

export interface SecretAbilities {
    manage: boolean;
    reveal: boolean;
    promote: boolean;
}

export const SCOPE_LABELS: Record<SecretScope, string> = {
    organization: 'Organization',
    project: 'Project',
    environment: 'Environment',
    service: 'Service',
};

export type OnChange = 'none' | 'restart' | 'redeploy';

export const ON_CHANGE_LABELS: Record<OnChange, string> = {
    none: 'Record a new version only',
    restart: 'Restart the services that use it',
    redeploy: 'Redeploy the services that use it',
};

export type ProviderTypeValue = 'vault' | 'aws_secrets_manager' | 'aws_ssm' | 'onepassword' | 'doppler' | 'infisical' | 'http';

/** One setting of a provider type (credentials are write-only: never sent back). */
export interface ProviderField {
    name: string;
    label: string;
    kind: 'text' | 'url' | 'secret' | 'textarea' | 'select';
    required: boolean;
    secret?: boolean;
    options?: { value: string; label: string }[];
    default?: string;
    placeholder?: string;
    hint?: string;
    when?: { field: string; in: string[] };
}

export interface ProviderTypeOption {
    value: ProviderTypeValue;
    label: string;
    scheme: string;
    example: string;
    self_hostable: boolean;
    fields: ProviderField[];
}

/** A provider without its credentials (only which ones are stored). */
export interface ProviderRow {
    id: string;
    name: string;
    type: ProviderTypeValue;
    type_label: string;
    scheme: string;
    settings: Record<string, string>;
    stored_credentials: string[];
    allow_private_network: boolean;
    cache_ttl_seconds: number;
    status: 'untested' | 'ok' | 'error';
    last_checked_at: string | null;
    last_error: string | null;
    secrets_count: number;
    created_at: string;
    updated_at: string;
}

/** A provider a linked secret can use (the secrets pages). */
export interface ProviderOption {
    id: string;
    name: string;
    type: ProviderTypeValue;
    scheme: string;
    example: string;
    status: ProviderRow['status'];
}

/** The integration icon of a provider type. */
export function providerIcon(type: ProviderTypeValue): string {
    if (type === 'aws_secrets_manager' || type === 'aws_ssm') return 'aws';

    return type === 'http' ? 'webhook' : 'token';
}

/** Whether a field applies given the other settings (its `when`). */
export function fieldApplies(field: ProviderField, fields: ProviderField[], values: Record<string, string>): boolean {
    if (!field.when) return true;
    const other = fields.find((candidate) => candidate.name === field.when?.field);
    const value = values[field.when.field] || other?.default || '';

    return field.when.in.includes(value);
}
