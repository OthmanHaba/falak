import { Button, Dialog, RelativeTime, SkeletonRows, Tag } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { type ServiceActionDialogProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import { type Release } from '../types';
import { firstLine, rollback } from './api';
import { CommitTag } from './deployment-row';

/** Header `⋯` → Rollback…: pick a previous release (its build is re-activated, no rebuild). */
export function RollbackDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const { data, error } = useJson<{ releases: Release[]; can: { rollback: boolean } }>(open ? `/sites/${ctx.service.ref_id}/releases` : null);
    const [selected, setSelected] = useState<string | null>(null);
    const [running, setRunning] = useState(false);
    const candidates = (data?.releases ?? []).filter((release) => !release.active);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Roll back"
            description="Re-activate a previous release on every server. The build is reused, so this is fast; migrations do not run backwards."
            size="md"
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        disabled={!selected || !data?.can.rollback}
                        loading={running}
                        onClick={async () => {
                            if (!selected) return;
                            setRunning(true);
                            await rollback(ctx, selected);
                            setRunning(false);
                            onOpenChange(false);
                        }}
                    >
                        Roll back
                    </Button>
                </>
            }
        >
            {error && <p className="text-danger text-sm">{error}</p>}
            {!data && !error && <SkeletonRows rows={4} />}
            {data && candidates.length === 0 && <p className="text-fg-muted text-sm">No previous release to roll back to yet.</p>}
            <div className="grid gap-1" role="radiogroup" aria-label="Releases">
                {candidates.map((release) => (
                    <button
                        key={release.id}
                        type="button"
                        role="radio"
                        aria-checked={selected === release.id}
                        disabled={!release.can_rollback}
                        onClick={() => setSelected(release.id)}
                        className={cn(
                            'border-border hover:border-border-strong grid gap-1 rounded-md border px-3 py-2 text-left transition-colors disabled:pointer-events-none disabled:opacity-50',
                            selected === release.id && 'border-primary bg-primary-soft',
                        )}
                    >
                        <span className="flex items-center gap-2">
                            <span className="text-fg min-w-0 flex-1 truncate text-sm">{firstLine(release.message) ?? 'Release'}</span>
                            {!release.can_rollback && <Tag>{release.status}</Tag>}
                        </span>
                        <span className="text-fg-faint flex items-center gap-2 text-xs">
                            <CommitTag sha={release.commit} branch={release.branch} />
                            <RelativeTime value={release.activated_at ?? release.created_at} />
                        </span>
                    </button>
                ))}
            </div>
        </Dialog>
    );
}
