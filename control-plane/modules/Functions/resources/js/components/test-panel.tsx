import { Button, Callout, CodeBlock, Input, Select, Tag, Textarea } from '@/components/kiln';
import { errorMessage, requestJson } from '@/lib/http';
import { ChevronDown, ChevronRight, Send } from 'lucide-react';
import { useState } from 'react';
import { functionUrl } from '../types';

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'].map((m) => ({ value: m, label: m }));

interface Result {
    url?: string;
    status?: number;
    headers?: Record<string, string>;
    body?: string;
    truncated?: boolean;
    duration_ms: number;
    error?: string;
}

function parseHeaders(text: string): Record<string, string> {
    const headers: Record<string, string> = {};
    for (const line of text.split('\n')) {
        const i = line.indexOf(':');
        if (i > 0) headers[line.slice(0, i).trim()] = line.slice(i + 1).trim();
    }
    return headers;
}

function pretty(body: string, type: string | undefined): string {
    if (!type?.includes('json')) return body;
    try {
        return JSON.stringify(JSON.parse(body), null, 2);
    } catch {
        return body;
    }
}

/**
 * The Code tab's "Send a test request": through the function's real URL (TLS, gateway, cold start, access rules),
 * requested by the control plane so there is no CORS to worry about.
 */
export function TestPanel({ siteId }: { siteId: string }) {
    const [open, setOpen] = useState(false);
    const [method, setMethod] = useState('GET');
    const [path, setPath] = useState('/');
    const [headers, setHeaders] = useState('');
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [result, setResult] = useState<Result | null>(null);

    const send = async () => {
        if (sending) return; // Enter in the path field while a request is pending
        setSending(true);
        try {
            const response = await requestJson<{ data: Result }>(functionUrl(siteId, '/invoke'), 'POST', {
                method,
                path: path.startsWith('/') ? path : `/${path}`,
                headers: parseHeaders(headers),
                body: ['GET', 'HEAD'].includes(method) ? null : body,
            });
            setResult(response.data);
        } catch (e) {
            setResult({ error: errorMessage(e), duration_ms: 0 });
        } finally {
            setSending(false);
        }
    };

    const type = result?.headers?.['Content-Type'] ?? result?.headers?.['content-type'];

    return (
        <div className="border-border rounded-md border">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="text-fg flex w-full items-center gap-1.5 px-3 py-2 text-left text-sm font-medium"
            >
                {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />} Send a test request
            </button>
            {open && (
                <div className="border-border grid gap-2 border-t p-3">
                    <div className="flex gap-2">
                        <Select value={method} onValueChange={setMethod} options={METHODS} aria-label="Method" className="w-28" />
                        <Input
                            className="font-mono"
                            value={path}
                            onChange={(e) => setPath(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && send()}
                            aria-label="Path"
                        />
                        <Button variant="primary" icon={<Send />} loading={sending} onClick={send}>
                            Send
                        </Button>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Textarea
                            rows={3}
                            className="font-mono text-xs"
                            placeholder={'Headers, one per line\nContent-Type: application/json\nX-Kiln-Key: kfn_…'}
                            value={headers}
                            onChange={(e) => setHeaders(e.target.value)}
                            aria-label="Headers"
                        />
                        <Textarea
                            rows={3}
                            className="font-mono text-xs"
                            placeholder="Body"
                            disabled={['GET', 'HEAD'].includes(method)}
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            aria-label="Body"
                        />
                    </div>
                    {result?.error && (
                        <Callout tone="danger" title="The request failed">
                            {result.error}
                        </Callout>
                    )}
                    {result?.status !== undefined && (
                        <div className="grid gap-2">
                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                <Tag tone={result.status < 400 ? 'success' : result.status < 500 ? 'warning' : 'danger'}>{result.status}</Tag>
                                <span className="text-fg-muted">{result.duration_ms} ms</span>
                                <span className="text-fg-faint truncate font-mono">{result.url}</span>
                                {result.truncated && <Tag tone="neutral">body truncated</Tag>}
                            </div>
                            <CodeBlock code={pretty(result.body ?? '', type) || '(empty body)'} />
                            <details className="text-xs">
                                <summary className="text-fg-muted cursor-pointer">Response headers</summary>
                                <pre className="text-fg-muted mt-1 overflow-auto font-mono">
                                    {Object.entries(result.headers ?? {})
                                        .map(([k, v]) => `${k}: ${v}`)
                                        .join('\n')}
                                </pre>
                            </details>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
