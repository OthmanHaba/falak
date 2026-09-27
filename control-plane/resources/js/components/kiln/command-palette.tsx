import { defaultEnvironment, projectUrl } from '@/lib/kiln';
import { projectsUi } from '@/lib/pages';
import { navigationFor, registeredCommandProviders, settingsNavFor, shellContext, type PaletteCommand, type ShellContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Command } from 'cmdk';
import { Clock, CornerDownLeft, FolderKanban, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Kbd } from './kbd';

const OPEN_EVENT = 'kiln:command-palette';
const RECENT_KEY = 'kiln:palette-recent';
const MAX_RECENT = 6;
const GROUP_ORDER = ['Recent', 'Navigation', 'Projects', 'Actions', 'Servers', 'Sites', 'Organization', 'Settings', 'Preferences'];

export function openCommandPalette(): void {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

interface RecentEntry {
    id: string;
    title: string;
    href?: string;
}

function readRecent(): RecentEntry[] {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]');

        return Array.isArray(parsed) ? (parsed as RecentEntry[]).filter((entry) => typeof entry?.id === 'string').slice(0, MAX_RECENT) : [];
    } catch {
        return [];
    }
}

function remember(command: PaletteCommand): void {
    try {
        const entry: RecentEntry = { id: command.id, title: command.title, href: command.href };
        const next = [entry, ...readRecent().filter((item) => item.id !== command.id)].slice(0, MAX_RECENT);
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
        // Storage unavailable (private mode): recents are a convenience only.
    }
}

/** Commands contributed by the shell itself: registered nav items, settings sections and projects (§9 `kiln` prop). */
function shellCommands(ctx: ShellContext): PaletteCommand[] {
    const nav: PaletteCommand[] = navigationFor(ctx).map((item) => ({
        id: `nav.${item.id}`,
        title: item.title,
        group: 'Navigation',
        icon: item.icon,
        href: item.url,
    }));

    const settings: PaletteCommand[] = settingsNavFor(ctx).map((item) => ({
        id: `settings.${item.id}`,
        title: item.title,
        subtitle: item.group === 'organization' ? 'Organization settings' : 'Account settings',
        group: 'Settings',
        icon: item.icon,
        href: item.url,
        keywords: item.keywords,
    }));

    const projects: PaletteCommand[] = (projectsUi.canvas() ? (ctx.props.kiln?.projects ?? []) : []).flatMap((project) => {
        const primary = defaultEnvironment(project);

        return [
            { id: `project.${project.id}`, title: project.name, group: 'Projects', icon: FolderKanban, href: projectUrl(project, primary) },
            ...project.environments
                .filter((env) => env.id !== primary?.id)
                .map((env) => ({
                    id: `project.${project.id}.${env.id}`,
                    title: `${project.name} · ${env.name}`,
                    group: 'Projects',
                    icon: FolderKanban,
                    href: projectUrl(project, env),
                    keywords: [env.slug],
                })),
        ];
    });

    return [...nav, ...projects, ...settings];
}

/** Merge and dedupe (first by id, then same href in the same group: registered commands win over derived ones). */
function merge(commands: PaletteCommand[], ctx: ShellContext): PaletteCommand[] {
    const byId = new Map<string, PaletteCommand>();
    const seenHref = new Set<string>();

    for (const command of commands) {
        if (command.permission && !ctx.can(command.permission)) continue;
        const hrefKey = command.href ? `${command.group}|${command.href}` : null;
        if (byId.has(command.id) || (hrefKey && seenHref.has(hrefKey))) continue;
        byId.set(command.id, command);
        if (hrefKey) seenHref.add(hrefKey);
    }

    return [...byId.values()];
}

async function collect(ctx: ShellContext, query: string, staticOnly = false): Promise<PaletteCommand[]> {
    const results = await Promise.all(
        registeredCommandProviders()
            .filter((provider) => (staticOnly ? (provider.minQueryLength ?? 0) === 0 : query.length >= (provider.minQueryLength ?? 0)))
            .map(async (provider) => {
                try {
                    return await provider.commands({ ...ctx, query });
                } catch {
                    return [];
                }
            }),
    );

    // Registered (module) commands first so they win the href dedupe against derived nav entries.
    return merge([...results.flat(), ...shellCommands(ctx)], ctx);
}

function run(command: PaletteCommand): void {
    remember(command);
    if (command.href) router.visit(command.href);
    else command.perform?.();
}

function isTyping(target: EventTarget | null): boolean {
    const element = target as HTMLElement | null;

    return Boolean(element && (element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)));
}

/**
 * ⌘K / Ctrl+K palette: grouped results from every module's register.ts, recent items, and live two-key shortcuts
 * (`g p` projects, `g s` servers, `g o` observability, …) for any command that declares one.
 */
