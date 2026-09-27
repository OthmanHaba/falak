import { cn } from '@/lib/utils';
import { Fragment, useRef, type KeyboardEvent, type ReactNode } from 'react';
import { type DiffLine } from '../api';

type Token = { text: string; kind: 'key' | 'string' | 'number' | 'comment' | 'variable' | 'punct' | 'anchor' | 'plain' };

const TOKEN_CLASS: Record<Token['kind'], string> = {
    key: 'text-primary',
    string: 'text-success',
    number: 'text-warning',
    comment: 'text-fg-faint italic',
    variable: 'text-info',
    punct: 'text-fg-faint',
    anchor: 'text-warning',
    plain: 'text-fg',
};

/** Values: ${VAR} interpolation, quoted strings, numbers / booleans, anchors. */
function valueTokens(text: string): Token[] {
    const out: Token[] = [];
    const pattern =
        /(\$\{[^}]*\}|\$\$|"(?:[^"\\]|\\.)*"?|'(?:[^']|'')*'?|[&*][A-Za-z0-9_-]+|\b(?:true|false|null|yes|no|on|off)\b|-?\b\d+(?:\.\d+)?\b|[[\]{},])/g;
    let last = 0;
    for (const match of text.matchAll(pattern)) {
        const index = match.index ?? 0;
        if (index > last) out.push({ text: text.slice(last, index), kind: 'plain' });
        const value = match[0];
        const kind: Token['kind'] = value.startsWith('${')
            ? 'variable'
            : value.startsWith('"') || value.startsWith("'")
              ? 'string'
              : value.startsWith('&') || value.startsWith('*')
                ? 'anchor'
                : /^[[\]{},]$/.test(value)
                  ? 'punct'
                  : 'number';
        out.push({ text: value, kind });
        last = index + value.length;
    }
    if (last < text.length) out.push({ text: text.slice(last), kind: 'plain' });

    return out;
}

