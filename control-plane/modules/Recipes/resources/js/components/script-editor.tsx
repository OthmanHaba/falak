import { useFieldControl } from '@/components/falak/field';
import { cn } from '@/lib/utils';
import { useRef, type KeyboardEvent } from 'react';

interface ScriptEditorProps {
    value: string;
    onChange: (value: string) => void;
    id?: string;
    rows?: number;
    readOnly?: boolean;
    className?: string;
}

/**
 * Monospace bash editor: line-number gutter, Tab indents (4 spaces), Shift+Tab outdents, no spellcheck.
 * A plain <textarea> underneath so it stays accessible and works with Field labels/errors.
 */
export function ScriptEditor({ value, onChange, id, rows = 16, readOnly = false, className }: ScriptEditorProps) {
    const field = useFieldControl({ id });
    const gutter = useRef<HTMLDivElement>(null);
    const lines = Math.max(value.split('\n').length, rows);

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key !== 'Tab' || readOnly) return;
        event.preventDefault();
        const target = event.currentTarget;
        const { selectionStart, selectionEnd } = target;
        const lineStart = value.lastIndexOf('\n', selectionStart - 1) + 1;

        if (event.shiftKey) {
            const indent = value.slice(lineStart, lineStart + 4).match(/^ {1,4}/)?.[0].length ?? 0;
            if (indent === 0) return;
            onChange(value.slice(0, lineStart) + value.slice(lineStart + indent));
            requestAnimationFrame(() =>
                target.setSelectionRange(Math.max(lineStart, selectionStart - indent), Math.max(lineStart, selectionEnd - indent)),
            );

            return;
        }

        onChange(`${value.slice(0, selectionStart)}    ${value.slice(selectionEnd)}`);
        requestAnimationFrame(() => target.setSelectionRange(selectionStart + 4, selectionStart + 4));
    };

    return (
        <div
            className={cn(
                'border-border bg-canvas focus-within:border-border-strong focus-within:outline-primary flex overflow-hidden rounded-md border focus-within:outline-2 focus-within:outline-offset-2',
                field['aria-invalid'] && 'border-danger',
                className,
            )}
        >
            <div
                ref={gutter}
                aria-hidden
                className="border-border text-fg-faint tabular shrink-0 overflow-hidden border-r px-2 py-2 text-right font-mono text-xs leading-5 select-none"
                style={{ height: `calc(${rows} * 1.25rem + 1rem)` }}
            >
                {Array.from({ length: lines }, (_, index) => (
                    <div key={index}>{index + 1}</div>
                ))}
            </div>
            <textarea
                {...field}
                value={value}
                readOnly={readOnly}
                rows={rows}
                spellCheck={false}
                autoCapitalize="off"
                autoCorrect="off"
                wrap="off"
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={onKeyDown}
                onScroll={(event) => {
                    if (gutter.current) gutter.current.scrollTop = event.currentTarget.scrollTop;
                }}
                className="text-fg placeholder:text-fg-faint min-w-0 flex-1 resize-none bg-transparent px-3 py-2 font-mono text-xs leading-5 outline-none"
                style={{ height: `calc(${rows} * 1.25rem + 1rem)` }}
            />
        </div>
    );
}
