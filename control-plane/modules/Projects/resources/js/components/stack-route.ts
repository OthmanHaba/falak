import { type ServiceLayer } from '@/lib/registry';
import { type ServiceKind } from '@/types';

/** A service panel in the stack: `/service/{kind}/{id}/{tab}/{item}` (base) or `?peek={kind}:{id}&peek_tab=` (stacked). */
export interface ServiceRef {
    kind: ServiceKind;
    id: string;
    tab: string | null;
    item: string | null;
}

/** A registered detail layer on top of the top-most service panel: `?{param}={record}&{param}_tab={tab}`. */
export interface LayerRef {
    id: string;
    record: string;
    tab: string | null;
}

/**
 * The panel stack of the canvas (docs/UI_DESIGN.md §5.5), fully described by the URL so deep links and browser
 * back/forward restore it: base service panel → optional peeked service → optional detail layer (e.g. a
 * deployment's logs). `focus` maximises the top panel (pop-out).
 */
export interface StackRoute {
    base: ServiceRef | null;
    peek: ServiceRef | null;
    layer: LayerRef | null;
    focus: boolean;
}

const KINDS: ServiceKind[] = ['site', 'database'];

export const EMPTY_STACK: StackRoute = { base: null, peek: null, layer: null, focus: false };

export function parseStack(url: string, home: string, layers: ServiceLayer[]): StackRoute {
    const parsed = new URL(url, 'http://falak.local');
    const query = parsed.searchParams;
    const rest = parsed.pathname.startsWith(`${home}/service/`) ? parsed.pathname.slice(`${home}/service/`.length).split('/') : [];
    const [kind, id, tab = null, item = null] = rest;

    let base: ServiceRef | null =
        kind && id && KINDS.includes(kind as ServiceKind) ? { kind: kind as ServiceKind, id: id.toLowerCase(), tab, item } : null;

    const peekParam = query.get('peek');
    const [peekKind, peekId] = peekParam?.split(':') ?? [];
    const peek: ServiceRef | null =
        base && peekKind && peekId && KINDS.includes(peekKind as ServiceKind)
            ? { kind: peekKind as ServiceKind, id: peekId.toLowerCase(), tab: query.get('peek_tab'), item: null }
            : null;

    let layer: LayerRef | null = null;
    for (const registered of layers) {
        const record = query.get(registered.param);
        if (record && base) {
            layer = { id: registered.id, record: record.toLowerCase(), tab: query.get(`${registered.param}_tab`) };
            break;
        }
    }

    // Legacy deep links: …/deployments/{deployment} opens the deployment layer over the Deployments tab.
    if (!layer && !peek && base?.tab && base.item) {
        const legacy = layers.find((registered) => registered.fromTab === base?.tab);
        if (legacy) {
            layer = { id: legacy.id, record: base.item.toLowerCase(), tab: null };
            base = { ...base, item: null };
        }
    }

    return { base, peek, layer, focus: query.get('focus') === '1' && base !== null };
}

export function buildStack(route: StackRoute, home: string, layers: ServiceLayer[], current?: string): string {
    if (!route.base) return home;
    const { base, peek, layer } = route;
    let path = `${home}/service/${base.kind}/${base.id}`;
    if (base.tab) path += `/${base.tab}${base.item ? `/${base.item}` : ''}`;

    // Keep unrelated query parameters (none today, but other features may add some).
    const query = new URLSearchParams(current ? new URL(current, 'http://falak.local').search : '');
    for (const key of ['peek', 'peek_tab', 'focus', ...layers.flatMap((registered) => [registered.param, `${registered.param}_tab`])])
        query.delete(key);
    if (peek) {
        query.set('peek', `${peek.kind}:${peek.id}`);
        if (peek.tab) query.set('peek_tab', peek.tab);
    }
    const registered = layer ? layers.find((candidate) => candidate.id === layer.id) : undefined;
    if (layer && registered) {
        query.set(registered.param, layer.record);
        if (layer.tab) query.set(`${registered.param}_tab`, layer.tab);
    }
    if (route.focus && (peek || layer)) query.set('focus', '1');
    const search = query.toString();

    return search ? `${path}?${search}` : path;
}
