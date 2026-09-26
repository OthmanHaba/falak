import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { Download, Pause, Play, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { formatDuration, REASON_LABELS, TERMINAL_THEME } from '../lib';
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
        { title: 'Terminal', href: '/terminal' },
        { title: `Recording · ${session.server_name}`, href: route('terminal.sessions.recording', session.id) },
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

        const term = new Terminal({ cols: cast.width, rows: cast.height, disableStdin: true, theme: TERMINAL_THEME, fontSize: 13, scrollback: 5000 });
        term.open(container.current);
        terminal.current = term;

        return () => {
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Recording · ${session.server_name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-mono text-lg font-semibold">
                            {session.unix_user}@{session.server_name}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {session.owner.name} · {new Date(session.created_at).toLocaleString()}
                            {session.close_reason && ` · ${REASON_LABELS[session.close_reason] ?? session.close_reason}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" onClick={toggle} disabled={!cast || cast.events.length === 0}>
                            {playing ? <Pause /> : <Play />} {playing ? 'Pause' : 'Play'}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                setPlaying(false);
                                restart();
                            }}
                            disabled={!cast}
                        >
                            <RotateCcw /> Restart
                        </Button>
                        <div className="flex items-center gap-1" role="group" aria-label="Playback speed">
                            {SPEEDS.map((value) => (
                                <Button key={value} size="sm" variant={speed === value ? 'secondary' : 'ghost'} onClick={() => setSpeed(value)}>
                                    {value}×
                                </Button>
                            ))}
                        </div>
                        <Button size="sm" variant="outline" asChild>
                            <a href={castUrl}>
                                <Download /> .cast
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="text-muted-foreground flex items-center gap-3 text-xs tabular-nums">
                    <span>{formatDuration(position)}</span>
                    <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                        <div className="bg-primary h-full" style={{ width: `${duration > 0 ? Math.min(100, (position / duration) * 100) : 0}%` }} />
                    </div>
                    <span>{formatDuration(duration)}</span>
                </div>

                {error && <p className="text-sm text-red-600 dark:text-red-400">Could not load the recording: {error}</p>}
                {cast && cast.events.length === 0 && <p className="text-muted-foreground text-sm">This recording is empty.</p>}

                <div className="overflow-auto rounded-lg border bg-neutral-950 p-2">
                    <div ref={container} className="min-h-80" data-testid="playback" />
                </div>
            </div>
        </AppLayout>
    );
}