export function CommandPalette() {
    const { props } = usePage<SharedData>();
    const ctx = useMemo(() => shellContext(props), [props]);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [commands, setCommands] = useState<PaletteCommand[]>([]);
    const [recent, setRecent] = useState<RecentEntry[]>([]);
    const [loading, setLoading] = useState(false);
    const pendingG = useRef<number | null>(null);

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
    }, []);

    // ⌘K + two-key sequences.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setOpen((value) => !value);

                return;
            }
            if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target) || event.defaultPrevented) return;
            if (document.querySelector('[role="dialog"]')) return;

            const key = event.key.toLowerCase();
            const now = Date.now();

            if (pendingG.current !== null && now - pendingG.current < 1200 && key.length === 1) {
                pendingG.current = null;
                const shortcut = `G ${key.toUpperCase()}`;
                void collect(ctx, '', true).then((all) => {
                    const target = all.find((command) => command.shortcut?.toUpperCase() === shortcut);
                    if (target) run(target);
                });

                return;
            }
            pendingG.current = key === 'g' ? now : null;
        };
        const onOpen = () => setOpen(true);

        window.addEventListener('keydown', onKeyDown);
        window.addEventListener(OPEN_EVENT, onOpen);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener(OPEN_EVENT, onOpen);
        };
    }, [ctx]);

    useEffect(() => {
        if (open) setRecent(readRecent());
    }, [open]);

    useEffect(() => {
        if (!open) return;

        let cancelled = false;
        setLoading(true);
        const timer = window.setTimeout(
            async () => {
                const results = await collect(ctx, query);
                if (!cancelled) {
                    setCommands(results);
                    setLoading(false);
                }
            },
            query.length > 0 ? 150 : 0,
        );

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [open, query, ctx]);

    const groups = useMemo(() => {
        const byGroup = new Map<string, PaletteCommand[]>();

        if (query.length === 0) {
            const recentCommands = recent
                .map(
                    (entry) =>
                        commands.find((command) => command.id === entry.id) ??
                        (entry.href ? { id: entry.id, title: entry.title, group: 'Recent', href: entry.href } : null),
                )
                .filter((command): command is PaletteCommand => command !== null)
                .map((command) => ({ ...command, group: 'Recent', icon: command.icon ?? Clock }));
            if (recentCommands.length > 0) byGroup.set('Recent', recentCommands);
        }

        commands.forEach((command) => byGroup.set(command.group, [...(byGroup.get(command.group) ?? []), command]));

        const rank = (group: string) => {
            const index = GROUP_ORDER.indexOf(group);

            return index === -1 ? GROUP_ORDER.length - 2.5 : index;
        };

        return [...byGroup.entries()].sort(([a], [b]) => rank(a) - rank(b));
    }, [commands, recent, query]);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={(value) => (value ? setOpen(true) : close())}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="animate-fade-in bg-overlay fixed inset-0 z-50" />
                <DialogPrimitive.Content
                    className="animate-dialog-in border-border bg-surface-1 shadow-panel fixed top-[12vh] left-1/2 z-50 w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 overflow-hidden rounded-xl border"
                    aria-describedby={undefined}
                >
                    <DialogPrimitive.Title className="sr-only">Command palette</DialogPrimitive.Title>
                    <Command label="Command palette" loop className="flex flex-col">
                        <div className="border-border flex items-center gap-2.5 border-b px-4">
                            <Search className="text-fg-faint size-4 shrink-0" aria-hidden />
                            <Command.Input
                                value={query}
                                onValueChange={setQuery}
                                placeholder="Search projects, servers, pages and actions…"
                                className="text-fg placeholder:text-fg-faint h-12 w-full bg-transparent text-base outline-none"
                            />
                            <Kbd>Esc</Kbd>
                        </div>
                        <Command.List className="max-h-[min(60vh,420px)] overflow-y-auto overscroll-contain p-2">
                            {loading && commands.length === 0 ? (
                                <Command.Loading>
                                    <p className="text-fg-faint px-2 py-6 text-center text-sm">Loading…</p>
                                </Command.Loading>
                            ) : (
                                <Command.Empty className="text-fg-faint px-2 py-8 text-center text-sm">No results for “{query}”.</Command.Empty>
                            )}
                            {groups.map(([group, items]) => (
                                <Command.Group
                                    key={group}
                                    heading={group}
                                    className="[&_[cmdk-group-heading]]:text-2xs [&_[cmdk-group-heading]]:text-fg-faint mb-1 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:tracking-wide [&_[cmdk-group-heading]]:uppercase"
                                >
                                    {items.map((command) => (
                                        <Command.Item
                                            key={`${group}.${command.id}`}
                                            value={`${group} ${command.title} ${command.id}`}
                                            keywords={command.keywords}
                                            onSelect={() => {
                                                close();
                                                run(command);
                                            }}
                                            className={cn(
                                                'group text-fg-muted flex h-9 cursor-default items-center gap-2.5 rounded-md px-2 text-sm',
                                                'data-[selected=true]:bg-surface-2 data-[selected=true]:text-fg',
                                            )}
                                        >
                                            {command.icon ? (
                                                <command.icon className="text-fg-faint size-4 shrink-0" aria-hidden />
                                            ) : (
                                                <span className="size-4" />
                                            )}
                                            <span className="text-fg truncate">{command.title}</span>
                                            {command.subtitle && <span className="text-fg-faint truncate text-xs">{command.subtitle}</span>}
                                            <span className="ml-auto flex shrink-0 items-center gap-1">
                                                {command.shortcut && command.shortcut.split(' ').map((key) => <Kbd key={key}>{key}</Kbd>)}
                                                <CornerDownLeft
                                                    className="text-fg-faint hidden size-3.5 group-data-[selected=true]:block"
                                                    aria-hidden
                                                />
                                            </span>
                                        </Command.Item>
                                    ))}
                                </Command.Group>
                            ))}
                        </Command.List>
                        <div className="border-border text-2xs text-fg-faint flex items-center gap-3 border-t px-4 py-2">
                            <span className="flex items-center gap-1">
                                <Kbd>↑</Kbd>
                                <Kbd>↓</Kbd> navigate
                            </span>
                            <span className="flex items-center gap-1">
                                <Kbd>↵</Kbd> open
                            </span>
                            <span className="ml-auto hidden items-center gap-1 sm:flex">
                                <Kbd>G</Kbd>
                                <Kbd>P</Kbd> projects · <Kbd>G</Kbd>
                                <Kbd>S</Kbd> servers · <Kbd>G</Kbd>
                                <Kbd>O</Kbd> observability
                            </span>
                        </div>
                    </Command>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
