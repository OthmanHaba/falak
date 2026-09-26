import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { type Build, ms, statusClass } from '../types';

interface Props {
    builds: { data: Build[]; links: { prev: string | null; next: string | null }; total: number };
    sites: { id: string; name: string }[];
    filters: { site: string | null };
}

export default function Index({ builds, sites, filters }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Builds', href: '/builds' }]}>
            <Head title="Builds" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Builds</h1>
                        <p className="text-muted-foreground text-sm">
                            Release tarballs and images built once per commit, then deployed to every server.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            aria-label="Filter by site"
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            value={filters.site ?? ''}
                            onChange={(e) => router.get('/builds', e.target.value ? { site: e.target.value } : {}, { preserveState: true })}
                        >
                            <option value="">All sites</option>
                            {sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </select>
                        <Button variant="outline" asChild>
                            <Link href="/builds/builders">Builders</Link>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                        <CardDescription>{builds.total} builds</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Commit</TableHead>
                                    <TableHead>Mode</TableHead>
                                    <TableHead>Builder</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>Created</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {builds.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-muted-foreground text-center">
                                            No builds yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {builds.data.map((build) => (
                                    <TableRow key={build.id} className="cursor-pointer" onClick={() => router.visit(`/builds/${build.id}`)}>
                                        <TableCell className="font-medium">{build.site_name}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className={cn('border-transparent', statusClass(build.status))}>
                                                {build.status.replace('_', ' ')}
                                            </Badge>
                                            {build.reused_build_id && <span className="text-muted-foreground ml-2 text-xs">reused</span>}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {build.branch && <span className="text-muted-foreground">{build.branch}@</span>}
                                            {build.commit?.slice(0, 7) ?? 'HEAD'}
                                        </TableCell>
                                        <TableCell>{build.mode}</TableCell>
                                        <TableCell>{build.builder ?? '—'}</TableCell>
                                        <TableCell>{ms(build.duration_ms)}</TableCell>
                                        <TableCell>{new Date(build.created_at).toLocaleString()}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="mt-4 flex justify-end gap-2">
                            {builds.links.prev && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={builds.links.prev}>Newer</Link>
                                </Button>
                            )}
                            {builds.links.next && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={builds.links.next}>Older</Link>
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
