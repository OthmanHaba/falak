export type ApplyStatus = 'pending' | 'applying' | 'applied' | 'failed';

export interface FirewallStateSummary {
    status: ApplyStatus;
    in_sync: boolean;
    revision: number;
    command_id: string | null;
    ruleset_sha256: string | null;
    error: string | null;
    applied_at: string | null;
}

export interface PrivateAddress {
    network_id: string;
    network: string;
    address: string;
    status: ApplyStatus;
}
