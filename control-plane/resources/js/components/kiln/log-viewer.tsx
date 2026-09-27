import { cn } from '@/lib/utils';
import { useVirtualizer } from '@tanstack/react-virtual';
import Anser from 'anser';
import { ArrowDown, ChevronDown, ChevronUp, Copy, Download, Search, WrapText } from 'lucide-react';
import { memo, useCallback, useDeferredValue, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Button, IconButton } from './button';
import { copyText } from './copy-button';
import { toast } from './toast';

export interface LogLine {
    text: string;
    /** ISO timestamp or preformatted time, shown in a faint gutter. */
    time?: string;
    /** Phase/section this line belongs to; the current one is shown as a sticky header. */
    phase?: string;
    level?: 'debug' | 'info' | 'warning' | 'error';
}

export interface LogViewerProps {
    lines: (string | LogLine)[];
    /** Accessible name, also used for the download filename. */
    label?: string;
    filename?: string;
    /** Auto-scroll to new lines (user scrolling up pauses; "Jump to live" resumes). */
    follow?: boolean;
    /** Show a "live" indicator. */
    streaming?: boolean;
    lineNumbers?: boolean;
    /** Fixed height of the viewport; defaults to filling the parent (parent must have a height). */
    height?: number | string;
    emptyText?: ReactNode;
    onLineClick?: (line: LogLine, index: number) => void;
    /** Extra toolbar controls (level filter, server filter…). */
    toolbar?: ReactNode;
    className?: string;
}

const ROW_HEIGHT = 20;

