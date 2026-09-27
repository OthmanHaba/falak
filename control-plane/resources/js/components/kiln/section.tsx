import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface SectionProps {
    title: ReactNode;
    description?: ReactNode;
    /** Right side of the header (e.g. a secondary action). */
    aside?: ReactNode;
    /** Footer row (e.g. Save button), separated by a hairline. */
    footer?: ReactNode;
    id?: string;
    tone?: 'default' | 'danger';
    /** Render the body without the surface card (for tables that bring their own frame). */
    bare?: boolean;
    className?: string;
    children?: ReactNode;
}

/** A settings section: title/description on top, content in a quiet surface. */
export function Section({ title, description, aside, footer, id, tone = 'default', bare = false, className, children }: SectionProps) {
    const headingId = id ? `${id}-title` : undefined;

    return (
        <section id={id} aria-labelledby={headingId} className={cn('grid scroll-mt-20 gap-3', className)}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-0.5">
                    <h2 id={headingId} className={cn('text-base font-medium', tone === 'danger' ? 'text-danger' : 'text-fg')}>
                        {title}
                    </h2>
                    {description && <p className="text-fg-muted text-sm">{description}</p>}
                </div>
                {aside && <div className="flex items-center gap-2">{aside}</div>}
            </div>
            {children !== undefined &&
                (bare ? (
                    children
                ) : (
                    <div className={cn('bg-surface-1 rounded-lg border', tone === 'danger' ? 'border-danger/40' : 'border-border')}>
                        <div className="grid gap-4 p-4">{children}</div>
                        {footer && <div className="border-border flex items-center justify-end gap-2 border-t px-4 py-3">{footer}</div>}
                    </div>
                ))}
        </section>
    );
}

/** Page heading used inside the shell (16px title per the type scale). */
export function PageHeader({
    title,
    description,
    actions,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap items-start justify-between gap-4', className)}>
            <div className="grid min-w-0 gap-0.5">
                <h1 className="text-fg truncate text-lg font-semibold">{title}</h1>
                {description && <p className="text-fg-muted text-sm">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
