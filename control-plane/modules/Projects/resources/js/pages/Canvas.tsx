import { AppShell, Button, EmptyCanvas, IconButton, Tooltip, toast } from '@/components/kiln';
import { errorMessage, requestJson } from '@/lib/http';
import { shellContext, type ServicePanelContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type CanvasService, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ReactFlowProvider, useReactFlow } from '@xyflow/react';
import { Activity, Maximize, Minus, Plus, Settings } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityRail } from '../components/activity-rail';
import { CanvasBoard } from '../components/canvas-board';
import { CreatePicker } from '../components/create-picker';
import { ServicePanel } from '../components/service-panel';
import { useLiveCanvas } from '../components/use-live-canvas';
import { CREATE_SERVICE_EVENT, canvasUrl, panelUrl, type ActivityItem, type CanvasPageProps, type PanelRoute } from '../types';

function Toolbar({ activity, onActivity }: { activity: boolean; onActivity: () => void }) {
    const flow = useReactFlow();

    return (
        <div className="border-border bg-surface-1 shadow-panel absolute bottom-3 left-3 z-10 flex items-center gap-0.5 rounded-lg border p-0.5">
            <IconButton size="sm" label="Zoom out" shortcut="−" icon={<Minus />} onClick={() => flow.zoomOut({ duration: 150 })} />
            <IconButton size="sm" label="Zoom in" shortcut="+" icon={<Plus />} onClick={() => flow.zoomIn({ duration: 150 })} />
            <IconButton
                size="sm"
                label="Fit to screen"
                icon={<Maximize />}
                onClick={() => flow.fitView({ duration: 220, padding: 0.3, maxZoom: 1 })}
            />
            <span className="bg-border mx-0.5 h-4 w-px" aria-hidden />
            <Tooltip content="Activity">
                <Button
                    size="sm"
                    variant="ghost"
                    icon={<Activity />}
                    aria-pressed={activity}
                    onClick={onActivity}
                    className={cn(activity && 'bg-surface-3 text-fg')}
                >
                    Activity
                </Button>
            </Tooltip>
        </div>
    );
}

