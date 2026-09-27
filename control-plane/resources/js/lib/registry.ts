import { type CanvasService, type ServiceKind, type SharedData } from '@/types';
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

/**
 * What a service panel tab / action knows about the open service (docs/UI_DESIGN.md §5). The panel is one page;
 * each tab fetches its own data as JSON from the owning module's endpoints.
 */
export interface ServicePanelContext {
    project: { id: string; name: string };
    environment: { id: string; name: string; slug: string };
    service: CanvasService;
    /** Canvas URL of the environment (closing the panel goes here). */
    canvasUrl: string;
    /** Panel URL without the tab: /projects/{p}/{env}/service/{kind}/{id}. */
    baseUrl: string;
    /** Active tab id (URL segment). */
    tab: string;
    /** Record inside the tab (…/{tab}/{item}), e.g. the deployment shown in the Deploy view. */
    item: string | null;
    /** Navigate inside the panel without leaving the canvas. */
    open: (tab: string, item?: string | null) => void;
    /** Re-fetch the canvas read model (card status, panel header). */
    refresh: () => void;
    /** Leave the panel (e.g. after deleting the service). */
    close: () => void;
    can: (permission: string) => boolean;
}

export interface ServiceTabProps {
    ctx: ServicePanelContext;
}

/**
 * A tab of the service panel. `id` is its URL segment. Modules register theirs in register.ts:
 * Deployments: deployments 100 · Sites: variables 200, settings 900 · Telemetry: metrics 300, logs 400 ·
 * Insights: observability 500 · Processes: processes 600. Databases: overview 100, databases 200, backups 300,
 * metrics 400, settings 900.
 */
export interface ServiceTab {
    id: string;
    kinds: ServiceKind[];
    title: string;
    order: number;
    permission?: string;
    component: ComponentType<ServiceTabProps>;
    /** Only for some services of the kind (e.g. the Services tab of compose sites). Hidden until the service is loaded. */
    when?: (service: CanvasService) => boolean;
    /**
     * A stand-in until the owning module ships the tab: never replaces a real registration, and a real
     * registration with the same id replaces it regardless of load order.
     */
    placeholder?: boolean;
}

