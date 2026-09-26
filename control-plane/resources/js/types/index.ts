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

export interface SharedData {
    name: string;
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
