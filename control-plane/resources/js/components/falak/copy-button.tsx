import { cn } from '@/lib/utils';
import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';

export async function copyText(value: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(value);

        return true;
    } catch {
        return false;
    }
}

export interface CopyButtonProps {
    value: string;
    label?: string;
    size?: 'xs' | 'sm';
    className?: string;
}

export function CopyButton({ value, label = 'Copy', size = 'sm', className }: CopyButtonProps) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) return;
        const timer = window.setTimeout(() => setCopied(false), 1500);

        return () => window.clearTimeout(timer);
    }, [copied]);

    return (
        <button
            type="button"
            onClick={async () => setCopied(await copyText(value))}
            aria-label={copied ? 'Copied' : label}
            title={copied ? 'Copied' : label}
            className={cn(
                'text-fg-faint hover:bg-surface-3 hover:text-fg inline-flex shrink-0 items-center justify-center rounded-sm transition-colors duration-150',
                size === 'xs' ? 'size-5 [&_svg]:size-3' : 'size-6 [&_svg]:size-3.5',
                className,
            )}
        >
            {copied ? <Check className="text-success" aria-hidden /> : <Copy aria-hidden />}
            <span className="sr-only" aria-live="polite">
                {copied ? 'Copied' : ''}
            </span>
        </button>
    );
}
