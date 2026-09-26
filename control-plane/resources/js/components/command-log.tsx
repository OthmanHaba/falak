import { Badge } from '@/components/ui/badge';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { cn } from '@/lib/utils';
import { useCallback, useEffect, useRef, useState } from 'react';

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

const STATUS_STYLES: Record<CommandStatus, string> = {
    queued: 'bg-muted text-muted-foreground',
    delivered: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    running: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    timed_out: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    cancelled: 'bg-muted text-muted-foreground',
};

export function CommandStatusBadge({ status, className }: { status: CommandStatus; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', STATUS_STYLES[status], className)}>
            {status.replace('_', ' ')}
        </Badge>
    );
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
    const pane = useRef<HTMLDivElement>(null);
    const stick = useRef(true);
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

    useEffect(() => {
        if (stick.current && pane.current) {
            pane.current.scrollTop = pane.current.scrollHeight;
        }
    }, [lines]);

    const onScroll = () => {
        const el = pane.current;

        if (el) {
            stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        }
    };

    if (!commandId) {
        return null;
    }

    return (
        <div className={cn('overflow-hidden rounded-lg border', className)}>
            <div className="bg-muted/40 flex items-center justify-between gap-2 border-b px-3 py-2 text-xs">
                <span className="text-muted-foreground font-mono">{commandId}</span>
                <div className="flex items-center gap-2">
                    {meta.exit_code !== null && <span className="text-muted-foreground">exit {meta.exit_code}</span>}
                    {status && <CommandStatusBadge status={status} />}
                </div>
            </div>
            <div
                ref={pane}
                onScroll={onScroll}
                className="max-h-96 min-h-24 overflow-auto bg-neutral-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-neutral-100"
                data-testid="command-log"
            >
                {lines.length === 0 && !error && <span className="text-neutral-500">{terminal ? 'No output.' : 'Waiting for output…'}</span>}
                {lines.map((line) => (
                    <span key={line.seq} className={line.stream === 'stderr' ? 'text-amber-300' : undefined}>
                        {line.data}
                    </span>
                ))}
                {meta.error && terminal && <div className="mt-2 text-red-400">{meta.error}</div>}
                {error && <div className="text-red-400">Could not load output: {error}</div>}
            </div>
        </div>
    );
}
