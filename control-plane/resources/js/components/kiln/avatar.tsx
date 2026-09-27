import { cn } from '@/lib/utils';
import * as AvatarPrimitive from '@radix-ui/react-avatar';

export function initialsOf(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';

    return (parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export interface AvatarProps {
    name: string;
    src?: string | null;
    size?: 'xs' | 'sm' | 'md' | 'lg';
    square?: boolean;
    className?: string;
}

const SIZES = { xs: 'size-4 text-[8px]', sm: 'size-6 text-2xs', md: 'size-7 text-xs', lg: 'size-10 text-sm' } as const;

export function Avatar({ name, src, size = 'md', square = false, className }: AvatarProps) {
    return (
        <AvatarPrimitive.Root
            className={cn('relative inline-flex shrink-0 overflow-hidden', square ? 'rounded-md' : 'rounded-full', SIZES[size], className)}
        >
            {src && <AvatarPrimitive.Image src={src} alt={name} className="size-full object-cover" />}
            <AvatarPrimitive.Fallback
                delayMs={src ? 400 : 0}
                className="bg-surface-3 text-fg-muted flex size-full items-center justify-center font-medium select-none"
                aria-label={name}
            >
                {initialsOf(name)}
            </AvatarPrimitive.Fallback>
        </AvatarPrimitive.Root>
    );
}
