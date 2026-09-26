// Package otlp implements the agent's OTLP relay: an OTLP/HTTP receiver (unix socket + TCP), resource
// enrichment per the telemetry contract, trace sampling, batching, export over OTLP/HTTP protobuf with
// retries, and a bounded on-disk buffer used while the exporter is unavailable.
//
// The Export*ServiceRequest wrappers are encoded/decoded by hand with protowire (field 1 = repeated
// Resource{Spans,Logs,Metrics}) so the collector packages — and therefore grpc — are never linked.
package otlp

import (
	"bytes"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"

	logspb "go.opentelemetry.io/proto/otlp/logs/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/encoding/protowire"
	"google.golang.org/protobuf/proto"
)

// Signal identifies a telemetry signal.
type Signal string

const (
	Traces  Signal = "traces"
	Logs    Signal = "logs"
	Metrics Signal = "metrics"
)

// Path returns the OTLP/HTTP path of the signal (/v1/traces ...).
func (s Signal) Path() string { return "/v1/" + string(s) }

func (s Signal) jsonKey() string {
	switch s {
	case Traces:
		return "resourceSpans"
	case Logs:
		return "resourceLogs"
	default:
		return "resourceMetrics"
	}
}

func (s Signal) snakeKey() string {
	switch s {
	case Traces:
		return "resource_spans"
	case Logs:
		return "resource_logs"
	default:
		return "resource_metrics"
	}
}

// decodeRepeated parses a wrapper message whose field 1 is a repeated embedded message.
func decodeRepeated[T proto.Message](b []byte, newT func() T) ([]T, error) {
	var out []T
	for len(b) > 0 {
		num, typ, n := protowire.ConsumeTag(b)
		if n < 0 {
			return nil, fmt.Errorf("otlp: bad tag: %w", protowire.ParseError(n))
		}
		b = b[n:]
		if num == 1 && typ == protowire.BytesType {
			v, n := protowire.ConsumeBytes(b)
			if n < 0 {
				return nil, fmt.Errorf("otlp: bad field: %w", protowire.ParseError(n))
			}
			m := newT()
			if err := proto.Unmarshal(v, m); err != nil {
				return nil, fmt.Errorf("otlp: %w", err)
			}
			out = append(out, m)
			b = b[n:]
			continue
		}
		n = protowire.ConsumeFieldValue(num, typ, b)
		if n < 0 {
			return nil, fmt.Errorf("otlp: bad field: %w", protowire.ParseError(n))
		}
		b = b[n:]
	}
	return out, nil
}

func encodeRepeated[T proto.Message](items []T) ([]byte, error) {
	var out []byte
	for _, it := range items {
		b, err := proto.Marshal(it)
		if err != nil {
			return nil, err
		}
		out = protowire.AppendTag(out, 1, protowire.BytesType)
		out = protowire.AppendBytes(out, b)
	}
	return out, nil
}

// DecodeTraces parses an ExportTraceServiceRequest (protobuf).
func DecodeTraces(b []byte) ([]*tracepb.ResourceSpans, error) {
	return decodeRepeated(b, func() *tracepb.ResourceSpans { return new(tracepb.ResourceSpans) })
}

// DecodeLogs parses an ExportLogsServiceRequest (protobuf).
func DecodeLogs(b []byte) ([]*logspb.ResourceLogs, error) {
	return decodeRepeated(b, func() *logspb.ResourceLogs { return new(logspb.ResourceLogs) })
}

// DecodeMetrics parses an ExportMetricsServiceRequest (protobuf).
func DecodeMetrics(b []byte) ([]*metricspb.ResourceMetrics, error) {
	return decodeRepeated(b, func() *metricspb.ResourceMetrics { return new(metricspb.ResourceMetrics) })
}

// EncodeTraces builds an ExportTraceServiceRequest (protobuf).
func EncodeTraces(rs []*tracepb.ResourceSpans) ([]byte, error) { return encodeRepeated(rs) }

// EncodeLogs builds an ExportLogsServiceRequest (protobuf).
func EncodeLogs(rl []*logspb.ResourceLogs) ([]byte, error) { return encodeRepeated(rl) }

// EncodeMetrics builds an ExportMetricsServiceRequest (protobuf).
func EncodeMetrics(rm []*metricspb.ResourceMetrics) ([]byte, error) { return encodeRepeated(rm) }

var jsonUnmarshal = protojson.UnmarshalOptions{DiscardUnknown: true}

// decodeJSON parses OTLP/JSON. Per the OTLP spec trace/span ids are hex strings (protojson expects
// base64), so they are rewritten before handing each resource entry to protojson. Enum values may be
// names or integers and 64-bit ints may be strings or numbers — protojson accepts all of these.
func decodeJSON[T proto.Message](body []byte, sig Signal, newT func() T) ([]T, error) {
	var top map[string]json.RawMessage
	if err := json.Unmarshal(body, &top); err != nil {
		return nil, fmt.Errorf("otlp json: %w", err)
	}
	raw, ok := top[sig.jsonKey()]
	if !ok {
		raw, ok = top[sig.snakeKey()]
	}
	if !ok || len(raw) == 0 || string(raw) == "null" {
		return nil, nil
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var items []any
	if err := dec.Decode(&items); err != nil {
		return nil, fmt.Errorf("otlp json: %w", err)
	}
	out := make([]T, 0, len(items))
	for _, it := range items {
		fixIDs(it)
		b, err := json.Marshal(it)
		if err != nil {
			return nil, err
		}
		m := newT()
		if err := jsonUnmarshal.Unmarshal(b, m); err != nil {
			return nil, fmt.Errorf("otlp json: %w", err)
		}
		out = append(out, m)
	}
	return out, nil
}

var idKeys = map[string]bool{
	"traceId": true, "spanId": true, "parentSpanId": true,
	"trace_id": true, "span_id": true, "parent_span_id": true,
}

func fixIDs(v any) {
	switch t := v.(type) {
	case map[string]any:
		for k, val := range t {
			if s, ok := val.(string); ok && idKeys[k] {
				if s == "" {
					continue
				}
				if b, err := hex.DecodeString(s); err == nil {
					t[k] = base64.StdEncoding.EncodeToString(b)
				}
				continue
			}
			fixIDs(val)
		}
	case []any:
		for _, e := range t {
			fixIDs(e)
		}
	}
}

// DecodeTracesJSON parses an OTLP/JSON ExportTraceServiceRequest.
func DecodeTracesJSON(b []byte) ([]*tracepb.ResourceSpans, error) {
	return decodeJSON(b, Traces, func() *tracepb.ResourceSpans { return new(tracepb.ResourceSpans) })
}

// DecodeLogsJSON parses an OTLP/JSON ExportLogsServiceRequest.
func DecodeLogsJSON(b []byte) ([]*logspb.ResourceLogs, error) {
	return decodeJSON(b, Logs, func() *logspb.ResourceLogs { return new(logspb.ResourceLogs) })
}

// DecodeMetricsJSON parses an OTLP/JSON ExportMetricsServiceRequest.
func DecodeMetricsJSON(b []byte) ([]*metricspb.ResourceMetrics, error) {
	return decodeJSON(b, Metrics, func() *metricspb.ResourceMetrics { return new(metricspb.ResourceMetrics) })
}
