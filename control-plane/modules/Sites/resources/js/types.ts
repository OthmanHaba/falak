export type SiteStatus = 'ready' | 'provisioning' | 'failed' | 'no_servers';
export type TargetStatus = 'pending' | 'provisioning' | 'ready' | 'failed' | 'removing';
export type TargetRole = 'leader' | 'member';

export interface Option {
    value: string;
    label: string;
}

export interface SiteTarget {
    id: string;
    server_id: string;
    server_name: string;
    server_ip: string | null;
    role: TargetRole;
    status: TargetStatus;
    status_message: string | null;
    command_id: string | null;
}

export interface SharedPathItem {
    path: string;
    type: 'directory' | 'file';
}

export type OctaneServer = 'frankenphp' | 'swoole' | 'roadrunner';

export interface LaravelToggles {
    scheduler: boolean;
    horizon: boolean;
    octane: boolean;
    maintenance: boolean;
    /** Set by the control plane when Octane is first enabled (defaults per runtime). */
    octane_server: OctaneServer | null;
    /** 127.0.0.1 port Octane listens on; allocated and persisted by the control plane (read only). */
    octane_port: number | null;
}

export interface FrameworkOption extends Option {
    runtimes: string[];
    web_directory: string;
    is_php: boolean;
    is_laravel: boolean;
}

export interface RuntimeOption extends Option {
    is_php: boolean;
    proxies: boolean;
    container: boolean;
    build_modes: string[];
}

export interface ServerOption {
    id: string;
    name: string;
    type: string;
    type_label: string;
    status: string;
    ipv4: string | null;
    php_runtime: string | null;
    php_versions: string[];
    default_php: string | null;
    docker: boolean;
    memory_bytes: number | null;
}

export interface ConnectionOption {
    id: string;
    name: string;
    provider: string;
    provider_label: string;
    has_api: boolean;
}

export interface SiteOptions {
    frameworks: FrameworkOption[];
    runtimes: RuntimeOption[];
    build_modes: Option[];
    servers: ServerOption[];
    connections: ConnectionOption[];
    php_versions: string[];
    node_versions: string[];
    test_domain: string | null;
    on_server_min_memory_bytes: number;
}

export interface RepositoryItem {
    full_name: string;
    default_branch: string;
    private: boolean;
    ssh_url: string;
    https_url: string;
    web_url: string | null;
}

export interface BranchItem {
    name: string;
    sha: string | null;
    protected: boolean;
}
