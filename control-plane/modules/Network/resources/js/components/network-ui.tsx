import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type ApplyStatus } from '../types';

const STATUS_STYLES: Record<ApplyStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    applying: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    applied: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
};

export function ApplyStatusBadge({ status, className }: { status: ApplyStatus | null | undefined; className?: string }) {
    if (!status) {
        return (
            <Badge variant="outline" className={cn('text-muted-foreground border-dashed', className)}>
                not configured
            </Badge>
        );
    }

    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', STATUS_STYLES[status], className)}>
            {status}
        </Badge>
    );
}