// eslint-disable-next-line no-control-regex
const ANSI_PATTERN = /\u001b\[[0-9;]*[A-Za-z]/g;

export function stripAnsi(text: string): string {
    return text.replace(ANSI_PATTERN, '');
}

function ansiColor(name: string | null): string | undefined {
    if (!name) return undefined;
    const match = /^ansi-(bright-)?(black|red|green|yellow|blue|magenta|cyan|white)$/.exec(name);
    if (!match) return undefined;
    if (match[2] === 'black' && match[1]) return 'var(--ansi-bright-black)';

    return `var(--ansi-${match[2]})`;
}

const AnsiText = memo(function AnsiText({ text, highlight }: { text: string; highlight: string }) {
    const parts = useMemo(() => {
        if (!text.includes('\u001b[')) return [{ content: text, style: undefined as CSSProperties | undefined }];

        return Anser.ansiToJson(text, { use_classes: true, remove_empty: true }).map((entry) => {
            const style: CSSProperties = {};
            const fg = ansiColor(entry.fg);
            const bg = ansiColor(entry.bg);
            if (fg) style.color = fg;
            if (bg) style.backgroundColor = bg;
            if (entry.decorations.includes('bold')) style.fontWeight = 600;
            if (entry.decorations.includes('dim')) style.opacity = 0.6;
            if (entry.decorations.includes('italic')) style.fontStyle = 'italic';
            if (entry.decorations.includes('underline')) style.textDecoration = 'underline';

            return { content: entry.content, style };
        });
    }, [text]);

    return (
        <>
            {parts.map((part, index) => (
                <span key={index} style={part.style}>
                    {highlight ? <Highlighted text={part.content} query={highlight} /> : part.content}
                </span>
            ))}
        </>
    );
});

function Highlighted({ text, query }: { text: string; query: string }) {
    const lower = text.toLowerCase();
    const needle = query.toLowerCase();
    const out: ReactNode[] = [];
    let from = 0;
    let at = lower.indexOf(needle);

    while (at !== -1) {
        if (at > from) out.push(text.slice(from, at));
        out.push(
            <mark key={at} className="bg-warning-soft text-fg outline-warning/50 rounded-sm outline">
                {text.slice(at, at + needle.length)}
            </mark>,
        );
        from = at + needle.length;
        at = lower.indexOf(needle, from);
    }
    if (from < text.length) out.push(text.slice(from));

    return <>{out}</>;
}

const LEVEL_TONE: Record<NonNullable<LogLine['level']>, string> = {
    debug: 'text-fg-faint',
    info: '',
    warning: 'bg-warning-soft/40',
    error: 'bg-danger-soft',
};

/**
 * Virtualized monospace log (JetBrains Mono 12px): ANSI colors, line numbers, search with match navigation,
 * sticky phase header, copy, download and auto-follow with "Jump to live".
 */
export function LogViewer({
    lines: input,
    label = 'Log output',
    filename,
    follow = true,
    streaming = false,
    lineNumbers = true,
    height,
    emptyText = 'No output yet.',
    onLineClick,
    toolbar,
    className,
}: LogViewerProps) {
    const lines = useMemo<LogLine[]>(() => input.map((line) => (typeof line === 'string' ? { text: line } : line)), [input]);
    const plain = useMemo(() => lines.map((line) => stripAnsi(line.text)), [lines]);

    const scrollRef = useRef<HTMLDivElement>(null);
    const [following, setFollowing] = useState(follow);
    const [wrap, setWrap] = useState(false);
    const [query, setQuery] = useState('');
    const deferredQuery = useDeferredValue(query.trim());
    const [matchIndex, setMatchIndex] = useState(0);

    const matches = useMemo(() => {
        if (!deferredQuery) return [];
        const needle = deferredQuery.toLowerCase();

        return plain.reduce<number[]>((acc, text, index) => (text.toLowerCase().includes(needle) ? [...acc, index] : acc), []);
    }, [plain, deferredQuery]);

    const virtualizer = useVirtualizer({
        count: lines.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => ROW_HEIGHT,
        overscan: 20,
        measureElement: wrap ? (element) => element.getBoundingClientRect().height : undefined,
    });

    // Follow new output.
    useEffect(() => {
        if (following && lines.length > 0) {
            virtualizer.scrollToIndex(lines.length - 1, { align: 'end' });
        }
    }, [lines.length, following, virtualizer]);

    useEffect(() => {
        setMatchIndex(0);
    }, [deferredQuery]);

    useEffect(() => {
        if (matches.length > 0) {
            setFollowing(false);
            virtualizer.scrollToIndex(matches[Math.min(matchIndex, matches.length - 1)], { align: 'center' });
        }
    }, [matches, matchIndex, virtualizer]);

    const onScroll = useCallback(() => {
        const element = scrollRef.current;
        if (!element) return;
        const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < ROW_HEIGHT * 2;
        setFollowing(atBottom);
    }, []);

    const items = virtualizer.getVirtualItems();
    const firstVisible = items.find((item) => item.start >= (scrollRef.current?.scrollTop ?? 0)) ?? items[0];
    const currentPhase = firstVisible ? lines[firstVisible.index]?.phase : undefined;
    const gutter = String(lines.length).length;

    const copyAll = async () => {
        if (await copyText(plain.join('\n'))) toast.success('Log copied to clipboard');
        else toast.error('Could not copy the log');
    };

    const download = () => {
        const blob = new Blob([plain.join('\n')], { type: 'text/plain' });
        const href = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.download = filename ?? `${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.log`;
        anchor.click();
        URL.revokeObjectURL(href);
    };

    const step = (delta: number) => {
        if (matches.length === 0) return;
        setMatchIndex((index) => (index + delta + matches.length) % matches.length);
    };

    return (
        <div
            className={cn('border-border bg-canvas flex min-h-0 flex-col overflow-hidden rounded-lg border', className)}
            style={height !== undefined ? { height } : undefined}
        >
            <div className="border-border flex flex-wrap items-center gap-1.5 border-b px-2 py-1.5">
                <div className="relative min-w-40 flex-1">
                    <Search className="text-fg-faint pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2" aria-hidden />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                step(event.shiftKey ? -1 : 1);
                            }
                        }}
                        placeholder="Search logs"
                        aria-label={`Search ${label}`}
                        className="bg-surface-2 text-fg placeholder:text-fg-faint focus-visible:border-border-strong focus-visible:outline-primary h-7 w-full rounded-md border border-transparent pr-2 pl-7 text-xs focus-visible:outline-2 focus-visible:outline-offset-2"
                    />
                </div>
                {deferredQuery && (
                    <div className="text-fg-muted tabular flex items-center gap-0.5 text-xs" aria-live="polite">
                        {matches.length === 0 ? 'No matches' : `${Math.min(matchIndex + 1, matches.length)} / ${matches.length}`}
                        <IconButton size="sm" label="Previous match" icon={<ChevronUp />} onClick={() => step(-1)} disabled={matches.length === 0} />
                        <IconButton size="sm" label="Next match" icon={<ChevronDown />} onClick={() => step(1)} disabled={matches.length === 0} />
                    </div>
                )}
                {toolbar}
                {streaming && (
                    <span className="text-fg-muted flex items-center gap-1.5 px-1 text-xs">
                        <span className="animate-pulse-dot bg-success text-success size-1.5 rounded-full" aria-hidden />
                        Live
                    </span>
                )}
                <IconButton
                    size="sm"
                    label={wrap ? 'Disable line wrap' : 'Wrap lines'}
                    icon={<WrapText />}
                    onClick={() => setWrap((value) => !value)}
                    aria-pressed={wrap}
                />
                <IconButton size="sm" label="Copy log" icon={<Copy />} onClick={() => void copyAll()} disabled={lines.length === 0} />
                <IconButton size="sm" label="Download log" icon={<Download />} onClick={download} disabled={lines.length === 0} />
            </div>

            <div className="relative min-h-0 flex-1">
                {currentPhase && (
                    <div className="border-border bg-surface-1/95 text-2xs text-fg-muted absolute inset-x-0 top-0 z-10 border-b px-3 py-1 font-mono font-medium tracking-wide uppercase backdrop-blur">
                        {currentPhase}
                    </div>
                )}
                <div
                    ref={scrollRef}
                    onScroll={onScroll}
                    role="log"
                    aria-label={label}
                    aria-live={following ? 'polite' : 'off'}
                    tabIndex={0}
                    className="text-fg h-full min-h-40 overflow-auto font-mono text-xs leading-5"
                >
                    {lines.length === 0 ? (
                        <p className="text-fg-faint p-4">{emptyText}</p>
                    ) : (
                        <div
                            style={{
                                height: virtualizer.getTotalSize(),
                                position: 'relative',
                                minWidth: '100%',
                                width: wrap ? '100%' : 'max-content',
                            }}
                        >
                            {items.map((item) => {
                                const line = lines[item.index];
                                const isMatch = matches.length > 0 && matches[matchIndex] === item.index;

                                return (
                                    <div
                                        key={item.key}
                                        data-index={item.index}
                                        ref={wrap ? virtualizer.measureElement : undefined}
                                        onClick={onLineClick ? () => onLineClick(line, item.index) : undefined}
                                        className={cn(
                                            'hover:bg-surface-2/60 absolute left-0 flex w-full min-w-max',
                                            wrap && 'min-w-0',
                                            line.level && LEVEL_TONE[line.level],
                                            isMatch && 'bg-primary-soft',
                                            onLineClick && 'cursor-pointer',
                                        )}
                                        style={{ transform: `translateY(${item.start}px)`, minHeight: ROW_HEIGHT }}
                                    >
                                        {lineNumbers && (
                                            <span
                                                className="bg-canvas text-fg-faint sticky left-0 shrink-0 pr-3 pl-3 text-right select-none"
                                                style={{ width: `${gutter + 3}ch` }}
                                                aria-hidden
                                            >
                                                {item.index + 1}
                                            </span>
                                        )}
                                        {line.time && <span className="text-fg-faint shrink-0 pr-3 select-none">{line.time}</span>}
                                        <span className={cn('pr-4', wrap ? 'min-w-0 break-all whitespace-pre-wrap' : 'whitespace-pre')}>
                                            <AnsiText text={line.text} highlight={deferredQuery} />
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
                {!following && lines.length > 0 && (
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<ArrowDown />}
                        className="shadow-panel absolute right-3 bottom-3 z-10"
                        onClick={() => {
                            setFollowing(true);
                            virtualizer.scrollToIndex(lines.length - 1, { align: 'end' });
                        }}
                    >
                        Jump to live
                    </Button>
                )}
            </div>
        </div>
    );
}
