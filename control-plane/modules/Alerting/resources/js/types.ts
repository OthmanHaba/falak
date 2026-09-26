export type Severity = 'info' | 'warning' | 'critical';
export type ChannelType = 'email' | 'slack' | 'discord' | 'telegram' | 'webhook';

export interface AppNotification {
    id: string;
    type: string;
    severity: Severity;
    title: string;
    body: string | null;
    url: string | null;
    read_at: string | null;
    created_at: string;
}

export interface ChannelRow {
    id: string;
    name: string;
    type: ChannelType;
    enabled: boolean;
    /** Secrets are masked by the server. */
    config: Record<string, unknown>;
    secret_keys: string[];
    rules_count: number;
    last_sent_at: string | null;
    last_error: string | null;
}

export interface QuietHours {
    start: string;
    end: string;
    timezone: string;
    days?: number[];
    allow_critical?: boolean;
}

export interface RuleRow {
    id: string;
    name: string;
    enabled: boolean;
    event_types: string[];
    min_severity: Severity;
    quiet_hours: QuietHours | null;
    rate_limit_per_hour: number | null;
    channels: { id: string; name: string; type: ChannelType }[];
}

export interface AlertTypeOption {
    type: string;
    label: string;
    group: string;
    severity: Severity;
}

export interface DeliveryRow {
    id: string;
    channel: { name: string; type: ChannelType } | null;
    status: 'pending' | 'sent' | 'failed';
    attempts: number;
    error: string | null;
    sent_at: string | null;
}

export interface AlertRow {
    id: string;
    type: string;
    severity: Severity;
    title: string;
    body: string | null;
    url: string | null;
    recovery: boolean;
    outcome: string;
    created_at: string;
    deliveries: DeliveryRow[];
}
