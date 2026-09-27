import { type Canvas, type CanvasService } from '@/types';

export interface ProjectEnvironment {
    id: string;
    project_id?: string;
    name: string;
    slug: string;
    is_production: boolean;
    forked_from_id?: string | null;
    services_count?: number;
    created_at?: string;
}

export interface ProjectSummary {
    id: string;
    name: string;
    description: string | null;
    icon: string | null;
    is_default: boolean;
    environments: ProjectEnvironment[];
    services_count: number;
    services: { kind: 'site' | 'database'; name: string; icon: string }[];
    last_deployment: { id: string; site_id: string; status: string; finished_at: string | null; created_at: string } | null;
    status: 'active' | 'deploying' | 'failed' | 'inactive';
    created_at: string;
}

export interface ProjectDetail {
    id: string;
    name: string;
    description: string | null;
    icon: string | null;
    is_default: boolean;
    created_at: string;
    environments: ProjectEnvironment[];
}

export interface ProjectAbilities {
    view: boolean;
    manage: boolean;
    create_sites: boolean;
    create_databases: boolean;
}

export interface PanelRoute {
    kind: 'site' | 'database';
    id: string;
    tab: string | null;
    item: string | null;
}

export interface CanvasPageProps {
    project: { id: string; name: string; description: string | null; icon: string | null; is_default: boolean };
    environment: ProjectEnvironment;
    canvas: Canvas;
    panel: PanelRoute | null;
    can: ProjectAbilities;
}

export interface ActivityItem {
    id: string;
    type: 'deployment' | 'service';
    service_id: string;
    service_name: string;
    kind: 'site' | 'database';
    ref_id: string;
    status: string;
    title: string;
    detail: string | null;
    deployment_id: string | null;
    at: string;
}

export type { Canvas, CanvasService };

/** Window event that opens the canvas Create picker (⌘K → Create service). */
export const CREATE_SERVICE_EVENT = 'kiln:canvas-create';

export function canvasUrl(projectId: string, environmentSlug: string): string {
    return `/projects/${projectId}/${environmentSlug}`;
}

export function panelUrl(
    projectId: string,
    environmentSlug: string,
    service: Pick<CanvasService, 'kind' | 'ref_id'>,
    tab?: string | null,
    item?: string | null,
): string {
    const base = `${canvasUrl(projectId, environmentSlug)}/service/${service.kind}/${service.ref_id}`;

    return tab ? `${base}/${tab}${item ? `/${item}` : ''}` : base;
}
