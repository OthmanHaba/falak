export type IssueKind = 'exception' | 'performance' | 'heartbeat';
export type IssueStatus = 'open' | 'resolved' | 'ignored';
export type IssuePriority = 'none' | 'low' | 'medium' | 'high' | 'urgent';

export interface Member {
    id: string;
    name: string;
    email: string;
}

export interface IssueSummary {
    id: string;
    kind: IssueKind;
    status: IssueStatus;
    priority: IssuePriority;
    title: string;
    culprit: string | null;
    site_id: string | null;
    site_name: string | null;
    occurrences: number;
    affected_users: number;
    first_seen_at: string;
    last_seen_at: string;
    assignee: Member | null;
    handled: boolean | null;
}

export interface Frame {
    raw: string;
    file: string | null;
    line: number | null;
    function: string | null;
    in_app: boolean;
}

export interface TopEntry {
    name: string;
    count: number;
    errors: number;
    p95_ms: number;
    max_ms: number;
}

export interface OverviewPoint {
    t: string;
    requests: number;
    request_errors: number;
    request_p95_ms: number | null;
    jobs: number;
    failed_jobs: number;
    queries: number;
    exceptions_handled: number;
    exceptions_unhandled: number;
}

export interface HeartbeatMonitor {
    id: string;
    job: string;
    site_id: string | null;
    site_name?: string | null;
    server_id: string | null;
    schedule: string | null;
    timezone: string;
    grace_seconds: number | null;
    enabled: boolean;
    last_status: string | null;
    last_exit_code: number | null;
    last_duration_ms: number | null;
    last_run_at: string | null;
    next_expected_at: string | null;
    missed_at: string | null;
    healthy: boolean;
}
