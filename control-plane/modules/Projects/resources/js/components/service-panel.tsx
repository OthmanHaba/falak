import { Button, Menu, Panel, ServiceIcon, Skeleton, SkeletonRows, StatusBadge, toast, type MenuAction } from '@/components/kiln';
import { errorMessage, requestJson } from '@/lib/http';
import { serviceActionsFor, serviceTabsFor, type ServiceAction, type ServicePanelContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type CanvasService } from '@/types';
import { Pencil } from 'lucide-react';
import { Suspense, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';

interface ServicePanelProps {
    base: Omit<ServicePanelContext, 'service' | 'tab' | 'baseUrl'>;
    service: CanvasService | null;
    kind: 'site' | 'database';
    refId: string;
    tab: string | null;
    renameUrl: string | null;
    onRenamed: (name: string) => void;
}

/** Click-to-edit service name (canvas service name = the handle `${{ name.KEY }}` references use). */
function InlineName({ name, url, onRenamed }: { name: string; url: string | null; onRenamed: (name: string) => void }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(name);
    const [saving, setSaving] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => setValue(name), [name]);
    useEffect(() => {
        if (editing) input.current?.select();
    }, [editing]);

    if (!url || !editing) {
        return (
            <button
                type="button"
                disabled={!url}
                onClick={() => setEditing(true)}
                className={cn('group flex min-w-0 items-center gap-1.5 rounded-sm text-left', url && 'hover:text-fg-muted')}
                aria-label={url ? `Rename ${name}` : undefined}
            >
                <span className="truncate">{name}</span>
                {url && (
                    <Pencil
                        className="text-fg-faint size-3 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                        aria-hidden
                    />
                )}
            </button>
        );
    }

    const save = async () => {
        const next = value.trim();
        if (!next || next === name) {
            setEditing(false);
            setValue(name);

            return;
        }
        setSaving(true);
        try {
            const body = await requestJson<{ data: { name: string } }>(url, 'PATCH', { name: next });
            onRenamed(body.data.name);
            toast.success('Service renamed', 'Update `${{ … }}` references that used the old name.');
            setEditing(false);
        } catch (error) {
            toast.error('Could not rename', errorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        event.stopPropagation();
        if (event.key === 'Enter') void save();
        if (event.key === 'Escape') {
            event.preventDefault();
            setValue(name);
            setEditing(false);
        }
    };

    return (
        <input
            ref={input}
            value={value}
            disabled={saving}
            aria-label="Service name"
            onChange={(event) => setValue(event.target.value)}
            onKeyDown={onKeyDown}
            onBlur={() => void save()}
            className="bg-surface-2 border-border-strong text-fg -my-0.5 h-7 w-56 rounded-md border px-1.5 text-base font-semibold outline-none"
        />
    );
}

function HeaderActions({ ctx }: { ctx: ServicePanelContext }) {
    const actions = serviceActionsFor(ctx);
    const primary = actions.find((action) => action.primary);
    const rest = actions.filter((action) => action !== primary);
    const [dialog, setDialog] = useState<ServiceAction | null>(null);
    const [running, setRunning] = useState<string | null>(null);

    const run = async (action: ServiceAction) => {
        if (action.dialog) {
            setDialog(action);

            return;
        }
        setRunning(action.id);
        try {
            await action.perform?.(ctx);
        } catch (error) {
            toast.error(`${action.label} failed`, errorMessage(error));
        } finally {
            setRunning(null);
        }
    };

    const menu: MenuAction[] = rest.flatMap((action) => [
        ...(action.separated ? [{ type: 'separator' as const }] : []),
        {
            label: action.label,
            icon: action.icon ? <action.icon /> : undefined,
            danger: action.danger,
            disabled: running === action.id,
            onSelect: () => void run(action),
        },
    ]);
    const Dialog = dialog?.dialog;

    return (
        <>
            {primary && (
                <Button
                    variant="primary"
                    size="sm"
                    icon={primary.icon ? <primary.icon /> : undefined}
                    loading={running === primary.id}
                    onClick={() => void run(primary)}
                >
                    {primary.label}
                </Button>
            )}
            {menu.length > 0 && <Menu actions={menu} label="Service actions" />}
            {Dialog && (
                <Suspense fallback={null}>
                    <Dialog ctx={ctx} open onOpenChange={(open) => !open && setDialog(null)} />
                </Suspense>
            )}
        </>
    );
}

/**
 * §5 service panel: header (icon, inline rename, status, primary action, `⋯`), module-registered tabs synced to
 * /projects/{p}/{env}/service/{kind}/{id}/{tab}. Each tab loads its own data from the owning module.
 */
export function ServicePanel({ base, service, kind, refId, tab, renameUrl, onRenamed }: ServicePanelProps) {
    const tabs = useMemo(() => serviceTabsFor(kind, base, service), [kind, base, service]);
    const active = tabs.find((item) => item.id === tab)?.id ?? tabs[0]?.id ?? '';
    const baseUrl = `${base.canvasUrl}/service/${kind}/${refId}`;

    const ctx = useMemo<ServicePanelContext | null>(
        () => (service ? { ...base, service, tab: active, baseUrl, item: active === tab ? base.item : null } : null),
        [base, service, active, baseUrl, tab],
    );

    return (
        <Panel
            open
            onOpenChange={(open) => !open && base.close()}
            resizable
            resizeKey="service"
            icon={<ServiceIcon name={service?.icon ?? kind} size={16} />}
            title={service ? <InlineName name={service.name} url={renameUrl} onRenamed={onRenamed} /> : <Skeleton className="h-5 w-40" />}
            description={service ? `${service.name} service panel` : 'Service panel'}
            status={service && <StatusBadge status={service.status} label={service.status_label} />}
            subtitle={
                service &&
                (service.url ? (
                    <a href={service.url} target="_blank" rel="noreferrer" className="hover:text-fg font-mono">
                        {service.url.replace(/^https?:\/\//, '')}
                    </a>
                ) : (
                    service.subtitle
                ))
            }
            actions={ctx && <HeaderActions ctx={ctx} />}
            tab={active}
            onTabChange={(next) => base.open(next)}
            tabs={tabs.map((item) => ({
                id: item.id,
                label: item.title,
                content: () =>
                    ctx ? (
                        <Suspense fallback={<SkeletonRows rows={6} />}>
                            <item.component key={`${refId}:${item.id}`} ctx={ctx} />
                        </Suspense>
                    ) : (
                        <Skeleton className="h-40" />
                    ),
            }))}
        />
    );
}
