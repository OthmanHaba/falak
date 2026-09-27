import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { CopyButton } from './copy-button';

export interface CodeBlockProps {
    code: string;
    /** Optional caption (filename, "bash", …). */
    title?: ReactNode;
    copyable?: boolean;
    wrap?: boolean;
    maxHeight?: number;
    className?: string;
}

export function CodeBlock({ code, title, copyable = true, wrap = false, maxHeight, className }: CodeBlockProps) {
    return (
        <div className={cn('group border-border bg-canvas relative overflow-hidden rounded-lg border', className)}>
            {title && (
                <div className="border-border text-fg-faint flex h-8 items-center justify-between border-b px-3 text-xs">
                    <span className="truncate">{title}</span>
                    {copyable && <CopyButton value={code} size="xs" />}
                </div>
            )}
            <pre
                className={cn('text-fg overflow-auto p-3 font-mono text-xs leading-5', wrap ? 'break-all whitespace-pre-wrap' : 'whitespace-pre')}
                style={maxHeight ? { maxHeight } : undefined}
                tabIndex={0}
            >
                <code>{code}</code>
            </pre>
            {!title && copyable && (
                <CopyButton
                    value={code}
                    className="bg-surface-2 absolute top-2 right-2 opacity-0 group-focus-within:opacity-100 group-hover:opacity-100"
                />
            )}
        </div>
    );
}
