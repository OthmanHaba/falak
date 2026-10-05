import { Button, Skeleton } from '@/components/falak';
import { type ServiceTab, type ServiceTabProps } from '@/lib/registry';
import { type ServiceKind } from '@/types';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Boxes } from 'lucide-react';

interface Pending {
    id: string;
    title: string;
    order: number;
    kinds: ServiceKind[];
    /** Module that will serve this tab's data. */
    module: string;
    description: string;
    permission?: string;
    /** Classic page with the same content until the tab ships (`{id}` = the site / database id). */
    legacy?: string;
}

/**
 * Stand-in panel tab until the owning module registers the real one (registerServiceTabs placeholder): says
 * where the data will come from and links the classic page meanwhile.
 */
export function pendingTab(spec: Pending): ServiceTab {
    function PendingTab({ ctx }: ServiceTabProps) {
        const legacy = spec.legacy?.replace('{id}', ctx.service.ref_id);

        return (
            <div className="grid gap-5" data-testid={`pending-tab-${spec.id}`}>
                <div className="border-border flex flex-wrap items-start gap-3 rounded-lg border border-dashed p-4">
                    <span className="border-border bg-surface-2 text-fg-muted flex size-9 shrink-0 items-center justify-center rounded-lg border">
                        <Boxes className="size-4" aria-hidden />
                    </span>
                    <div className="grid min-w-0 flex-1 gap-1">
                        <p className="text-fg text-sm font-medium">
                            {spec.title} loads from the {spec.module} module
                        </p>
                        <p className="text-fg-muted text-sm">{spec.description} It moves into this panel in the next release.</p>
                    </div>
                    {legacy && (
                        <Button asChild size="sm">
                            <Link href={legacy}>
                                Open classic page <ArrowUpRight />
                            </Link>
                        </Button>
                    )}
                </div>
                <div className="grid gap-2 opacity-60" aria-hidden>
                    {[0, 1, 2, 3].map((row) => (
                        <Skeleton key={row} className="h-9" />
                    ))}
                </div>
            </div>
        );
    }
    PendingTab.displayName = `PendingTab(${spec.id})`;

    return {
        id: spec.id,
        title: spec.title,
        order: spec.order,
        kinds: spec.kinds,
        permission: spec.permission,
        component: PendingTab,
        placeholder: true,
    };
}
