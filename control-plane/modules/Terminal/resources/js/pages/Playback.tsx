import { AppShell } from '@/components/falak/app-shell';
import { Button } from '@/components/falak/button';
import { EmptyState } from '@/components/falak/empty-state';
import { Skeleton } from '@/components/falak/skeleton';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { Download, Film, Pause, Play, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { formatDuration, onThemeChange, REASON_LABELS, TERMINAL_FONT, terminalTheme } from '../lib';
import { type TerminalSessionData } from '../types';

interface Props {
    session: TerminalSessionData;
    castUrl: string;
}

type CastEvent = [number, string, string];

interface Cast {
    width: number;
    height: number;
    events: CastEvent[];
}

const SPEEDS = [0.5, 1, 2, 4, 8];
/** Long pauses are shortened to this many seconds, like `asciinema play -i`. */
const IDLE_LIMIT = 2;

function parseCast(text: string): Cast {
    const lines = text.split('\n').filter((line) => line.trim() !== '');
    const header = JSON.parse(lines[0] ?? '{}') as { width?: number; height?: number };
    const events: CastEvent[] = [];
    let previous = 0;
    let shifted = 0;

    for (const line of lines.slice(1)) {
        const [time, kind, data] = JSON.parse(line) as CastEvent;
        shifted += Math.min(time - previous, IDLE_LIMIT);
        previous = time;
        events.push([shifted, kind, data]);
    }

    return { width: header.width ?? 80, height: header.height ?? 24, events };
}

export default function Playback({ session, castUrl }: Props) {
    const container = useRef<HTMLDivElement>(null);
    const terminal = useRef<Terminal | null>(null);
    const [cast, setCast] = useState<Cast | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [playing, setPlaying] = useState(false);
    const [speed, setSpeed] = useState(1);
    const [position, setPosition] = useState(0);
    const index = useRef(0);
    const clock = useRef(0);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Infrastructure', href: '/servers' },
        { title: session.server_name, href: `/servers/${session.server_id}` },
        { title: 'Terminal', href: `/servers/${session.server_id}/terminal` },
        { title: 'Recording', href: route('terminal.sessions.recording', session.id) },
    ];

    useEffect(() => {
        fetch(castUrl, { credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                return response.text();
            })
            .then((text) => setCast(parseCast(text)))
            .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Could not load the recording'));
    }, [castUrl]);

    useEffect(() => {
        if (!container.current || !cast) return;

        const term = new Terminal({
            cols: cast.width,
            rows: cast.height,
            disableStdin: true,
            theme: terminalTheme(),
            fontFamily: TERMINAL_FONT,
            fontSize: 13,
            lineHeight: 1.2,
            scrollback: 5000,
        });
        term.open(container.current);
        terminal.current = term;
        // Show the first frame (usually the prompt) before playback starts.
        index.current = 0;
        while (index.current < cast.events.length && cast.events[index.current][0] <= 0) {
            const [, kind, data] = cast.events[index.current];
            if (kind === 'o') term.write(data);
            index.current++;
        }
        const stopTheme = onThemeChange(() => {
            term.options.theme = terminalTheme();
        });

        return () => {
            stopTheme();
            term.dispose();
            terminal.current = null;
        };
    }, [cast]);

    const restart = useCallback(() => {
        terminal.current?.reset();

        if (cast) terminal.current?.resize(cast.width, cast.height);

        index.current = 0;
        clock.current = 0;
        setPosition(0);
    }, [cast]);

    useEffect(() => {
        if (!playing || !cast) return;

        let last = performance.now();
        let frame = 0;

        const tick = (now: number) => {
            clock.current += ((now - last) / 1000) * speed;
            last = now;

            while (index.current < cast.events.length && cast.events[index.current][0] <= clock.current) {
                const [, kind, data] = cast.events[index.current];

                if (kind === 'o') {
                    terminal.current?.write(data);
                } else if (kind === 'r') {
                    const [cols, rows] = data.split('x').map(Number);

                    if (cols > 0 && rows > 0) terminal.current?.resize(cols, rows);
                }

                index.current++;
            }

            setPosition(clock.current);

            if (index.current >= cast.events.length) {
                setPlaying(false);

                return;
            }

            frame = requestAnimationFrame(tick);
        };

        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [playing, cast, speed]);

    const duration = cast?.events.at(-1)?.[0] ?? 0;
    const finished = cast !== null && index.current >= cast.events.length && !playing && position > 0;

    const toggle = () => {
        if (finished) restart();
        setPlaying((value) => !value);
    };

    // Space toggles playback (outside form fields).
    const toggleRef = useRef(toggle);
    toggleRef.current = toggle;
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (event.key !== ' ' || target?.closest('input, textarea, button, a, [contenteditable="true"]')) return;
            event.preventDefault();
            toggleRef.current();
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const progress = duration > 0 ? Math.min(100, (position / duration) * 100) : 0;

    return (
        <AppShell breadcrumbs={breadcrumbs}>
            <Head title={`Recording · ${session.server_name}`} />
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="grid gap-0.5">
                        <h1 className="text-fg font-mono text-base font-semibold">
                            {session.unix_user}@{session.server_name}
                        </h1>
                        <p className="text-fg-muted text-sm">
                            {session.owner.name} · {new Date(session.created_at).toLocaleString()}
                            {session.close_reason && ` · ${REASON_LABELS[session.close_reason] ?? session.close_reason}`}
                        </p>
                    </div>
                    <Button asChild>
                        <a href={castUrl}>
                            <Download aria-hidden /> Download .cast
                        </a>
                    </Button>
                </div>

                <div className="border-border bg-surface-1 flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2">
                    <Button
                        variant="primary"
                        size="sm"
                        icon={playing ? <Pause /> : <Play />}
                        onClick={toggle}
                        disabled={!cast || cast.events.length === 0}
                        aria-keyshortcuts="Space"
                    >
                        {playing ? 'Pause' : finished ? 'Replay' : 'Play'}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        icon={<RotateCcw />}
                        onClick={() => {
                            setPlaying(false);
                            restart();
                        }}
                        disabled={!cast}
                    >
                        Restart
                    </Button>
                    <div className="text-fg-muted tabular flex min-w-40 flex-1 items-center gap-3 text-xs">
                        <span>{formatDuration(position)}</span>
                        <div
                            className="bg-surface-3 h-1 flex-1 overflow-hidden rounded-full"
                            role="progressbar"
                            aria-label="Playback position"
                            aria-valuenow={Math.round(progress)}
                            aria-valuemin={0}
                            aria-valuemax={100}
                        >
                            <div className="bg-primary h-full rounded-full" style={{ width: `${progress}%` }} />
                        </div>
                        <span>{formatDuration(duration)}</span>
                    </div>
                    <div className="border-border flex items-center rounded-md border p-0.5" role="radiogroup" aria-label="Playback speed">
                        {SPEEDS.map((value) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={speed === value}
                                onClick={() => setSpeed(value)}
                                className={
                                    speed === value
                                        ? 'bg-surface-3 text-fg h-6 rounded-sm px-2 text-xs font-medium'
                                        : 'text-fg-muted hover:text-fg h-6 rounded-sm px-2 text-xs font-medium'
                                }
                            >
                                {value}×
                            </button>
                        ))}
                    </div>
                </div>

                {error && (
                    <p role="alert" className="border-danger/40 bg-danger-soft text-danger rounded-lg border px-4 py-3 text-sm">
                        Could not load the recording: {error}
                    </p>
                )}
                {!cast && !error && <Skeleton className="h-80" />}
                {cast && cast.events.length === 0 && (
                    <EmptyState size="sm" icon={<Film />} title="This recording is empty" description="Nothing was printed during the session." />
                )}

                <div className={cast && cast.events.length > 0 ? 'border-border bg-canvas overflow-auto rounded-lg border p-2' : 'hidden'}>
                    <div ref={container} className="min-h-80" data-testid="playback" />
                </div>
            </div>
        </AppShell>
    );
}
