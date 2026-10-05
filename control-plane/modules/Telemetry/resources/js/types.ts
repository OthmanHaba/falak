export type AttributeValue = string | number | boolean | null;

export interface SpanEventDto {
    name: string;
    time_unix_nano: string;
    attributes: Record<string, AttributeValue>;
}

/** Mirrors Falak\Telemetry\Contracts\Data\Span::toArray(). */
export interface SpanDto {
    trace_id: string;
    span_id: string;
    parent_span_id: string | null;
    name: string;
    service: string;
    kind: string;
    start_unix_nano: string;
    end_unix_nano: string;
    duration_ms: number;
    status: 'ok' | 'error' | 'unset';
    status_message: string | null;
    attributes: Record<string, AttributeValue>;
    resource: Record<string, AttributeValue>;
    events: SpanEventDto[];
}

export interface TraceDto {
    trace_id: string;
    spans: SpanDto[];
}

export interface TraceSummaryDto {
    trace_id: string;
    root_service: string | null;
    root_name: string | null;
    start_unix_nano: string;
    duration_ms: number;
    matched_spans: number;
}

export interface LogLineDto {
    ts: string;
    at: string;
    line: string;
    labels: Record<string, string>;
    metadata: Record<string, string>;
    trace_id: string | null;
}

export interface MetricSeriesDto {
    labels: Record<string, string>;
    points: [number, number | null][];
}
