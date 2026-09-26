import { cn } from '@/lib/utils';
import { useEffect, useMemo, useRef } from 'react';
import { type OutputLine, type Phase } from '../types';

interface Props {
    lines: OutputLine[];
    server: string | null;
    phase: Phase | null;
    className?: string;
}

/**
 * Deployment output filtered by server and phase, auto-scrolling while the user stays at the bottom.
 */
export function OutputView({ lines, server, phase, className }: Props) {
    const pane = useRef<HTMLDivElement>(null);
    const stick = useRef(true);
    const visible = useMemo(
        () =>
            lines.filter(
                (line) => (server === null || line.server_id === server || line.server_id === null) && (phase === null || line.phase === phase),
            ),
        [lines, server, phase],
    );

    useEffect(() => {
        if (stick.current && pane.current) {
            pane.current.scrollTop = pane.current.scrollHeight;
        }
    }, [visible]);

    return (
        <div
            ref={pane}
            onScroll={() => {
                const el = pane.current;

                if (el) {
                    stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
                }
            }}
            className={cn(
                'max-h-[32rem] min-h-40 overflow-auto rounded-lg border bg-neutral-950 p-3 font-mono text-xs leading-relaxed text-neutral-100',
                className,
            )}
            data-testid="deployment-output"
        >
            {visible.length === 0 && <span className="text-neutral-500">No output yet.</span>}
            {visible.map((line) => (
                <div key={line.seq} className={cn('flex gap-3 whitespace-pre-wrap', line.stream === 'stderr' && 'text-red-300')}>
                    <span className="w-16 shrink-0 text-neutral-500 select-none">{new Date(line.at).toLocaleTimeString()}</span>
                    <span className="w-20 shrink-0 truncate text-neutral-400 select-none">{line.server ?? 'kiln'}</span>
                    <span className="w-20 shrink-0 text-neutral-500 select-none">{line.phase ?? ''}</span>
                    <span className="min-w-0 flex-1 break-all">{line.data.replace(/\n$/, '')}</span>
                </div>
            ))}
        </div>
    );
}