/** A small line tokenizer for YAML highlighting (keys, comments, strings, ${VAR}); not a parser. */
export function yamlTokens(line: string): Token[] {
    const comment = line.search(/(^|\s)#/);
    const code = comment >= 0 ? line.slice(0, comment) : line;
    const rest = comment >= 0 ? line.slice(comment) : '';
    const tokens: Token[] = [];
    const key = code.match(/^(\s*(?:-\s+)?)([A-Za-z0-9_.$"'${}-][^:#]*?)(:)(\s|$)/);

    if (key) {
        const [, indent, name, colon] = key;
        const dash = indent.indexOf('-');
        if (dash >= 0) {
            tokens.push(
                { text: indent.slice(0, dash), kind: 'plain' },
                { text: '-', kind: 'punct' },
                { text: indent.slice(dash + 1), kind: 'plain' },
            );
        } else {
            tokens.push({ text: indent, kind: 'plain' });
        }
        tokens.push({ text: name, kind: 'key' }, { text: colon, kind: 'punct' }, ...valueTokens(code.slice(indent.length + name.length + 1)));
    } else {
        const dash = code.match(/^(\s*)(-)(\s.*|$)/);
        if (dash) tokens.push({ text: dash[1], kind: 'plain' }, { text: '-', kind: 'punct' }, ...valueTokens(dash[3]));
        else tokens.push(...valueTokens(code));
    }
    if (rest) tokens.push({ text: rest, kind: 'comment' });

    return tokens;
}

export function HighlightedLine({ line }: { line: string }): ReactNode {
    return yamlTokens(line).map((token, index) => (
        <span key={index} className={TOKEN_CLASS[token.kind]}>
            {token.text}
        </span>
    ));
}

export interface YamlEditorProps {
    value: string;
    onChange: (value: string) => void;
    readOnly?: boolean;
    /** 1-based lines to mark (validation errors). */
    label?: string;
    onSave?: () => void;
    minRows?: number;
}

/**
 * Code editor for compose files: a transparent textarea over a highlighted copy (same metrics), line numbers,
 * Tab indents two spaces, ⌘S saves.
 */
export function YamlEditor({ value, onChange, readOnly = false, label = 'Compose file', onSave, minRows = 14 }: YamlEditorProps) {
    const area = useRef<HTMLTextAreaElement>(null);
    const layer = useRef<HTMLPreElement>(null);
    const gutter = useRef<HTMLDivElement>(null);
    const lines = value.split('\n');
    const rows = Math.min(30, Math.max(minRows, lines.length + 1));

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        event.stopPropagation();
        if ((event.metaKey || event.ctrlKey) && event.key === 's') {
            event.preventDefault();
            onSave?.();
        }
        if (event.key === 'Tab' && !readOnly) {
            event.preventDefault();
            const element = event.currentTarget;
            const { selectionStart, selectionEnd } = element;
            onChange(value.slice(0, selectionStart) + '  ' + value.slice(selectionEnd));
            window.requestAnimationFrame(() => element.setSelectionRange(selectionStart + 2, selectionStart + 2));
        }
    };

    const sync = () => {
        const element = area.current;
        if (!element) return;
        if (layer.current) {
            layer.current.scrollTop = element.scrollTop;
            layer.current.scrollLeft = element.scrollLeft;
        }
        if (gutter.current) gutter.current.scrollTop = element.scrollTop;
    };

    return (
        <div
            className="border-border bg-bg focus-within:border-border-strong flex overflow-hidden rounded-md border font-mono text-xs leading-5"
            data-testid="yaml-editor"
        >
            <div
                ref={gutter}
                aria-hidden
                className="border-border text-fg-faint overflow-hidden border-r px-2 py-2 text-right select-none"
                style={{ height: `${rows * 20 + 16}px` }}
            >
                {lines.map((_, index) => (
                    <div key={index}>{index + 1}</div>
                ))}
            </div>
            <div className="relative min-w-0 flex-1" style={{ height: `${rows * 20 + 16}px` }}>
                <pre ref={layer} aria-hidden className="pointer-events-none absolute inset-0 m-0 overflow-hidden px-3 py-2 whitespace-pre">
                    {lines.map((line, index) => (
                        <Fragment key={index}>
                            <HighlightedLine line={line} />
                            {'\n'}
                        </Fragment>
                    ))}
                </pre>
                <textarea
                    ref={area}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    onKeyDown={onKeyDown}
                    onScroll={sync}
                    readOnly={readOnly}
                    spellCheck={false}
                    autoComplete="off"
                    autoCapitalize="off"
                    aria-label={label}
                    wrap="off"
                    className="caret-fg selection:bg-primary-soft absolute inset-0 h-full w-full resize-none overflow-auto bg-transparent px-3 py-2 whitespace-pre text-transparent outline-none"
                />
            </div>
        </div>
    );
}

/** Unified line diff of two compose versions (reuses the Variables raw editor's diff). */
export function DiffView({ diff, header }: { diff: DiffLine[]; header: ReactNode }) {
    return (
        <div className="border-border bg-surface-1 overflow-hidden rounded-lg border" aria-label="Changes">
            <div className="border-border text-fg-muted flex items-center justify-between gap-2 border-b px-3 py-2 text-xs">
                <span>
                    <span className="text-success">+{diff.filter((line) => line.type === 'add').length}</span>{' '}
                    <span className="text-danger">−{diff.filter((line) => line.type === 'remove').length}</span>
                </span>
                <span className="text-fg-faint">{header}</span>
            </div>
            <pre className="max-h-[min(520px,calc(100vh-22rem))] overflow-auto py-1 font-mono text-xs leading-5">
                {diff.map((line, index) => (
                    <div
                        key={index}
                        className={cn(
                            'flex gap-3 px-3',
                            line.type === 'add' && 'bg-success-soft text-fg',
                            line.type === 'remove' && 'bg-danger-soft text-fg-muted',
                            line.type === 'same' && 'text-fg-muted',
                        )}
                    >
                        <span className="text-fg-faint w-6 shrink-0 text-right select-none">{line.oldNo ?? ''}</span>
                        <span className="text-fg-faint w-6 shrink-0 text-right select-none">{line.newNo ?? ''}</span>
                        <span className="w-3 shrink-0 select-none">{line.type === 'add' ? '+' : line.type === 'remove' ? '−' : ' '}</span>
                        <span className="break-all whitespace-pre-wrap">{line.text || ' '}</span>
                    </div>
                ))}
            </pre>
        </div>
    );
}
