import { Select, SkeletonRows } from '@/components/kiln';
import { serviceSettingsSectionsFor, type ServiceSettingsSection, type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { Suspense, useEffect, useMemo, useRef, useState } from 'react';

interface Group {
    id: string;
    title: string;
    blocks: ServiceSettingsSection[];
}

function scrollParent(element: HTMLElement | null): HTMLElement | null {
    let node = element?.parentElement ?? null;
    while (node) {
        const overflow = getComputedStyle(node).overflowY;
        if (overflow === 'auto' || overflow === 'scroll') return node;
        node = node.parentElement;
    }

    return null;
}

const anchor = (id: string) => `settings-${id}`;

/**
 * §5.1 Settings: one long page of anchored sections contributed by the owning modules
 * (registerServiceSettingsSections) with a sticky left mini-nav. `…/settings/{section}` deep-links a section.
 */
export function SettingsTab({ ctx }: ServiceTabProps) {
    const groups = useMemo<Group[]>(() => {
        const byId = new Map<string, Group>();
        serviceSettingsSectionsFor(ctx).forEach((block) => {
            const group = byId.get(block.section) ?? { id: block.section, title: block.sectionTitle, blocks: [] };
            group.blocks.push(block);
            byId.set(block.section, group);
        });

        return [...byId.values()];
    }, [ctx]);
    const [active, setActive] = useState<string>(ctx.item ?? groups[0]?.id ?? '');
    const root = useRef<HTMLDivElement>(null);
    const jumping = useRef<number | null>(null);

    const jump = (id: string, smooth = true) => {
        const element = document.getElementById(anchor(id));
        if (!element) return;
        setActive(id);
        jumping.current = window.setTimeout(() => (jumping.current = null), 600);
        element.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
        window.history.replaceState(window.history.state, '', `${ctx.baseUrl}/settings/${id}`);
    };

    // Deep link: scroll once the lazily loaded blocks had a chance to render.
    useEffect(() => {
        if (!ctx.item) return;
        const first = window.setTimeout(() => jump(ctx.item as string, false), 50);
        const settle = window.setTimeout(() => jump(ctx.item as string, false), 700);

        return () => {
            window.clearTimeout(first);
            window.clearTimeout(settle);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ctx.item]);

    // Scroll spy: the topmost visible section is active.
    useEffect(() => {
        const container = scrollParent(root.current);
        if (!container) return;
        const onScroll = () => {
            if (jumping.current !== null) return;
            const top = container.getBoundingClientRect().top;
            let current = groups[0]?.id ?? '';
            for (const group of groups) {
                const element = document.getElementById(anchor(group.id));
                if (element && element.getBoundingClientRect().top - top <= 96) current = group.id;
            }
            if (container.scrollTop + container.clientHeight >= container.scrollHeight - 4) current = groups[groups.length - 1]?.id ?? current;
            setActive(current);
        };
        container.addEventListener('scroll', onScroll, { passive: true });

        return () => container.removeEventListener('scroll', onScroll);
    }, [groups]);

    return (
        <div ref={root} className="grid gap-6 md:grid-cols-[9.5rem_minmax(0,1fr)]" data-testid="settings-tab">
            <nav aria-label="Settings sections" className="md:sticky md:top-0 md:self-start">
                <div className="md:hidden">
                    <Select
                        aria-label="Jump to section"
                        value={active}
                        onValueChange={(id) => jump(id)}
                        options={groups.map((group) => ({ value: group.id, label: group.title }))}
                    />
                </div>
                <ul className="hidden gap-0.5 md:grid">
                    {groups.map((group) => (
                        <li key={group.id}>
                            <a
                                href={`${ctx.baseUrl}/settings/${group.id}`}
                                onClick={(event) => {
                                    event.preventDefault();
                                    jump(group.id);
                                }}
                                aria-current={active === group.id ? 'location' : undefined}
                                className={cn(
                                    'block rounded-md px-2.5 py-1.5 text-sm transition-colors duration-150',
                                    active === group.id ? 'bg-surface-3 text-fg font-medium' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
                                    group.id === 'danger' && active !== group.id && 'text-danger/80 hover:text-danger',
                                )}
                            >
                                {group.title}
                            </a>
                        </li>
                    ))}
                </ul>
            </nav>
            <div className="grid min-w-0 gap-10 pb-[40vh]">
                {groups.map((group) => (
                    <section key={group.id} id={anchor(group.id)} aria-labelledby={`${anchor(group.id)}-title`} className="grid scroll-mt-2 gap-5">
                        <h2
                            id={`${anchor(group.id)}-title`}
                            className={cn('border-border border-b pb-2 text-sm font-semibold', group.id === 'danger' ? 'text-danger' : 'text-fg')}
                        >
                            {group.title}
                        </h2>
                        {group.blocks.map((block) => (
                            <Suspense key={block.id} fallback={<SkeletonRows rows={3} />}>
                                <block.component ctx={ctx} />
                            </Suspense>
                        ))}
                    </section>
                ))}
            </div>
        </div>
    );
}
