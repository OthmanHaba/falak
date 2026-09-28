import { cn } from '@/lib/utils';
import { useVirtualizer } from '@tanstack/react-virtual';
import Anser from 'anser';
import { ArrowDown, ArrowUp, Copy, Download, ExternalLink, Search, Settings2 } from 'lucide-react';
import { memo, useCallback, useDeferredValue, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { IconButton } from './button';
import { copyText } from './copy-button';
import { Kbd } from './kbd';
import { MenuCheckboxItem, MenuContent, MenuItem, MenuLabel, MenuRoot, MenuSeparator, MenuTrigger } from './menu';
import { toast } from './toast';

export interface LogLine {
    text: string;
    /** ISO timestamp (shown as local "YYYY-MM-DD HH:mm:ss") or a preformatted time. */
    time?: string;
    /** Phase/section this line belongs to; a header row starts each group, the current one sticks to the top. */
    phase?: string;
    level?: 'debug' | 'info' | 'warning' | 'error';
    /** stderr lines get the error bar and tint. */
    stream?: 'stdout' | 'stderr';
}

export interface LogViewerProps {
    lines: (string | LogLine)[];
    /** Accessible name, also used for the download filename. */
    label?: string;
    filename?: string;
    /** Auto-scroll to new lines (user scrolling up pauses; the ↓ button resumes). */
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
    /** Show a pop-out button (open the log on its own page / tab). */
    onPopOut?: () => void;
    /**
     * `card`: bordered box (inside pages and tabs). `flush`: fills its container edge to edge (stacked log panels) —
     * the parent provides the frame.
     */
    variant?: 'card' | 'flush';
    /** Press `/` to focus the filter (only in the top-most panel, or outside panels). Default true. */
    searchShortcut?: boolean;
    className?: string;
}

const ROW_HEIGHT = 26;
const PREFS = 'kiln:log-viewer';

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

/** Left bar + row tint per line: blue for output, amber for warnings, red (+ faint tint) for errors / stderr. */
function toneOf(line: LogLine): { bar: string; row: string } {
    if (line.level === 'error' || line.stream === 'stderr') return { bar: 'bg-danger', row: 'bg-danger-soft/50' };
    if (line.level === 'warning') return { bar: 'bg-warning', row: 'bg-warning-soft/30' };
    if (line.level === 'debug') return { bar: 'bg-fg-faint/40', row: 'text-fg-muted' };

    return { bar: 'bg-info/80', row: '' };
}

const pad = (value: number) => String(value).padStart(2, '0');

/** ["2026-09-28", "17:35:43"] in the viewer's time zone for ISO timestamps; anything else is shown as given. */
function timeParts(value: string): [string, string] {
    if (!/^\d{4}-\d{2}-\d{2}T/.test(value)) return ['', value];
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return ['', value];

    return [
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
        `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`,
    ];
}

function formatTime(value: string): string {
    return timeParts(value).filter(Boolean).join(' ');
}

/** "GMT+2" for the header of the time column. */
export function timeZoneLabel(): string {
    const offset = -new Date().getTimezoneOffset();
    if (offset === 0) return 'UTC';
    const hours = Math.trunc(Math.abs(offset) / 60);
    const minutes = Math.abs(offset) % 60;

    return `GMT${offset > 0 ? '+' : '-'}${hours}${minutes ? `:${pad(minutes)}` : ''}`;
}

function readPrefs(): { wrap: boolean; timestamps: boolean; numbers: boolean | null } {
    try {
        return { wrap: true, timestamps: true, numbers: null, ...JSON.parse(window.localStorage.getItem(PREFS) ?? '{}') };
    } catch {
        return { wrap: true, timestamps: true, numbers: null };
    }
}

function writePrefs(prefs: { wrap: boolean; timestamps: boolean; numbers: boolean | null }) {
    try {
        window.localStorage.setItem(PREFS, JSON.stringify(prefs));
    } catch {
        // Not remembered.
    }
}

type Row = { kind: 'header'; phase: string } | { kind: 'line'; index: number };

/**
 * Virtualized log table (JetBrains Mono 12px): a time column in the viewer's time zone and the data, a 3px colour bar
 * per line (output / warning / error + stderr tint), ANSI colours, group headers per phase, filter-as-you-type with
 * highlights (`/` focuses it), settings (wrap, timestamps, line numbers, copy), download, optional pop-out, and
 * live-follow with a floating "scroll to bottom / top" button.
 */
export function LogViewer({
    lines: input,
    label = 'Log output',
    filename,
    follow = true,
    streaming = false,
    lineNumbers,
    height,
    emptyText = 'No output yet.',
    onLineClick,
    toolbar,
    onPopOut,
    variant = 'card',
    searchShortcut = true,
    className,
}: LogViewerProps) {
    const lines = useMemo<LogLine[]>(() => input.map((line) => (typeof line === 'string' ? { text: line } : line)), [input]);
    const plain = useMemo(() => lines.map((line) => stripAnsi(line.text)), [lines]);

    const rootRef = useRef<HTMLDivElement>(null);
    const scrollRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [following, setFollowing] = useState(follow);
    const [atTop, setAtTop] = useState(true);
    const [prefs, setPrefs] = useState(readPrefs);
    const [query, setQuery] = useState('');
    const filter = useDeferredValue(query.trim());
    const showNumbers = prefs.numbers ?? lineNumbers ?? false;
    const hasTime = lines.some((line) => line.time);
    const showTime = hasTime && prefs.timestamps;
    const isoTimes = hasTime && lines.some((line) => line.time && /^\d{4}-\d{2}-\d{2}T/.test(line.time));

    useEffect(() => setFollowing(follow), [follow]);

    const update = (patch: Partial<typeof prefs>) =>
        setPrefs((current) => {
            const next = { ...current, ...patch };
            writePrefs(next);

            return next;
        });

    // Rows = matching lines plus a header wherever the phase (e.g. "app-2 · fetch") changes.
    const { rows, matches } = useMemo(() => {
        const needle = filter.toLowerCase();
        const out: Row[] = [];
        let count = 0;
        let previous: string | undefined;
        lines.forEach((line, index) => {
            if (needle && !plain[index].toLowerCase().includes(needle)) return;
            count++;
            if (line.phase && line.phase !== previous) out.push({ kind: 'header', phase: line.phase });
            previous = line.phase ?? previous;
            out.push({ kind: 'line', index });
        });

        return { rows: out, matches: count };
    }, [lines, plain, filter]);
    const lastRow = rows.length - 1;

    const virtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => ROW_HEIGHT,
        overscan: 24,
        measureElement: prefs.wrap ? (element) => element.getBoundingClientRect().height : undefined,
    });

    // Follow new output.
    useEffect(() => {
        if (following && lastRow >= 0) virtualizer.scrollToIndex(lastRow, { align: 'end' });
    }, [lastRow, following, virtualizer]);

    // A new filter starts at the newest match.
    useEffect(() => {
        if (filter && lastRow >= 0) virtualizer.scrollToIndex(lastRow, { align: 'end' });
    }, [filter, lastRow, virtualizer]);

    // `/` focuses the filter when this viewer is in the top-most panel (or not in a panel at all).
    useEffect(() => {
        if (!searchShortcut) return;
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            const panel = rootRef.current?.closest<HTMLElement>('[data-kiln-panel]');
            if (panel ? panel.dataset.depth !== '0' : document.querySelector('[data-kiln-panel][data-depth="0"]')) return;
            if (!rootRef.current?.offsetParent) return;
            event.preventDefault();
            searchRef.current?.focus();
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [searchShortcut]);

    const onScroll = useCallback(() => {
        const element = scrollRef.current;
        if (!element) return;
        setFollowing(element.scrollHeight - element.scrollTop - element.clientHeight < ROW_HEIGHT * 2);
        setAtTop(element.scrollTop < ROW_HEIGHT * 4);
    }, []);

    const items = virtualizer.getVirtualItems();
    const scrollTop = virtualizer.scrollOffset ?? scrollRef.current?.scrollTop ?? 0;
    // Group of the topmost visible row: the last header at or above it (repeated as a sticky header).
    const topRow = items.find((item) => item.end > scrollTop)?.index ?? 0;
    let currentPhase: string | undefined;
    for (let index = Math.min(topRow, lastRow); index >= 0; index--) {
        const row = rows[index];
        if (row.kind === 'header') {
            currentPhase = row.phase;
            break;
        }
        const phase = lines[row.index]?.phase;
        if (phase) {
            currentPhase = phase;
            break;
        }
    }
    const inlineHeaderOnTop = rows[topRow]?.kind === 'header' || scrollTop < 1;
    const gutter = String(lines.length).length;

    const copyAll = async () => {
        if (await copyText(plain.join('\n'))) toast.success('Log copied to clipboard');
        else toast.error('Could not copy the log');
    };

    const download = () => {
        const blob = new Blob([lines.map((line, index) => (line.time ? `${formatTime(line.time)}  ${plain[index]}` : plain[index])).join('\n')], {
            type: 'text/plain',
        });
        const href = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.download = filename ?? `${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.log`;
        anchor.click();
        URL.revokeObjectURL(href);
    };

    const flush = variant === 'flush';
    const scrollable = lines.length > 0 && virtualizer.getTotalSize() > (scrollRef.current?.clientHeight ?? Infinity);

    return (
        <div
            ref={rootRef}
            className={cn(
                'flex min-h-0 flex-col overflow-hidden',
                flush ? 'bg-surface-1 flex-1' : 'border-border bg-surface-1 rounded-lg border',
                className,
            )}
            style={height !== undefined ? { height } : undefined}
        >
            <div className={cn('flex flex-wrap items-center gap-1.5', flush ? 'px-5 pt-1 pb-3 sm:px-7' : 'border-border border-b p-2')}>
                <div className="relative min-w-48 flex-1">
                    <Search className="text-fg-faint pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" aria-hidden />
                    <input
                        ref={searchRef}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Filter and search logs"
                        aria-label={`Filter ${label}`}
                        className="border-border bg-surface-1 text-fg placeholder:text-fg-faint hover:border-border-strong focus-visible:border-border-strong focus-visible:outline-primary h-8 w-full rounded-md border pr-16 pl-8 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 [&::-webkit-search-cancel-button]:hidden"
                    />
                    <span className="pointer-events-none absolute top-1/2 right-2 flex -translate-y-1/2 items-center gap-1.5">
                        {filter ? (
                            <span className="text-fg-muted tabular text-xs" aria-live="polite">
                                {matches} {matches === 1 ? 'match' : 'matches'}
                            </span>
                        ) : (
                            searchShortcut && <Kbd aria-hidden>/</Kbd>
                        )}
                    </span>
                </div>
                {toolbar}
                {streaming && (
                    <span className="text-fg-muted flex items-center gap-1.5 px-1.5 text-xs" data-testid="log-live">
                        <span className="animate-pulse-dot bg-success text-success size-1.5 rounded-full" aria-hidden />
                        Live
                    </span>
                )}
                <IconButton variant="secondary" label="Download log" icon={<Download />} onClick={download} disabled={lines.length === 0} />
                {onPopOut && <IconButton variant="secondary" label="Open in a new tab" icon={<ExternalLink />} onClick={onPopOut} />}
            </div>

            <div className={cn('relative flex min-h-0 flex-1 flex-col', flush ? 'border-border border-t' : '')}>
                <div
                    className="text-fg-muted border-border bg-surface-1 flex h-8 shrink-0 items-center border-b pr-2 text-xs font-medium"
                    role="presentation"
                >
                    <span className={cn('shrink-0', flush ? 'pl-5 sm:pl-7' : 'pl-3')} style={{ width: showNumbers ? `${gutter + 3}ch` : undefined }}>
                        {showNumbers && '#'}
                    </span>
                    {showTime && (
                        <span className="w-20 shrink-0 truncate pl-2 sm:w-44" data-testid="log-time-header">
                            Time{isoTimes && <span className="hidden sm:inline"> ({timeZoneLabel()})</span>}
                        </span>
                    )}
                    <span className="min-w-0 flex-1 pl-2">Data</span>
                    <MenuRoot>
                        <MenuTrigger asChild>
                            <IconButton size="sm" label="Log settings" tooltip={false} icon={<Settings2 />} />
                        </MenuTrigger>
                        <MenuContent className="w-52">
                            <MenuLabel>Display</MenuLabel>
                            <MenuCheckboxItem checked={prefs.wrap} onCheckedChange={(value) => update({ wrap: value === true })}>
                                Wrap long lines
                            </MenuCheckboxItem>
                            {hasTime && (
                                <MenuCheckboxItem checked={prefs.timestamps} onCheckedChange={(value) => update({ timestamps: value === true })}>
                                    Show timestamps
                                </MenuCheckboxItem>
                            )}
                            <MenuCheckboxItem checked={showNumbers} onCheckedChange={(value) => update({ numbers: value === true })}>
                                Line numbers
                            </MenuCheckboxItem>
                            <MenuSeparator />
                            <MenuItem icon={<Copy />} disabled={lines.length === 0} onSelect={() => void copyAll()}>
                                Copy log
                            </MenuItem>
                        </MenuContent>
                    </MenuRoot>
                </div>

                {currentPhase && !inlineHeaderOnTop && (
                    <div
                        className={cn(
                            'border-border bg-surface-2 text-2xs text-fg-muted absolute inset-x-0 top-8 z-10 border-b py-1 pr-3 font-mono font-medium tracking-wide uppercase',
                            flush ? 'pl-5 sm:pl-7' : 'pl-3',
                        )}
                        aria-hidden
                    >
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
                    className="text-fg min-h-40 flex-1 overflow-auto font-mono text-xs leading-[18px] outline-none"
                >
                    {rows.length === 0 ? (
                        <p className={cn('text-fg-faint py-4 font-sans text-sm', flush ? 'px-5 sm:px-7' : 'px-4')}>
                            {lines.length > 0 && filter ? `No lines match “${filter}”.` : emptyText}
                        </p>
                    ) : (
                        <div
                            style={{
                                height: virtualizer.getTotalSize(),
                                position: 'relative',
                                minWidth: '100%',
                                width: prefs.wrap ? '100%' : 'max-content',
                            }}
                        >
                            {items.map((item) => {
                                const row = rows[item.index];
                                if (row.kind === 'header') {
                                    return (
                                        <div
                                            key={item.key}
                                            data-index={item.index}
                                            ref={prefs.wrap ? virtualizer.measureElement : undefined}
                                            role="separator"
                                            aria-label={row.phase}
                                            className={cn(
                                                'border-border bg-surface-2 text-2xs text-fg-muted absolute left-0 flex w-full min-w-max items-center border-y pr-3 font-mono font-medium tracking-wide uppercase',
                                                flush ? 'pl-5 sm:pl-7' : 'pl-3',
                                            )}
                                            style={{ transform: `translateY(${item.start}px)`, height: ROW_HEIGHT }}
                                        >
                                            {row.phase}
                                        </div>
                                    );
                                }
                                const line = lines[row.index];
                                const tone = toneOf(line);

                                return (
                                    <div
                                        key={item.key}
                                        data-index={item.index}
                                        data-stream={line.stream}
                                        data-level={line.level}
                                        ref={prefs.wrap ? virtualizer.measureElement : undefined}
                                        onClick={onLineClick ? () => onLineClick(line, row.index) : undefined}
                                        className={cn(
                                            'group/line hover:bg-surface-2/70 absolute left-0 flex w-full min-w-max py-1',
                                            prefs.wrap && 'min-w-0',
                                            tone.row,
                                            onLineClick && 'cursor-pointer',
                                        )}
                                        style={{ transform: `translateY(${item.start}px)`, minHeight: ROW_HEIGHT }}
                                    >
                                        <span
                                            className={cn(
                                                'absolute inset-y-0.5 w-[3px] rounded-full',
                                                flush ? 'left-2 sm:left-3' : 'left-1',
                                                tone.bar,
                                            )}
                                            aria-hidden
                                        />
                                        <span
                                            className={cn('text-fg-faint shrink-0 text-right select-none', flush ? 'pl-5 sm:pl-7' : 'pl-3')}
                                            style={{ width: showNumbers ? `${gutter + 3}ch` : undefined }}
                                            aria-hidden
                                        >
                                            {showNumbers ? row.index + 1 : ''}
                                        </span>
                                        {showTime && (
                                            <span className="text-fg-muted w-20 shrink-0 pl-2 whitespace-nowrap select-none sm:w-44">
                                                {line.time && (
                                                    <>
                                                        <span className="hidden sm:inline">{timeParts(line.time)[0]} </span>
                                                        {timeParts(line.time)[1]}
                                                    </>
                                                )}
                                            </span>
                                        )}
                                        <span
                                            className={cn(
                                                'min-w-0 pr-4 pl-2',
                                                prefs.wrap ? 'flex-1 break-all whitespace-pre-wrap' : 'whitespace-pre',
                                            )}
                                        >
                                            <AnsiText text={line.text} highlight={filter} />
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
                {lines.length > 0 && (scrollable || !following) && (!following || !atTop) && (
                    <IconButton
                        variant="secondary"
                        label={following ? 'Scroll to top' : 'Scroll to bottom'}
                        icon={following ? <ArrowUp /> : <ArrowDown />}
                        className="shadow-panel animate-fade-in absolute right-4 bottom-4 z-10 rounded-full"
                        onClick={() => {
                            if (following) {
                                setFollowing(false);
                                virtualizer.scrollToIndex(0, { align: 'start' });
                            } else {
                                setFollowing(true);
                                virtualizer.scrollToIndex(lastRow, { align: 'end' });
                            }
                        }}
                    />
                )}
            </div>
        </div>
    );
}
