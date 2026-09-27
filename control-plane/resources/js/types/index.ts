import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export type OrganizationRole = 'owner' | 'admin' | 'developer' | 'viewer';

export interface OrganizationSummary {
    id: string;
    name: string;
    slug: string;
    personal: boolean;
}

export interface CurrentOrganization extends OrganizationSummary {
    role: OrganizationRole | null;
    permissions: string[];
}

export interface OrganizationProps {
    current: CurrentOrganization | null;
    all: OrganizationSummary[];
}

/** One-shot session flashes shared by HandleInertiaRequests (rendered as toasts). */
export interface FlashMessages {
    success?: string;
    error?: string;
    warning?: string;
    status?: string;
}

/** Shared by the Projects module on every authenticated page (docs/UI_DESIGN.md §9). Absent until it ships. */
export interface KilnEnvironment {
    id: string;
    name: string;
    slug: string;
    is_production: boolean;
}

export interface KilnProject {
    id: string;
    name: string;
    icon: string | null;
    environments: KilnEnvironment[];
}

export interface KilnShared {
    projects: KilnProject[];
    current: { project_id: string | null; environment_id: string | null };
}

export interface SharedData {
    name: string;
    kiln?: KilnShared | null;
    flash?: FlashMessages;
    quote: { message: string; author: string };
    auth: Auth;
    organization: OrganizationProps | null;
    [key: string]: unknown;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_confirmed_at?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

/** Laravel LengthAwarePaginator serialized by Inertia. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

/** docs/UI_DESIGN.md §9 canvas read model (GET /projects/{p}/{env}/canvas). */
export type ServiceKind = 'site' | 'database';

export type CanvasStatus = 'active' | 'deploying' | 'building' | 'queued' | 'failed' | 'crashed' | 'inactive' | 'provisioning';

export interface CanvasService {
    /** projects_services.id */
    id: string;
    kind: ServiceKind;
    /** Site id / database id (the owning module's ULID). */
    ref_id: string;
    name: string;
    /** ServiceIcon key. */
    icon: string;
    position: { x: number; y: number };
    status: CanvasStatus;
    status_label: string;
    url: string | null;
    subtitle: string | null;
    servers: { id: string; name: string; leader: boolean; online: boolean }[];
    last_deployment: { id: string; status: string; commit: string | null; message: string | null; finished_at: string | null } | null;
}

export interface Canvas {
    services: CanvasService[];
    edges: { from: string; to: string }[];
}
