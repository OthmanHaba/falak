export type TlsMode = 'auto' | 'dns' | 'custom' | 'internal' | 'off';
export type WwwRedirect = 'none' | 'to_www' | 'to_apex';
export type ApplyStatus = 'pending' | 'applied' | 'failed' | 'error';
export type InstallStatus = 'pending' | 'installed' | 'failed' | 'removing';

export interface Option {
    value: string;
    label: string;
}

export interface EdgeDomain {
    id: string;
    name: string;
    is_primary: boolean;
    www_redirect: WwwRedirect;
    tls_mode: TlsMode;
    certificate_id: string | null;
    dns_credential_id: string | null;
    hosts: string[];
    served_host: string;
    supports_www: boolean;
    wildcard: boolean;
}

export interface EdgeCertificate {
    id: string;
    domains: string[];
    issuer: string | null;
    not_after: string | null;
    expired: boolean;
    fingerprint: string;
    created_at: string;
    installs: { server_id: string; server_name: string; status: InstallStatus; error: string | null }[];
}

export interface DnsCredentialOption {
    id: string;
    name: string;
    provider: string;
}

export interface EdgeServer {
    id: string;
    name: string;
    role: string;
    serves_http: boolean;
    state: {
        status: ApplyStatus;
        error: string | null;
        command_id: string | null;
        dispatched_at: string | null;
        applied_at: string | null;
        routes: number | null;
    } | null;
}

export interface LoadBalancerConfig {
    server_id: string;
    policy: string;
    health_uri: string | null;
    backend_port: number;
    weights: Record<string, number>;
}

/** GET /sites/{site}/domains (JSON). */
export interface DomainsData {
    testDomain: string | null;
    domains: EdgeDomain[];
    certificates: EdgeCertificate[];
    dnsCredentials: DnsCredentialOption[];
    dnsProviders: Option[];
    tlsModes: Option[];
    edgeServers: EdgeServer[];
    loadBalancer: LoadBalancerConfig | null;
    lbServers: { id: string; name: string; status: string }[];
    targets: { server_id: string; name: string; role: string }[];
    policies: Option[];
    routeId: string;
    can: { manage: boolean; manage_dns: boolean };
}

/** GET /sites/{site}/routing (JSON). */
export interface RoutingData {
    redirects: { id: string; from: string; to: string; status: number }[];
    rules: { id: string; name: string | null; path: string | null; username: string }[];
    headers: { id: string; name: string; value: string }[];
    settings: { allow_ips: string[]; deny_ips: string[]; max_body_bytes: number | null; encode: boolean };
    behindLoadBalancer: boolean;
    can: { manage: boolean };
}
