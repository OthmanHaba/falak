import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { Search } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import { formatDuration, getJson, queryString } from '../lib';
import { type TraceSummaryDto } from '../types';

interface Filters {
    site_id?: string;
    service?: string;
    name?: string;
    status?: string;
    min_duration_ms?: string;
    range?: string;
    from?: string;
    to?: string;
}

interface Props {
    filters: Filters;
    configured: boolean;
}

const RANGES = ['15m', '1h', '6h', '24h', '7d'];
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Traces', href: '/telemetry/traces' }];

export default function Traces({ filters: initial, configured }: Props) {
    const [filters, setFilters] = useState<Filters>({ range: '1h', status: 'any', ...initial });
    const [traces, setTraces] = useState<TraceSummaryDto[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const run = useCallback(async (current: Filters) => {
        setLoading(true);
        setError(null);

        try {
            const body = await getJson<{ traces: TraceSummaryDto[] }>(`/telemetry/traces/search${queryString({ ...current })}`);
            setTraces(body.traces);
            window.history.replaceState(null, '', `/telemetry/traces${queryString({ ...current })}`);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Search failed');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (configured) void run({ range: '1h', status: 'any', ...initial });
    }, [configured, initial, run]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        void run(filters);
    };

    const set = (key: keyof Filters) => (value: string) => setFilters((current) => ({ ...current, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Traces" />
            <div className="space-y-6 p-4">
                <Heading title="Traces" description="Search distributed traces (Tempo) across your sites" />
                {!configured && (
                    <Alert>
                        <AlertDescription>Tempo is not configured (KILN_TEMPO_URL).</AlertDescription>
                    </Alert>
                )}
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
                    <div className="space-y-1">
                        <Label htmlFor="service">Service</Label>
                        <Input
                            id="service"
                            value={filters.service ?? ''}
                            onChange={(e) => set('service')(e.target.value)}
                            placeholder="shop-example-com"
                        />
                    </div>
                    <div className="space-y-1 lg:col-span-2">
                        <Label htmlFor="name">Span name</Label>
                        <Input id="name" value={filters.name ?? ''} onChange={(e) => set('name')(e.target.value)} placeholder="GET /orders/{order}" />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="min">Min duration (ms)</Label>
                        <Input
                            id="min"
                            type="number"
                            min={0}
                            value={filters.min_duration_ms ?? ''}
                            onChange={(e) => set('min_duration_ms')(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label>Status</Label>
                        <Select value={filters.status ?? 'any'} onValueChange={set('status')}>
                            <SelectTrigger aria-label="Status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="any">Any</SelectItem>
                                <SelectItem value="error">Errors only</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <Label>Range</Label>
                        <Select value={filters.range ?? '1h'} onValueChange={set('range')}>
                            <SelectTrigger aria-label="Time range">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RANGES.map((range) => (
                                    <SelectItem key={range} value={range}>
                                        Last {range}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-end">
                        <Button type="submit" disabled={!configured || loading} className="w-full">
                            <Search /> Search
                        </Button>
                    </div>
                </form>
                {error && <p className="text-destructive text-sm">{error}</p>}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Started</TableHead>
                                    <TableHead>Service</TableHead>
                                    <TableHead>Root span</TableHead>
                                    <TableHead className="text-right">Duration</TableHead>
                                    <TableHead className="text-right">Matched spans</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {traces?.map((trace) => (
                                    <TableRow key={trace.trace_id}>
                                        <TableCell className="whitespace-nowrap">
                                            {format(Number(BigInt(trace.start_unix_nano) / 1_000_000n), 'PP HH:mm:ss')}
                                        </TableCell>
                                        <TableCell>{trace.root_service ?? '—'}</TableCell>
                                        <TableCell className="max-w-md truncate font-mono text-xs">
                                            <Link href={`/telemetry/traces/${trace.trace_id}`} className="hover:underline">
                                                {trace.root_name ?? trace.trace_id}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right">{formatDuration(trace.duration_ms)}</TableCell>
                                        <TableCell className="text-right">{trace.matched_spans}</TableCell>
                                    </TableRow>
                                ))}
                                {traces?.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-muted-foreground py-8 text-center">
                                            No traces match these filters.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
