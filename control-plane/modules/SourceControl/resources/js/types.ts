export type ProviderValue = 'github' | 'gitlab' | 'bitbucket' | 'custom';

export type AuthType = 'oauth' | 'app' | 'token' | 'basic' | 'none';

export interface ConnectionRow {
    id: string;
    name: string;
    provider: ProviderValue;
    provider_label: string;
    auth_type: AuthType;
    account: string | null;
    base_url: string | null;
    deploy_keys_count: number;
    webhooks_count: number;
    created_at: string;
}

export interface PushRow {
    id: string;
    connection_id: string;
    repository: string;
    branch: string;
    sha: string;
    message: string;
    author: string | null;
    pusher: string | null;
    url: string | null;
    received_at: string;
}

export interface ProviderOption {
    value: ProviderValue;
    label: string;
    has_api: boolean;
    oauth: boolean;
}

/** GET /source-control/connections/{id}/repositories */
export interface RepositoryOption {
    full_name: string;
    default_branch: string;
    private: boolean;
    ssh_url: string;
    https_url: string;
    web_url: string | null;
}

/** GET /source-control/connections/{id}/branches?repository= */
export interface BranchOption {
    name: string;
    sha: string | null;
    protected: boolean;
}

export const AUTH_LABELS: Record<AuthType, string> = {
    oauth: 'OAuth',
    app: 'GitHub App',
    token: 'Access token',
    basic: 'App password',
    none: 'Deploy key only',
};