export interface ServiceActionDialogProps {
    ctx: ServicePanelContext;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * An action in the panel header (§5): the one `primary` action renders as a button (Deploy for sites, Connect for
 * databases), the rest go into the `⋯` menu, ordered. Either `perform` it or open its `dialog`.
 */
export interface ServiceAction {
    id: string;
    kinds: ServiceKind[];
    label: string;
    order: number;
    icon?: LucideIcon;
    primary?: boolean;
    danger?: boolean;
    permission?: string;
    /** Hide the action when this returns false (e.g. "Open site" without a URL). */
    when?: (ctx: ServicePanelContext) => boolean;
    perform?: (ctx: ServicePanelContext) => void | Promise<void>;
    dialog?: ComponentType<ServiceActionDialogProps>;
    /** Menu separator above this action. */
    separated?: boolean;
}

/**
 * A block of the service panel's Settings tab (§5.1): one long page with anchored sections and a left mini-nav.
 * Several modules contribute to one section (e.g. Deploy = Deployments' strategy/health check + Sites' deploy script),
 * so each registration names its `section` (anchor + nav entry) and is ordered inside it.
 *
 * Sections and their order: source 100 · build 200 · deploy 300 · networking 400 · servers 500 · laravel 600 ·
 * commands 700 · danger 900. Registrations: `order` = section base + position (e.g. deploy 310, 320 …).
 */
export interface ServiceSettingsSection {
    /** Unique id, e.g. 'sites.deploy-script'. */
    id: string;
    kinds: ServiceKind[];
    /** Anchor / nav id of the section this block belongs to. */
    section: string;
    /** Nav label of the section (the first block of the section provides it). */
    sectionTitle: string;
    order: number;
    permission?: string;
    /** Hide the block (e.g. Laravel only for Laravel sites). */
    when?: (ctx: ServicePanelContext) => boolean;
    component: ComponentType<ServiceTabProps>;
}

/**
 * Window event that opens the canvas Create picker (⌘K → Create service). A CustomEvent with `detail.option` opens a
 * registered create option directly (e.g. `new CustomEvent(CREATE_SERVICE_EVENT, {detail: {option: 'template'}})`).
 */
export const CREATE_SERVICE_EVENT = 'kiln:canvas-create';

export interface CreateOptionProps {
    projectId: string;
    environmentSlug: string;
    /** Canvas coordinates for the new card; auto-placed when null. */
    position: { x: number; y: number } | null;
    /** The new card (and the first deployment when one was started): the canvas adds it and opens its panel. */
    onCreated: (service: CanvasService, deploymentId: string | null) => void;
    onClose: () => void;
}

/**
 * A kind in the canvas Create picker (§4) contributed by another module (e.g. Templates' "Template"). Projects renders
 * the built-in kinds (git, database, docker image, empty) and appends these; picking one renders its `component`
 * inside the picker.
 */
export interface CreateOption {
    id: string;
    title: string;
    description: string;
    icon: LucideIcon;
    /** Lower comes first; built-in kinds use 100–400. */
    order: number;
    /** Organization permission needed (in addition to managing the project). */
    permission?: string;
    /** Picker header while the option is open (defaults to `title`). */
    stepTitle?: string;
    /** Render the picker wider while the option is open (galleries, long forms). */
    wide?: boolean;
    component: ComponentType<CreateOptionProps>;
}

const navItems = new Map<string, ModuleNavItem>();
const settingsItems = new Map<string, SettingsNavItem>();
const headerItems = new Map<string, HeaderItem>();
const commandProviders = new Map<string, CommandProvider>();
const serviceTabs = new Map<string, ServiceTab>();
const serviceActions = new Map<string, ServiceAction>();
const settingsSections = new Map<string, ServiceSettingsSection>();
const createOptions = new Map<string, CreateOption>();

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

export function registerServiceTabs(...tabs: ServiceTab[]): void {
    tabs.forEach((tab) => {
        for (const kind of tab.kinds) {
            const key = `${kind}:${tab.id}`;
            if (tab.placeholder && serviceTabs.has(key) && !serviceTabs.get(key)?.placeholder) continue;
            serviceTabs.set(key, { ...tab, kinds: [kind] });
        }
    });
}

export function serviceTabsFor(kind: ServiceKind, ctx: Pick<ShellContext, 'can'>, service?: CanvasService | null): ServiceTab[] {
    return [...serviceTabs.values()]
        .filter(
            (tab) => tab.kinds.includes(kind) && (!tab.permission || ctx.can(tab.permission)) && (!tab.when || (service ? tab.when(service) : false)),
        )
        .sort((a, b) => a.order - b.order);
}

export function registerServiceActions(...actions: ServiceAction[]): void {
    actions.forEach((action) => serviceActions.set(action.id, action));
}

export function serviceActionsFor(ctx: ServicePanelContext): ServiceAction[] {
    return [...serviceActions.values()]
        .filter(
            (action) => action.kinds.includes(ctx.service.kind) && (!action.permission || ctx.can(action.permission)) && (action.when?.(ctx) ?? true),
        )
        .sort((a, b) => a.order - b.order);
}

export function registerServiceSettingsSections(...sections: ServiceSettingsSection[]): void {
    sections.forEach((section) => settingsSections.set(section.id, section));
}

/** Visible settings blocks of a service, ordered; group them by `section` for the mini-nav. */
export function serviceSettingsSectionsFor(ctx: ServicePanelContext): ServiceSettingsSection[] {
    return [...settingsSections.values()]
        .filter(
            (section) =>
                section.kinds.includes(ctx.service.kind) && (!section.permission || ctx.can(section.permission)) && (section.when?.(ctx) ?? true),
        )
        .sort((a, b) => a.order - b.order);
}

export function registerCreateOptions(...options: CreateOption[]): void {
    options.forEach((option) => createOptions.set(option.id, option));
}

export function createOptionsFor(ctx: Pick<ShellContext, 'can'>): CreateOption[] {
    return [...createOptions.values()].filter((option) => !option.permission || ctx.can(option.permission)).sort((a, b) => a.order - b.order);
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
