import { GitCommitHorizontal } from 'lucide-react';

export function CommitTag({ sha, branch }: { sha: string | null; branch?: string | null }) {
    return (
        <span className="text-fg-muted inline-flex min-w-0 items-center gap-1 font-mono text-xs">
            <GitCommitHorizontal className="text-fg-faint size-3.5 shrink-0" aria-hidden />
            {branch && (
                <>
                    <span className="truncate">{branch}</span>
                    <span className="text-fg-faint" aria-hidden>
                        ·
                    </span>
                </>
            )}
            <span>{sha ? sha.slice(0, 7) : 'HEAD'}</span>
        </span>
    );
}

const TRIGGERS: Record<string, string> = {
    manual: 'Manual',
    push: 'Push',
    hook: 'Deploy hook',
    api: 'API',
    rollback: 'Rollback',
    schedule: 'Scheduled',
};

export function triggerLabel(trigger: string): string {
    return TRIGGERS[trigger] ?? trigger.charAt(0).toUpperCase() + trigger.slice(1);
}
