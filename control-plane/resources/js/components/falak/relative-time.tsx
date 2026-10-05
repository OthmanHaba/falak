import { formatDistanceToNowStrict } from 'date-fns';
import { useEffect, useReducer } from 'react';

function interval(ageMs: number): number {
    if (ageMs < 60_000) return 5_000;
    if (ageMs < 3_600_000) return 30_000;

    return 300_000;
}

export function formatRelative(date: Date): string {
    const age = Date.now() - date.getTime();

    if (Math.abs(age) < 10_000) return 'just now';

    return formatDistanceToNowStrict(date, { addSuffix: true });
}

/** "2m ago" that keeps itself fresh; full timestamp in the title/dateTime. */
export function RelativeTime({
    value,
    className,
    fallback = '—',
}: {
    value: string | number | Date | null | undefined;
    className?: string;
    fallback?: string;
}) {
    const [, tick] = useReducer((n: number) => n + 1, 0);
    const date = value === null || value === undefined ? null : new Date(value);
    const valid = date !== null && !Number.isNaN(date.getTime());
    const time = valid ? date.getTime() : null;

    useEffect(() => {
        if (time === null) return;

        let timer: number;
        const schedule = () => {
            timer = window.setTimeout(
                () => {
                    tick();
                    schedule();
                },
                interval(Date.now() - time),
            );
        };
        schedule();

        return () => window.clearTimeout(timer);
    }, [time]);

    if (!valid) return <span className={className}>{fallback}</span>;

    return (
        <time dateTime={date.toISOString()} title={date.toLocaleString()} className={className}>
            {formatRelative(date)}
        </time>
    );
}
