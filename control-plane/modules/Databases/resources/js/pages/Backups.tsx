import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BackupsTable } from '../components/backups-table';
import { type BackupRow } from '../types';

interface Props {
    backups: { data: BackupRow[]; current_page: number; last_page: number; total: number };
    filters: { status?: string; search?: string };
    can: { manage: boolean; restore: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Databases', href: '/databases' },
    { title: 'Backups', href: '/databases/backups' },
];

export default function Backups({ backups, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next: { status?: string; search?: string; page?: number }) => {
        const query = { status: filters.status, search: filters.search, ...next };
        router.get(
            '/databases/backups',
            Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined && value !== '' && value !== 'all')),
            { preserveState: true, preserveScroll: true },
        );
    };

    const running = backups.data.some((backup) => backup.status === 'pending' || backup.status === 'running');

    useEffect(() => {
        if (!running) return;
        const timer = window.setInterval(() => router.reload({ only: ['backups'] }), 5000);

        return () => window.clearInterval(timer);
    }, [running]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Database backups" />
            <div className="space-y-6 p-4">
                <Heading title="Backups" description={`${backups.total} backup(s) across all database servers`} />
                <div className="flex flex-wrap gap-2">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            apply({ search, page: undefined });
                        }}
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search database or server" className="w-64" />
                    </form>
                    <Select value={filters.status ?? 'all'} onValueChange={(value) => apply({ status: value, page: undefined })}>
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {['pending', 'running', 'succeeded', 'failed', 'pruned'].map((status) => (
                                <SelectItem key={status} value={status} className="capitalize">
                                    {status}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <Card className="py-0">
                    <BackupsTable backups={backups.data} showServer canManage={can.manage} canRestore={can.restore} />
                </Card>
                {backups.last_page > 1 && (
                    <div className="flex items-center justify-end gap-2 text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={backups.current_page <= 1}
                            onClick={() => apply({ page: backups.current_page - 1 })}
                        >
                            Previous
                        </Button>
                        <span className="text-muted-foreground">
                            Page {backups.current_page} of {backups.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={backups.current_page >= backups.last_page}
                            onClick={() => apply({ page: backups.current_page + 1 })}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
