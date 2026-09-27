import { AppShell } from '@/components/kiln/app-shell';
import { PageHeader } from '@/components/kiln/section';
import { tabTriggerClasses } from '@/components/kiln/tabs';
import { shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';

interface InfrastructureSection {
    id: string;
    label: string;
    href: string;
    /** Any one of these permissions shows the section. */
    permissions?: string[];
}

/** Organization-wide infrastructure views (§3 `/servers`): the fleet plus the org-level lists that belong to it. */
const SECTIONS: InfrastructureSection[] = [
    { id: 'servers', label: 'Servers', href: '/servers', permissions: ['servers.view'] },
    { id: 'networks', label: 'Private networks', href: '/network', permissions: ['network.view'] },
    { id: 'ssh-keys', label: 'SSH keys', href: '/ssh-keys', permissions: ['servers.view'] },
    {
        id: 'terminal',
        label: 'Terminal sessions',
        href: '/terminal',
        permissions: ['terminal.open', 'terminal.attach', 'terminal.control', 'terminal.recordings.view'],
    },
    { id: 'runs', label: 'Recipe runs', href: '/recipes/runs', permissions: ['recipes.view'] },
];

interface InfrastructureLayoutProps {
    /** Active section id. */
    section: string;
    /** Browser title; defaults to the section label. */
    title?: string;
    description?: ReactNode;
    /** The page's one primary action. */
    actions?: ReactNode;
    children: ReactNode;
}

/** Shell for /servers, /network, /ssh-keys, /terminal and /recipes/runs: "Infrastructure" header + section tabs. */
export default function InfrastructureLayout({ section, title, description, actions, children }: InfrastructureLayoutProps) {
    const { props } = usePage<SharedData>();
    const sections = useMemo(() => {
        const ctx = shellContext(props);

        return SECTIONS.filter((item) => !item.permissions || item.permissions.some((permission) => ctx.can(permission)));
    }, [props]);
    const current = SECTIONS.find((item) => item.id === section) ?? SECTIONS[0];

    return (
        <AppShell
            breadcrumbs={[
                { title: 'Infrastructure', href: '/servers' },
                ...(current.id === 'servers' ? [] : [{ title: current.label, href: current.href }]),
            ]}
        >
            <Head title={title ?? current.label} />
            <div className="grid gap-5">
                <PageHeader title="Infrastructure" description={description} actions={actions} />
                <nav aria-label="Infrastructure sections" className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                    <ul className="border-border flex min-w-max items-center gap-1 border-b">
                        {sections.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={item.href}
                                    prefetch
                                    aria-current={item.id === current.id ? 'page' : undefined}
                                    className={tabTriggerClasses}
                                >
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
                <div className="grid min-w-0 gap-6">{children}</div>
            </div>
        </AppShell>
    );
}
