import { IconButton, RelativeTime, SkeletonRows, StatusDot } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { Activity, X } from 'lucide-react';
import { type ActivityItem } from '../types';

/** §4 Activity rail: recent deploys and events of this environment, newest first. Clicking opens the service. */
export function ActivityRail({ url, onClose, onOpen }: { url: string; onClose: () => void; onOpen: (item: ActivityItem) => void }) {
    const { data, error } = useJson<ActivityItem[]>(url, { interval: 10000 });

    return (
        <aside
            aria-label="Activity"
            className="animate-fade-in border-border bg-surface-1 shadow-panel absolute top-3 right-3 bottom-3 z-20 flex w-[min(320px,calc(100%-1.5rem))] flex-col overflow-hidden rounded-xl border"
        >
            <div className="border-border flex items-center gap-2 border-b py-2 pr-2 pl-3">
                <Activity className="text-fg-muted size-4" aria-hidden />
                <h2 className="text-fg flex-1 text-sm font-medium">Activity</h2>
                <IconButton size="sm" label="Close activity" icon={<X />} onClick={onClose} />
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-1.5">
                {error && <p className="text-danger p-3 text-xs">{error}</p>}
                {!data && !error && <SkeletonRows rows={6} className="p-2" />}
                {data?.length === 0 && (
                    <p className="text-fg-faint p-4 text-center text-sm">No activity yet. Deploys and new services show up here.</p>
                )}
                <ol className="grid">
                    {data?.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                onClick={() => onOpen(item)}
                                className="hover:bg-surface-2 focus-visible:bg-surface-2 flex w-full items-start gap-2.5 rounded-md px-2.5 py-2 text-left outline-none"
                            >
                                <StatusDot status={item.status} className="mt-1.5" />
                                <span className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="text-fg truncate text-sm">{item.title}</span>
                                    {item.detail && <span className="text-fg-muted truncate text-xs">{item.detail}</span>}
                                    <RelativeTime value={item.at} className="text-fg-faint text-2xs" />
                                </span>
                            </button>
                        </li>
                    ))}
                </ol>
            </div>
        </aside>
    );
}
