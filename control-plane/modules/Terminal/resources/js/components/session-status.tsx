import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type SessionStatus } from '../types';

const STYLES: Record<SessionStatus, string> = {
    opening: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    open: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    closed: 'bg-muted text-muted-foreground',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
};

export function SessionStatusBadge({ status, className }: { status: SessionStatus; className?: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', STYLES[status], className)}>
            {status}
        </Badge>
    );
}
