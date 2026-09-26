import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { echo } from '@/lib/echo';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { Eye, Film, Power, Share2, Users } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { SessionStatusBadge } from '../components/session-status';
import { base64ToBytes, encodeInput, postJson, REASON_LABELS, TERMINAL_THEME } from '../lib';
import { type OutputPart, type Participant, type SessionUpdate, type TerminalSessionData } from '../types';

interface Props {
    session: TerminalSessionData;
    isOwner: boolean;
    idleTimeout: number;
    inputMaxBytes: number;
    can: { type: boolean; close: boolean; share: boolean; replay: boolean };
}

interface FramesResponse {
    frames: { id: number; seq: number | null; data: string }[];
    last_id: number;
    more: boolean;
    status: TerminalSessionData['status'];
}

/** Keystrokes typed within this window are sent together. */
const INPUT_BATCH_MS = 8;
/** Flush early once this many characters are pending (UTF-8 ≤ 3 bytes/char keeps batches well under the server limit). */
const INPUT_BATCH_CHARS = 4096;

const isLive = (status: TerminalSessionData['status']) => status === 'opening' || status === 'open';

export default function Session({ session: initial, isOwner, can }: Props) {
    const [session, setSession] = useState<TerminalSessionData>(initial);
    const [participants, setParticipants] = useState<Participant[]>([]);
    const [connected, setConnected] = useState(false);
    const [inputError, setInputError] = useState<string | null>(null);
    const container = useRef<HTMLDivElement>(null);
    const terminal = useRef<Terminal | null>(null);
    const live = isLive(session.status);
    const canType = can.type && live;
    const canTypeRef = useRef(canType);
    canTypeRef.current = canType;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Terminal', href: '/terminal' },
        { title: `${session.unix_user}@${session.server_name}`, href: route('terminal.sessions.show', session.id) },
    ];

    // ---- output: catch-up through /frames, then live parts over the presence channel ----------------
    const lastSeq = useRef(-1);
    const lastPart = useRef(Number.MAX_SAFE_INTEGER);
    const caughtUp = useRef(false);
    const buffered = useRef<OutputPart[]>([]);
    const cursor = useRef(0);

    const writePart = useCallback((part: OutputPart) => {
        if (part.seq < lastSeq.current || (part.seq === lastSeq.current && part.part <= lastPart.current)) {
            return;
        }

        lastSeq.current = part.seq;
        lastPart.current = part.parts - 1 === part.part ? Number.MAX_SAFE_INTEGER : part.part;
        terminal.current?.write(base64ToBytes(part.data));
    }, []);

    const catchUp = useCallback(async () => {
        let more = true;

        while (more) {
            const response = await fetch(`${route('terminal.sessions.frames', initial.id)}?after=${cursor.current}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return;
            }

            const body = (await response.json()) as FramesResponse;
            body.frames.forEach((frame) => writePart({ seq: frame.seq ?? lastSeq.current + 1, part: 0, parts: 1, data: frame.data }));
            cursor.current = body.last_id;
            more = body.more;

            if (!more && !isLive(body.status)) {
                setSession((current) => (isLive(current.status) ? { ...current, status: body.status } : current));
            }
        }

        caughtUp.current = true;
        buffered.current.splice(0).forEach(writePart);
    }, [initial.id, writePart]);

    // ---- terminal setup -----------------------------------------------------------------------------
    const sendResize = useRef<(cols: number, rows: number) => void>(() => undefined);
    const queueInput = useRef<(text: string, binary: boolean) => void>(() => undefined);

    useEffect(() => {
        if (!container.current) return;

        const term = new Terminal({
            cursorBlink: true,
            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            fontSize: 13,
            scrollback: 5000,
            theme: TERMINAL_THEME,
            disableStdin: !canTypeRef.current,
        });
        const fit = new FitAddon();
        term.loadAddon(fit);
        term.open(container.current);
        terminal.current = term;

        try {
            fit.fit();
        } catch {
            // container not measurable yet
        }

        const data = term.onData((text) => canTypeRef.current && queueInput.current(text, false));
        const binary = term.onBinary((text) => canTypeRef.current && queueInput.current(text, true));
        const resized = term.onResize(({ cols, rows }) => sendResize.current(cols, rows));

        let fitTimer: number | undefined;
        const observer = new ResizeObserver(() => {
            window.clearTimeout(fitTimer);
            fitTimer = window.setTimeout(() => {
                try {
                    fit.fit();
                } catch {
                    // detached
                }
            }, 50);
        });
        observer.observe(container.current);

        if (canTypeRef.current) {
            term.focus();
        }

        return () => {
            window.clearTimeout(fitTimer);
            observer.disconnect();
            data.dispose();
            binary.dispose();
            resized.dispose();
            term.dispose();
            terminal.current = null;
        };
    }, []);

    useEffect(() => {
        if (terminal.current) {
            terminal.current.options.disableStdin = !canType;
            terminal.current.options.cursorBlink = canType;
        }
    }, [canType]);

    // ---- live channel ---------------------------------------------------------------------------------
    useEffect(() => {
        const client = echo();
        const name = `terminal.sessions.${initial.id}`;

        if (!client) {
            void catchUp();

            return;
        }

        const channel = client.join(name);
        channel
            .here((users: Participant[]) => {
                setParticipants(users);
                setConnected(true);
                void catchUp();
            })
            .joining((user: Participant) => setParticipants((current) => [...current.filter((u) => u.id !== user.id), user]))
            .leaving((user: Participant) => setParticipants((current) => current.filter((u) => u.id !== user.id)))
            .listen('.terminal.output', (part: OutputPart) => (caughtUp.current ? writePart(part) : buffered.current.push(part)))
            .listen('.terminal.session.updated', (update: SessionUpdate) => setSession((current) => ({ ...current, ...update })));

        return () => {
            client.leave(name);
        };
    }, [initial.id, catchUp, writePart]);

    // Without Reverb, poll for new frames while the session is live.
    useEffect(() => {
        if (connected || !live) return;

        const timer = window.setInterval(() => void catchUp(), 1000);

        return () => window.clearInterval(timer);
    }, [connected, live, catchUp]);

    // ---- input: batched, one request in flight, ordered by seq ------------------------------------------
    const pending = useRef<{ text: string; binary: boolean }[]>([]);
    const pendingChars = useRef(0);
    const inFlight = useRef(false);
    const seq = useRef(0);
    const flushTimer = useRef<number | undefined>(undefined);

    const flush = useCallback(async () => {
        window.clearTimeout(flushTimer.current);
        flushTimer.current = undefined;

        if (inFlight.current || pending.current.length === 0) return;

        const batch = pending.current.splice(0);
        pendingChars.current = 0;
        inFlight.current = true;
        const body = { data: encodeInput(batch), seq: seq.current++ };

        try {
            for (let attempt = 0; attempt < 3; attempt++) {
                let response: Response;

                try {
                    response = await postJson(route('terminal.sessions.input', initial.id), body);
                } catch {
                    // Network hiccup: retry with the same seq (the server dedupes on it).
                    await new Promise((resolve) => window.setTimeout(resolve, 200 * (attempt + 1)));
                    continue;
                }

                if (response.status === 429) {
                    await new Promise((resolve) => window.setTimeout(resolve, 250 * (attempt + 1)));
                    continue;
                }

                setInputError(
                    response.ok ? null : response.status === 409 ? 'The session is closed.' : `Input was rejected (HTTP ${response.status}).`,
                );
                break;
            }
        } finally {
            inFlight.current = false;

            if (pending.current.length > 0) {
                void flush();
            }
        }
    }, [initial.id]);

    queueInput.current = (text: string, binary: boolean) => {
        // Split big pastes so each request stays under the server's batch limit.
        for (let offset = 0; offset < text.length; offset += INPUT_BATCH_CHARS) {
            const slice = text.slice(offset, offset + INPUT_BATCH_CHARS);
            pending.current.push({ text: slice, binary });
            pendingChars.current += slice.length;

            if (pendingChars.current >= INPUT_BATCH_CHARS) {
                void flush();
            }
        }

        if (flushTimer.current === undefined) {
            flushTimer.current = window.setTimeout(() => void flush(), INPUT_BATCH_MS);
        }
    };

    const resizeTimer = useRef<number | undefined>(undefined);
    sendResize.current = (cols: number, rows: number) => {
        if (!canTypeRef.current) return;

        window.clearTimeout(resizeTimer.current);
        resizeTimer.current = window.setTimeout(() => {
            void postJson(route('terminal.sessions.resize', initial.id), { cols, rows });
        }, 250);
    };

    // Once the PTY is open, sync it to the fitted size.
    const syncedOpen = useRef(false);
    useEffect(() => {
        if (session.status === 'open' && !syncedOpen.current && terminal.current) {
            syncedOpen.current = true;
            const { cols, rows } = terminal.current;

            if (cols !== session.cols || rows !== session.rows) {
                sendResize.current(cols, rows);
            }
        }
    }, [session.status, session.cols, session.rows]);

    const toggleShare = () => router.patch(route('terminal.sessions.share', session.id), { shared: !session.shared }, { preserveScroll: true });
    const close = () => router.delete(route('terminal.sessions.destroy', session.id), { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Terminal · ${session.server_name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="font-mono text-lg font-semibold">
                            {session.unix_user}@{session.server_name}
                        </h1>
                        <SessionStatusBadge status={session.status} />
                        {session.shared && (
                            <Badge variant="outline" className="gap-1">
                                <Share2 className="size-3" /> Shared
                            </Badge>
                        )}
                        {!can.type && live && (
                            <Badge variant="outline" className="gap-1">
                                <Eye className="size-3" /> Watching
                            </Badge>
                        )}
                        {!isOwner && <span className="text-muted-foreground text-sm">Owner: {session.owner.name}</span>}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {participants.length > 0 && (
                            <span
                                className="text-muted-foreground inline-flex items-center gap-1.5 text-sm"
                                title={participants.map((p) => p.name).join(', ')}
                            >
                                <Users className="size-4" />
                                {participants.map((p) => p.name + (p.can_type ? '' : ' (view)')).join(', ')}
                            </span>
                        )}
                        {can.share && live && (
                            <Button variant="outline" size="sm" onClick={toggleShare}>
                                <Share2 /> {session.shared ? 'Stop sharing' : 'Share'}
                            </Button>
                        )}
                        {can.replay && !live && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('terminal.sessions.recording', session.id)}>
                                    <Film /> Replay
                                </Link>
                            </Button>
                        )}
                        {can.close && live && (
                            <Button variant="destructive" size="sm" onClick={close}>
                                <Power /> Close
                            </Button>
                        )}
                    </div>
                </div>

                {!live && (
                    <Alert variant={session.status === 'failed' ? 'destructive' : 'default'}>
                        <AlertTitle>
                            {session.close_reason ? (REASON_LABELS[session.close_reason] ?? session.close_reason) : 'Session ended'}
                        </AlertTitle>
                        <AlertDescription>
                            {session.error ?? (session.exit_code !== null ? `Exit code ${session.exit_code}.` : 'The session is no longer running.')}
                        </AlertDescription>
                    </Alert>
                )}
                {inputError && live && <p className="text-sm text-red-600 dark:text-red-400">{inputError}</p>}
                {session.shared && isOwner && live && (
                    <p className="text-muted-foreground text-xs">
                        Members with terminal access can watch this session; only you and administrators can type.
                    </p>
                )}

                <div className="overflow-hidden rounded-lg border bg-neutral-950 p-2">
                    <div ref={container} className="h-[70vh] min-h-80 w-full" data-testid="terminal" />
                </div>
            </div>
        </AppLayout>
    );
}
