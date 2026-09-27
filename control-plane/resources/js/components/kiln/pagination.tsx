import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export interface PaginationProps {
    /** Laravel paginator JSON (`links` with previous/next at the ends). */
    page: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
    };
    /** Noun for the total ("issues"). */
    noun?: string;
    className?: string;
}

/** "1–25 of 312 issues  ‹ ›" for Inertia-paginated lists (keeps state and scroll). */
export function Pagination({ page, noun = 'results', className }: PaginationProps) {
    if (page.total === 0) return null;
    const previous = page.links[0]?.url ?? null;
    const next = page.links[page.links.length - 1]?.url ?? null;
    const button =
        'inline-flex size-7 items-center justify-center rounded-md text-fg-muted transition-colors duration-150 hover:bg-surface-2 hover:text-fg';

    return (
        <nav aria-label="Pagination" className={cn('flex items-center justify-between gap-3 text-xs', className)}>
            <p className="text-fg-muted tabular">
                {page.from ?? 0}–{page.to ?? 0} of {page.total} {noun}
            </p>
            {page.last_page > 1 && (
                <div className="flex items-center gap-1">
                    <span className="text-fg-faint tabular mr-1">
                        Page {page.current_page} of {page.last_page}
                    </span>
                    {previous ? (
                        <Link href={previous} preserveState preserveScroll className={button} aria-label="Previous page">
                            <ChevronLeft className="size-4" />
                        </Link>
                    ) : (
                        <span className={cn(button, 'pointer-events-none opacity-40')} aria-hidden>
                            <ChevronLeft className="size-4" />
                        </span>
                    )}
                    {next ? (
                        <Link href={next} preserveState preserveScroll className={button} aria-label="Next page">
                            <ChevronRight className="size-4" />
                        </Link>
                    ) : (
                        <span className={cn(button, 'pointer-events-none opacity-40')} aria-hidden>
                            <ChevronRight className="size-4" />
                        </span>
                    )}
                </div>
            )}
        </nav>
    );
}
