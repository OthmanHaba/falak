import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import SiteLayout from '@/layouts/site-layout';
import { Link, router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { Commit, when } from '../components/deploy-ui';
import { type Release, type SitePageProps } from '../types';

interface Props extends SitePageProps {
    releases: Release[];
    keep: number;
    can: { rollback: boolean };
}

export default function Releases({ site, releases, keep, can }: Props) {
    const [target, setTarget] = useState<Release | null>(null);
    const [busy, setBusy] = useState(false);

    const rollback = () => {
        if (!target) {
            return;
        }

        setBusy(true);
        router.post(`/sites/${site.id}/releases/${target.id}/rollback`, {}, { onFinish: () => setBusy(false) });
    };

    return (
        <SiteLayout site={site} title="Releases">
            <Card>
                <CardHeader>
                    <CardTitle>Releases</CardTitle>
                    <CardDescription>The newest {keep} releases are kept on every server; roll back to any of them instantly.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Release</TableHead>
                                <TableHead>Commit</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Activated</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {releases.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-muted-foreground text-center">
                                        No releases yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {releases.map((release) => (
                                <TableRow key={release.id}>
                                    <TableCell className="font-mono text-xs">
                                        <Link href={`/sites/${site.id}/deployments/${release.deployment_id}`} className="hover:underline">
                                            {release.id.toUpperCase()}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="max-w-72 truncate">
                                        <Commit sha={release.commit} branch={release.branch} />{' '}
                                        <span className="text-muted-foreground">{release.message?.split('\n')[0]}</span>
                                    </TableCell>
                                    <TableCell>
                                        {release.active ? (
                                            <Badge>current</Badge>
                                        ) : (
                                            <Badge variant="outline" className="capitalize">
                                                {release.status}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{when(release.activated_at)}</TableCell>
                                    <TableCell className="text-right">
                                        {can.rollback && release.can_rollback && (
                                            <Button size="sm" variant="outline" onClick={() => setTarget(release)}>
                                                <RotateCcw className="size-4" /> Roll back
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Dialog open={target !== null} onOpenChange={(open) => !open && setTarget(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Roll back {site.name}?</DialogTitle>
                        <DialogDescription>
                            Every server switches back to release <span className="font-mono">{target?.id.toUpperCase()}</span> (
                            {target?.commit?.slice(0, 7) ?? 'unknown commit'}) and its processes restart. Database migrations are not reverted.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setTarget(null)}>
                            Cancel
                        </Button>
                        <Button onClick={rollback} disabled={busy}>
                            Roll back
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SiteLayout>
    );
}