function CanvasPage({ project, environment, canvas: initial, panel, can }: CanvasPageProps) {
    const { props } = usePage<SharedData>();
    const shell = useMemo(() => shellContext(props), [props]);
    const home = canvasUrl(project.id, environment.slug);
    const { canvas, setCanvas, refresh } = useLiveCanvas(`${home}/canvas`, initial);
    const [route, setRoute] = useState<PanelRoute | null>(panel);
    const [picker, setPicker] = useState<{ position: { x: number; y: number } | null; anchor: { x: number; y: number } | null } | null>(null);
    const [activity, setActivity] = useState(false);
    const canCreate = can.create_sites || can.create_databases;

    useEffect(() => setRoute(panel), [panel]);

    const visit = useCallback(
        (next: PanelRoute | null) => {
            setRoute(next);
            const url = next ? panelUrl(project.id, environment.slug, { kind: next.kind, ref_id: next.id }, next.tab, next.item) : home;
            router.visit(url, { only: ['panel'], preserveState: true, preserveScroll: true, replace: false });
        },
        [project.id, environment.slug, home],
    );

    const openService = useCallback(
        (service: Pick<CanvasService, 'kind' | 'ref_id'>, tab: string | null = null, item: string | null = null) =>
            visit({ kind: service.kind, id: service.ref_id, tab, item }),
        [visit],
    );

    // ⌘K → Create (registered by this module) and the empty state open the picker.
    useEffect(() => {
        const open = () => canCreate && setPicker({ position: null, anchor: null });
        window.addEventListener(CREATE_SERVICE_EVENT, open);

        return () => window.removeEventListener(CREATE_SERVICE_EVENT, open);
    }, [canCreate]);

    const move = useCallback(
        (service: CanvasService, position: { x: number; y: number }) => {
            setCanvas((current) => ({
                ...current,
                services: current.services.map((item) => (item.id === service.id ? { ...item, position } : item)),
            }));
            requestJson(`${home}/services/${service.id}/position`, 'PATCH', position).catch((error) => {
                toast.error('Could not save the position', errorMessage(error));
                void refresh();
            });
        },
        [home, setCanvas, refresh],
    );

    const selected = route ? (canvas.services.find((service) => service.kind === route.kind && service.ref_id === route.id) ?? null) : null;

    const base = useMemo<Omit<ServicePanelContext, 'service' | 'tab' | 'baseUrl'> | null>(
        () =>
            route
                ? {
                      project: { id: project.id, name: project.name },
                      environment: { id: environment.id, name: environment.name, slug: environment.slug },
                      canvasUrl: home,
                      item: route.item,
                      open: (tab, item = null) => visit({ ...route, tab, item }),
                      refresh: () => void refresh(),
                      close: () => {
                          visit(null);
                          void refresh();
                      },
                      can: shell.can,
                  }
                : null,
        [route, project.id, project.name, environment.id, environment.name, environment.slug, home, visit, refresh, shell],
    );

    return (
        <>
            <Head title={`${project.name} · ${environment.name}`} />
            <div className="relative h-[calc(100svh-3rem)] min-h-0 flex-1 overflow-hidden" data-testid="project-canvas">
                <div className="absolute inset-0">
                    <CanvasBoard
                        services={canvas.services}
                        edges={canvas.edges}
                        selectedId={selected?.id ?? null}
                        draggable={can.manage}
                        onOpen={(service) => openService(service)}
                        onMove={move}
                        onContextMenu={canCreate ? (position, anchor) => setPicker({ position, anchor }) : undefined}
                    />
                </div>

                <div className="absolute top-3 right-3 z-10 flex items-center gap-2 md:right-4">
                    {can.manage && (
                        <IconButton
                            label="Project settings"
                            icon={<Settings />}
                            variant="secondary"
                            onClick={() => router.visit(`/projects/${project.id}/settings`)}
                        />
                    )}
                    {canCreate && (
                        <Button
                            variant="primary"
                            icon={<Plus />}
                            onClick={() => setPicker(picker ? null : { position: null, anchor: null })}
                            aria-expanded={picker !== null}
                        >
                            Create
                        </Button>
                    )}
                </div>

                {canvas.services.length === 0 && <EmptyCanvas onCreate={canCreate ? () => setPicker({ position: null, anchor: null }) : undefined} />}

                <Toolbar activity={activity} onActivity={() => setActivity((value) => !value)} />

                {activity && (
                    <ActivityRail
                        url={`${home}/activity`}
                        onClose={() => setActivity(false)}
                        onOpen={(item: ActivityItem) =>
                            openService({ kind: item.kind, ref_id: item.ref_id }, item.deployment_id ? 'deployments' : null, item.deployment_id)
                        }
                    />
                )}

                {picker && (
                    <CreatePicker
                        projectId={project.id}
                        environmentSlug={environment.slug}
                        can={can}
                        position={picker.position}
                        anchor={picker.anchor}
                        onClose={() => setPicker(null)}
                        onCreated={(service, deploymentId) => {
                            setPicker(null);
                            setCanvas((current) => ({
                                ...current,
                                services: [...current.services.filter((item) => item.id !== service.id), service],
                            }));
                            openService(service, service.kind === 'site' ? 'deployments' : 'overview', deploymentId);
                            void refresh();
                        }}
                    />
                )}
            </div>

            {route && base && (
                <ServicePanel
                    base={base}
                    service={selected}
                    kind={route.kind}
                    refId={route.id}
                    tab={route.tab}
                    renameUrl={can.manage && selected ? `${home}/services/${selected.id}` : null}
                    onRenamed={(name) =>
                        setCanvas((current) => ({
                            ...current,
                            services: current.services.map((item) => (item.id === selected?.id ? { ...item, name } : item)),
                        }))
                    }
                />
            )}
        </>
    );
}

/** §4 project canvas with the §5 service panel (deep link: /projects/{p}/{env}/service/{kind}/{id}/{tab}/{item}). */
export default function Canvas(props: CanvasPageProps) {
    return (
        <AppShell variant="full">
            <ReactFlowProvider>
                <CanvasPage {...props} />
            </ReactFlowProvider>
        </AppShell>
    );
}
