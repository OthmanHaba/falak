import { type SharedData } from '@/types';
import { type LucideIcon } from 'lucide-react';
import { type ComponentType } from 'react';

/**
 * Typed extension points modules use to plug into the app shell.
 *
 * Each module may ship `modules/<Module>/resources/js/register.ts`; app.tsx imports all of them
 * eagerly at boot, and they call `registerNavigation()` / `registerCommands()` / `registerHeaderItems()`.
 */

export interface ShellContext {
    props: SharedData;
    /** Permissions the user holds in the current organization. */
    permissions: string[];
    can: (permission: string) => boolean;
}

export interface ModuleNavItem {
    id: string;
    title: string;
    url: string;
    icon: LucideIcon;
    /** Lower comes first. Core: dashboard 0, servers 100, sites 200 ... */
    order: number;
    /** Only shown when the user holds this permission in the current organization. */
    permission?: string;
    /** Treat URLs starting with this prefix as active. Defaults to `url`. */
    activePrefix?: string;
}

export interface PaletteCommand {
    id: string;
    title: string;
    /** Palette group heading. Well-known groups are ordered: Navigation, Actions, Projects, Organization, Settings; others follow. */
    group: string;
    icon?: LucideIcon;
    keywords?: string[];
    /**
     * Key hint rendered on the right. Two-key sequences like "G S" are also live global shortcuts
     * (press g then s anywhere outside a text field) — only static providers (minQueryLength 0) are consulted.
     */
    shortcut?: string;
    /** Optional secondary text (e.g. an IP or a repository). */
    subtitle?: string;
    /** Navigate with Inertia on select. */
    href?: string;
    /** Or run an arbitrary action. */
    perform?: () => void;
    permission?: string;
}

export interface CommandProvider {
    id: string;
    /**
     * Static commands (evaluated whenever the palette opens) or dynamic ones for the current query
     * (e.g. searching servers by name). Async providers are awaited and debounced by the palette.
     */
    commands: (ctx: ShellContext & { query: string }) => PaletteCommand[] | Promise<PaletteCommand[]>;
    /** Minimum query length before a dynamic provider runs (default 0). */
    minQueryLength?: number;
}

/** A widget rendered on the right of the app header (e.g. the notification bell). */
export interface HeaderItem {
    id: string;
    /** Lower comes first (left). */
    order: number;
    component: ComponentType;
    /** Only shown when the user holds this permission in the current organization. */
    permission?: string;
}

/**
 * A tab on the site pages (/sites/{id}/...). Sites, Edge, Deployments, Processes... each add their own.
 */
export interface SiteTab {
    id: string;
    title: string;
    /** Path below /sites/{id}; '' is the overview. */
    path: string;
    /** Lower comes first. Sites: overview 0, environment 300, deploy script 400, commands 600, settings 900. Edge: domains 100, routing 200. */
    order: number;
    permission?: string;
}

/**
 * An entry in the /settings/{section} left mini-nav (§3). Identity registers the account + organization sections;
 * other modules add theirs (Source control, Cloud providers, Storage, Builders, Alert channels ...).
 *
 * Groups: `account` (the signed-in user), `organization` (people, policy and org-wide libraries) and
 * `integrations` (external systems the organization connects: git, clouds, buckets, builders, alert targets).
 */
export type SettingsNavGroup = 'account' | 'organization' | 'integrations';

export interface SettingsNavItem {
    id: string;
    title: string;
    url: string;
    group: SettingsNavGroup;
    /**
     * Lower comes first. Identity: profile 0 … api tokens 40; general 100 … audit log 130.
     * Organization libraries: alert rules 160, recipes 170. Integrations: 200+.
     */
    order: number;
    icon?: LucideIcon;
    permission?: string;
    /** Only shown when the user has a current organization. */
    requiresOrganization?: boolean;
    keywords?: string[];
}

const navItems = new Map<string, ModuleNavItem>();
const settingsItems = new Map<string, SettingsNavItem>();
const headerItems = new Map<string, HeaderItem>();
const commandProviders = new Map<string, CommandProvider>();
const siteTabs = new Map<string, SiteTab>();

export function registerNavigation(...items: ModuleNavItem[]): void {
    items.forEach((item) => navItems.set(item.id, item));
}

export function registerCommands(...providers: CommandProvider[]): void {
    providers.forEach((provider) => commandProviders.set(provider.id, provider));
}

export function registerHeaderItems(...items: HeaderItem[]): void {
    items.forEach((item) => headerItems.set(item.id, item));
}

export function headerItemsFor(ctx: ShellContext): HeaderItem[] {
    return [...headerItems.values()].filter((item) => !item.permission || ctx.can(item.permission)).sort((a, b) => a.order - b.order);
}

export function registerSettingsNav(...items: SettingsNavItem[]): void {
    items.forEach((item) => settingsItems.set(item.id, item));
}

export function settingsNavFor(ctx: ShellContext): SettingsNavItem[] {
    const hasOrganization = Boolean(ctx.props.organization?.current);

    return [...settingsItems.values()]
        .filter((item) => (!item.permission || ctx.can(item.permission)) && (!item.requiresOrganization || hasOrganization))
        .sort((a, b) => a.order - b.order);
}

export function registerSiteTabs(...tabs: SiteTab[]): void {
    tabs.forEach((tab) => siteTabs.set(tab.id, tab));
}

export function siteTabsFor(ctx: ShellContext): SiteTab[] {
    return [...siteTabs.values()].filter((tab) => !tab.permission || ctx.can(tab.permission)).sort((a, b) => a.order - b.order);
}

export function navigationFor(ctx: ShellContext): ModuleNavItem[] {
    return [...navItems.values()].filter((item) => !item.permission || ctx.can(item.permission)).sort((a, b) => a.order - b.order);
}

export function registeredCommandProviders(): CommandProvider[] {
    return [...commandProviders.values()];
}

export function shellContext(props: SharedData): ShellContext {
    const permissions = props.organization?.current?.permissions ?? [];

    return { props, permissions, can: (permission: string) => permissions.includes(permission) };
}
