import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Globe, Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { SiteStatusBadge } from '../components/site-ui';
import { type Option, type SiteStatus } from '../types';

interface SiteRow {
    id: string;
    name: string;
    slug: string;
    runtime: string;
    runtime_label: string;
    framework_label: string;
    repository: string | null;
    branch: string | null;
    primary_domain: string | null;
    test_domain: string | null;
    php_version: string | null;
    status: SiteStatus;
    servers: { id: string; name: string; role: string }[];
}

interface Props {
    sites: SiteRow[];
    filters: { search?: string; runtime?: string; server?: string };
    runtimes: Option[];
    serverOptions: { id: string; name: string }[];
    can: { create: boolean };
}

const ALL = 'all';
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sites', href: '/sites' }];

export default function Index({ sites, filters, runtimes, serverOptions, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next: Partial<Props['filters']>) => {
        const query = { ...filters, search, ...next };
        const cleaned = Object.fromEntries(Object.entries(query).filter(([, value]) => value && value !== ALL));
        router.get('/sites', cleaned, { preserveState: true, replace: true });
    };

    const submitSearch: FormEventHandler = (event) => {
        event.preventDefault();
        apply({ search });
    };

    const filtered = Boolean(filters.search || filters.runtime || filters.server);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sites" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Sites" description="Applications deployed to your servers" />
                    {can.create && (
                        <Button asChild>
                            <Link href="/sites/create">
                                <Plus /> Create site
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={submitSearch} className="flex-1 sm:max-w-xs">
                        <Input
                            placeholder="Search name or repository…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            aria-label="Search sites"
                        />
                    </form>
                    <Select value={filters.runtime ?? ALL} onValueChange={(value) => apply({ runtime: value })}>
                        <SelectTrigger className="w-44" aria-label="Filter by runtime">
                            <SelectValue placeholder="All runtimes" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All runtimes</SelectItem>
                            {runtimes.map((runtime) => (
                                <SelectItem key={runtime.value} value={runtime.value}>
                                    {runtime.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.server ?? ALL} onValueChange={(value) => apply({ server: value })}>
                        <SelectTrigger className="w-44" aria-label="Filter by server">
                            <SelectValue placeholder="All servers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All servers</SelectItem>
                            {serverOptions.map((server) => (
                                <SelectItem key={server.id} value={server.id}>
                                    {server.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {sites.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <Globe className="text-muted-foreground size-8" />
                            <p className="text-muted-foreground text-sm">{filtered ? 'No sites match these filters.' : 'No sites yet.'}</p>
                            {can.create && !filtered && (
                                <Button asChild size="sm">
                                    <Link href="/sites/create">Create your first site</Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Runtime</TableHead>
                                    <TableHead className="hidden md:table-cell">Repository</TableHead>
                                    <TableHead className="hidden lg:table-cell">Servers</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sites.map((site) => (
                                    <TableRow key={site.id} className="cursor-pointer" onClick={() => router.visit(`/sites/${site.id}`)}>
                                        <TableCell>
                                            <Link
                                                href={`/sites/${site.id}`}
                                                className="font-medium hover:underline"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {site.name}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">
                                                {site.primary_domain ?? site.test_domain ?? site.slug}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="text-sm">{site.runtime_label}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {site.framework_label}
                                                {site.php_version ? ` · PHP ${site.php_version}` : ''}
                                            </div>
                                        </TableCell>
                                        <TableCell className="hidden font-mono text-xs md:table-cell">
                                            {site.repository ? `${site.repository}${site.branch ? `@${site.branch}` : ''}` : '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground hidden text-sm lg:table-cell">
                                            {site.servers.map((server) => server.name).join(', ') || '—'}
                                        </TableCell>
                                        <TableCell>
                                            <SiteStatusBadge status={site.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
