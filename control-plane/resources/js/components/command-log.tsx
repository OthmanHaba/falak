import { LogViewer, type LogLine } from '@/components/falak/log-viewer';
import { StatusBadge } from '@/components/falak/status';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export type CommandStatus = 'queued' | 'delivered' | 'running' | 'succeeded' | 'failed' | 'timed_out' | 'cancelled';

export const TERMINAL_COMMAND_STATUSES: CommandStatus[] = ['succeeded', 'failed', 'timed_out', 'cancelled'];

interface CommandLine {
    seq: number;
    stream: string;
    data: string;
    at: string;
}

interface CommandDetails {
    id: string;
    status: CommandStatus;
    type: string;
    exit_code: number | null;
    error: string | null;
    lines: CommandLine[];
    last_seq: number;
}

interface LiveEvent {
    seq: number;
    kind: 'started' | 'output' | 'progress' | 'finished';
    stream: string | null;
    data: string | null;
    progress: number | null;
    at: string;
}

interface LivePayload {
    command_id: string;
    status: CommandStatus;
    events: LiveEvent[];
}

const STATUS_KEYS: Record<CommandStatus, { status: string; label: string }> = {
    queued: { status: 'queued', label: 'Queued' },
    delivered: { status: 'queued', label: 'Delivered' },
    running: { status: 'running', label: 'Running' },
    succeeded: { status: 'succeeded', label: 'Succeeded' },
    failed: { status: 'failed', label: 'Failed' },
    timed_out: { status: 'failed', label: 'Timed out' },
    cancelled: { status: 'cancelled', label: 'Cancelled' },
};

export function CommandStatusBadge({ status, className }: { status: CommandStatus; className?: string }) {
    const spec = STATUS_KEYS[status];

    return <StatusBadge status={spec.status} label={spec.label} className={className} />;
}

async function fetchCommand(commandId: string, after: number): Promise<CommandDetails> {
    const response = await fetch(`/fleet/commands/${commandId}?after=${after}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const body = (await response.json()) as { data: CommandDetails };

    return body.data;
}

interface CommandLogProps {
    commandId: string | null;
    className?: string;
    onStatusChange?: (status: CommandStatus) => void;
}

/**
 * Live output of one agent command: initial load via /fleet/commands/{id}, then Echo
 * (private-fleet.commands.{id}, "command.output"), falling back to polling when Echo is unavailable.
 */
export function CommandLog({ commandId, className, onStatusChange }: CommandLogProps) {
    const [lines, setLines] = useState<CommandLine[]>([]);
    const [status, setStatus] = useState<CommandStatus | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [meta, setMeta] = useState<{ exit_code: number | null; error: string | null }>({ exit_code: null, error: null });
    const lastSeq = useRef(-1);
    const statusCallback = useRef(onStatusChange);
    statusCallback.current = onStatusChange;

    const updateStatus = useCallback((next: CommandStatus) => {
        setStatus((previous) => {
            if (previous !== next) {
                statusCallback.current?.(next);
            }

            return next;
        });
    }, []);

    const append = useCallback((incoming: CommandLine[]) => {
        const fresh = incoming.filter((line) => line.seq > lastSeq.current).sort((a, b) => a.seq - b.seq);

        if (fresh.length === 0) {
            return;
        }

        lastSeq.current = fresh[fresh.length - 1].seq;
        setLines((current) => [...current, ...fresh]);
    }, []);

    const load = useCallback(async () => {
        if (!commandId) {
            return;
        }

        try {
            const details = await fetchCommand(commandId, lastSeq.current);
            append(details.lines);
            setMeta({ exit_code: details.exit_code, error: details.error });
            updateStatus(details.status);
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to load output');
        }
    }, [commandId, append, updateStatus]);

    useEffect(() => {
        lastSeq.current = -1;
        setLines([]);
        setStatus(null);
        setError(null);
        void load();
    }, [commandId, load]);

    const live = useEchoChannel<LivePayload>(commandId ? `fleet.commands.${commandId}` : null, ['command.output'], (_event, payload) => {
        const outputs = payload.events
            .filter((event) => event.kind === 'output' && event.data !== null)
            .map((event) => ({ seq: event.seq, stream: event.stream ?? 'stdout', data: event.data ?? '', at: event.at }));

        // Live events may include non-output kinds; only advance lastSeq through output lines.
        append(outputs);
        updateStatus(payload.status);

        if (TERMINAL_COMMAND_STATUSES.includes(payload.status)) {
            void load();
        }
    });

    const terminal = status !== null && TERMINAL_COMMAND_STATUSES.includes(status);

    useEffect(() => {
        if (live || !commandId || terminal) {
            return;
        }

        const timer = window.setInterval(() => void load(), 2000);

        return () => window.clearInterval(timer);
    }, [live, commandId, terminal, load]);

    const logLines = useMemo<LogLine[]>(() => {
        // Agent output arrives in chunks; split into lines, keeping stderr lines marked.
        const out: LogLine[] = [];
        let carry = '';
        let carryStream = 'stdout';

        lines.forEach((line) => {
            const parts = (carry + line.data).split('\n');
            carry = parts.pop() ?? '';
            carryStream = line.stream;
            parts.forEach((text) => out.push({ text, level: line.stream === 'stderr' ? 'warning' : undefined }));
        });

        if (carry !== '') out.push({ text: carry, level: carryStream === 'stderr' ? 'warning' : undefined });
        if (meta.error && terminal) out.push({ text: meta.error, level: 'error' });
        if (error) out.push({ text: `Could not load output: ${error}`, level: 'error' });

        return out;
    }, [lines, meta.error, terminal, error]);

    if (!commandId) {
        return null;
    }

    return (
        <div className={className} data-testid="command-log">
            <LogViewer
                lines={logLines}
                label="Command output"
                filename={`command-${commandId}.log`}
                streaming={status !== null && !terminal}
                emptyText={terminal ? 'No output.' : 'Waiting for output…'}
                height={Math.min(420, Math.max(180, logLines.length * 20 + 56))}
                toolbar={
                    <span className="flex items-center gap-2">
                        {meta.exit_code !== null && <span className="text-fg-faint tabular text-xs">exit {meta.exit_code}</span>}
                        {status && <CommandStatusBadge status={status} />}
                    </span>
                }
            />
        </div>
    );
}
