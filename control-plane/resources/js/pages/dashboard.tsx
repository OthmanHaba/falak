import { AppShell } from '@/components/kiln/app-shell';
import { Button } from '@/components/kiln/button';
import { openCommandPalette } from '@/components/kiln/command-palette';
import { EmptyState } from '@/components/kiln/empty-state';
import { Kbd } from '@/components/kiln/kbd';
import { PageHeader } from '@/components/kiln/section';
import { navigationFor, shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, FolderKanban, Search } from 'lucide-react';

/** Temporary home until the Projects grid (`Projects/Index`) replaces it. */
export default function Dashboard() {
    const { props } = usePage<SharedData>();
    const destinations = navigationFor(shellContext(props)).slice(0, 6);

    return (
        <AppShell>
            <Head title="Home" />
            <div className="grid gap-6">
                <PageHeader title="Projects" description="Projects group the services (sites, databases) you deploy together, per environment." />
                <EmptyState
                    icon={<FolderKanban />}
                    title="Your projects will live here"
                    description="Each project is a canvas of services with production and staging environments. Until then, jump straight to your infrastructure."
                    action={
                        props.kiln ? (
                            <Button variant="primary" asChild>
                                <Link href="/projects">
                                    Open projects <ArrowRight />
                                </Link>
                            </Button>
                        ) : (
                            <Button variant="primary" icon={<Search />} onClick={openCommandPalette}>
                                Search everything <Kbd className="bg-on-accent/20 text-on-accent ml-1 border-transparent">⌘K</Kbd>
                            </Button>
                        )
                    }
                />
                {destinations.length > 0 && (
                    <nav aria-label="Quick links" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {destinations.map((item) => (
                            <Link
                                key={item.id}
                                href={item.url}
                                className="group border-border bg-surface-1 hover:border-border-strong hover:bg-surface-2 flex items-center gap-3 rounded-lg border px-4 py-3 transition-colors duration-150"
                            >
                                <item.icon className="text-fg-muted size-4" aria-hidden />
                                <span className="text-fg text-sm font-medium">{item.title}</span>
                                <ArrowRight
                                    className="text-fg-faint ml-auto size-4 transition-transform duration-150 group-hover:translate-x-0.5"
                                    aria-hidden
                                />
                            </Link>
                        ))}
                    </nav>
                )}
            </div>
        </AppShell>
    );
}
