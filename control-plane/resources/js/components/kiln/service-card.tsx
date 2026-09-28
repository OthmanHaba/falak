import { cn } from '@/lib/utils';
import { type CanvasService } from '@/types';
import { HardDrive, Star } from 'lucide-react';
import { type HTMLAttributes } from 'react';
import { ServiceIcon } from './service-icon';
import { StatusDot, statusSpec } from './status';

/** Fixed geometry so groups can be sized before cards are measured (canvas §4). */
export const SERVICE_CARD = { width: 240, height: 112, strip: 30 } as const;

export interface ServiceCardProps extends HTMLAttributes<HTMLDivElement> {
    service: Pick<CanvasService, 'kind' | 'name' | 'icon' | 'status' | 'status_label' | 'url' | 'subtitle' | 'servers' | 'badges'> & {
        volumes?: { name: string; detail: string | null }[];
    };
    /** The service whose panel is open. */
    selected?: boolean;
    /** Part of a multi-selection on the canvas (e.g. to group). */
    marked?: boolean;
}

/** Host of a URL without the scheme ("https://shop.acme.com" → "shop.acme.com"). */
function host(url: string): string {
    return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

const LABEL_TONE: Record<string, string> = {
    success: 'text-success',
    danger: 'text-danger',
    warning: 'text-warning',
    info: 'text-info',
    faint: 'text-fg-muted',
};

/**
 * The canvas card of a service (§4): icon + name (+ runtime badges), the public domain (sites) or engine/server
 * (databases) underneath, and the live status at the bottom ("● Online", "● Deploying 64%") with server chips. A
 * service with persistent storage (volume, engine data dir, shared paths) gets a strip docked under the card.
 * Selected (panel open) = accent border; hover lifts the card (canvas CSS).
 */
export function ServiceCard({ service, selected = false, marked = false, className, ...props }: ServiceCardProps) {
    const detail = service.kind === 'site' ? (service.url ? host(service.url) : service.subtitle) : service.subtitle;
    const servers = service.kind === 'site' ? service.servers : [];
    const volume = service.volumes?.[0];
    const tone = statusSpec(service.status).tone;

    return (
        <div className={cn('group/card relative w-60', className)} data-selected={selected || undefined} {...props}>
            <div
                className={cn(
                    'kiln-card bg-surface-1 relative z-10 flex h-28 flex-col rounded-lg border px-4 pt-3.5 pb-3 text-left',
                    selected
                        ? 'border-primary shadow-[0_0_0_1px_var(--accent),0_8px_28px_-8px_var(--accent-soft)]'
                        : 'border-border group-hover/card:border-border-strong shadow-[0_1px_2px_rgba(0,0,0,0.06)]',
                    marked && !selected && 'outline-primary/70 outline-2 outline-offset-2 outline-dashed',
                )}
            >
                <div className="flex min-w-0 items-center gap-2.5">
                    <ServiceIcon name={service.icon || service.kind} size={18} />
                    <span className="text-fg min-w-0 truncate text-sm font-semibold">{service.name}</span>
                    {service.badges?.map((badge) => (
                        <span
                            key={badge}
                            className="border-border bg-surface-2 text-fg-muted text-2xs inline-flex h-4 shrink-0 items-center rounded-sm border px-1 font-medium"
                        >
                            {badge}
                        </span>
                    ))}
                </div>
                {detail && <span className="text-fg-muted mt-0.5 min-w-0 truncate pl-[1.75rem] text-xs">{detail}</span>}
                <div className="mt-auto flex min-w-0 items-center gap-2">
                    <span className={cn('flex min-w-0 flex-1 items-center gap-2 text-xs font-medium', LABEL_TONE[tone])}>
                        <StatusDot status={service.status} className="ring-2 ring-current/15" />
                        <span className="truncate">{service.status_label}</span>
                    </span>
                    {servers.length > 0 && (
                        <span className="flex shrink-0 items-center gap-1">
                            {servers.slice(0, 1).map((server) => (
                                <span
                                    key={server.id}
                                    title={`${server.name}${server.leader ? ' (leader)' : ''}${server.online ? '' : ' · offline'}`}
                                    className={cn(
                                        'border-border bg-surface-2 text-2xs inline-flex h-4.5 items-center gap-0.5 rounded-sm border px-1 font-mono',
                                        server.online ? 'text-fg-muted' : 'text-fg-faint',
                                    )}
                                >
                                    {server.leader && servers.length > 1 && (
                                        <Star className="fill-warning text-warning size-2.5" aria-label="leader" />
                                    )}
                                    {server.name}
                                </span>
                            ))}
                            {servers.length > 1 && <span className="text-fg-faint text-2xs">+{servers.length - 1}</span>}
                        </span>
                    )}
                </div>
            </div>
            {volume && (
                <div
                    className="border-border bg-surface-2 text-fg-muted relative -mt-2 flex h-[38px] items-end gap-2 rounded-b-lg border border-t-0 px-4 pb-2 text-xs"
                    data-testid="volume-strip"
                >
                    <HardDrive className="text-fg-faint size-3.5 shrink-0" aria-hidden />
                    <span className="min-w-0 truncate">{volume.name}</span>
                    {(service.volumes?.length ?? 0) > 1 && <span className="text-fg-faint">+{(service.volumes?.length ?? 0) - 1}</span>}
                    {volume.detail && <span className="text-fg-faint ml-auto shrink-0 truncate">{volume.detail}</span>}
                </div>
            )}
        </div>
    );
}
