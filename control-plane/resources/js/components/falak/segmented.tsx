import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface SegmentedOption<V extends string = string> {
    value: V;
    label: ReactNode;
}

export interface SegmentedProps<V extends string = string> {
    value: V;
    onValueChange: (value: V) => void;
    options: SegmentedOption<V>[];
    /** Accessible group name (e.g. "Time range"). */
    label: string;
    size?: 'sm' | 'md';
    className?: string;
}

/** Compact single-choice toggle group (time ranges 1h/6h/24h, view modes). */
export function Segmented<V extends string = string>({ value, onValueChange, options, label, size = 'sm', className }: SegmentedProps<V>) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className={cn('border-border bg-surface-1 inline-flex shrink-0 items-center rounded-md border p-0.5', className)}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onValueChange(option.value)}
                        className={cn(
                            'tabular rounded-sm px-2 font-medium whitespace-nowrap transition-colors duration-150',
                            'focus-visible:outline-primary focus-visible:outline-2 focus-visible:outline-offset-2',
                            size === 'sm' ? 'h-6 text-xs' : 'h-7 text-sm',
                            active ? 'bg-surface-3 text-fg' : 'text-fg-muted hover:text-fg',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
