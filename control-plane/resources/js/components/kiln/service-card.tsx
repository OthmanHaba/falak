import { cn } from '@/lib/utils';
import { type CanvasService } from '@/types';
import { Star } from 'lucide-react';
import { type HTMLAttributes } from 'react';
import { ServiceIcon } from './service-icon';
import { StatusDot } from './status';

export interface ServiceCardProps extends HTMLAttributes<HTMLDivElement> {
    service: Pick<CanvasService, 'kind' | 'name' | 'icon' | 'status' | 'status_label' | 'url' | 'subtitle' | 'servers'>;
    selected?: boolean;
}

/** Host of a URL without the scheme ("https://shop.acme.com" → "shop.acme.com"). */
function host(url: string): string {
    return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

/**
 * The canvas card of a service (§4, 240×~96): kind icon, name, one-line live status, domain (sites) or
 * engine + server (databases), server chips with the leader starred. Selected = strong border + accent glow.
 */
export function ServiceCard({ service, selected = false, className, ...props }: ServiceCardProps) {
    const detail = service.kind === 'site' ? (service.url ? host(service.url) : service.subtitle) : service.subtitle;
    const servers = service.kind === 'site' ? service.servers : [];

    return (
        <div
            data-selected={selected || undefined}
            className={cn(
                'group bg-surface-1 grid w-60 gap-2 rounded-lg border px-3 py-2.5 text-left transition-[border-color,box-shadow] duration-150 ease-out',
                selected
                    ? 'border-border-strong shadow-[0_0_0_1px_var(--accent),0_0_24px_-4px_var(--accent-soft)]'
                    : 'border-border hover:border-border-strong',
                className,
            )}
            {...props}
        >
            <div className="flex min-w-0 items-center gap-2.5">
                <span className="border-border bg-surface-2 flex size-7 shrink-0 items-center justify-center rounded-md border">
                    <ServiceIcon name={service.icon || service.kind} size={15} />
                </span>
                <div className="grid min-w-0 flex-1">
                    <span className="text-fg truncate text-sm font-medium">{service.name}</span>
                    <span className="text-fg-muted flex min-w-0 items-center gap-1.5 text-xs">
                        <StatusDot status={service.status} size="sm" />
                        <span className="truncate">{service.status_label}</span>
                    </span>
                </div>
            </div>
            {(detail || servers.length > 0) && (
                <div className="flex min-w-0 items-center gap-2">
                    {detail && <span className="text-fg-faint min-w-0 flex-1 truncate font-mono text-[11px]">{detail}</span>}
                    {servers.length > 0 && (
                        <span className="ml-auto flex shrink-0 items-center gap-1">
                            {servers.slice(0, 3).map((server) => (
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
                            {servers.length > 3 && <span className="text-fg-faint text-2xs">+{servers.length - 3}</span>}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
