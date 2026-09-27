import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { CopyButton } from './copy-button';

export interface KeyValueItem {
    label: ReactNode;
    value: ReactNode;
    /** Adds a copy button for this raw string. */
    copy?: string;
    mono?: boolean;
}

/** Definition list for metadata (engine, IP, region, created…). */
export function KeyValue({ items, columns = 1, className }: { items: KeyValueItem[]; columns?: 1 | 2 | 3; className?: string }) {
    return (
        <dl className={cn('grid gap-x-6 gap-y-3', columns === 2 && 'sm:grid-cols-2', columns === 3 && 'sm:grid-cols-2 lg:grid-cols-3', className)}>
            {items.map((item, index) => (
                <div key={index} className="grid min-w-0 gap-0.5">
                    <dt className="text-fg-faint text-xs">{item.label}</dt>
                    <dd className={cn('text-fg flex min-w-0 items-center gap-1 text-sm', item.mono && 'font-mono text-xs')}>
                        <span className="min-w-0 truncate">{item.value ?? '—'}</span>
                        {item.copy && <CopyButton value={item.copy} size="xs" />}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
