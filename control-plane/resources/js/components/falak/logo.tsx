import { cn } from '@/lib/utils';
import { useId } from 'react';

/** The two orbit bands cut out of the sphere (viewBox -310 -310 620 620). */
const ORBITS = ['M-345 117Q-43 52 285-180', 'M-307 206Q7 164 335-44'];

/**
 * Falak mark: a sphere with two orbit bands, in the teal accent (`currentColor`, `text-primary` by default). Small
 * renderings (≤ 20px) use the thicker band so the gaps survive. The mask id is unique per instance.
 */
export function FalakMark({ className, size = 20 }: { className?: string; size?: number }) {
    const maskId = `falak-mark-orbits-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

    return (
        <svg
            viewBox="-310 -310 620 620"
            width={size}
            height={size}
            fill="currentColor"
            className={cn('text-primary shrink-0', className)}
            aria-hidden
        >
            <mask id={maskId}>
                <rect x="-310" y="-310" width="620" height="620" fill="#fff" />
                <g fill="none" stroke="#000" strokeWidth={size <= 20 ? 52 : 34}>
                    {ORBITS.map((d) => (
                        <path key={d} d={d} />
                    ))}
                </g>
            </mask>
            <circle r="296" mask={`url(#${maskId})`} />
        </svg>
    );
}

export function FalakLogo({ className }: { className?: string }) {
    return (
        <span className={cn('text-fg inline-flex items-center gap-2 text-sm font-semibold tracking-tight', className)}>
            <FalakMark />
            Falak
        </span>
    );
}
