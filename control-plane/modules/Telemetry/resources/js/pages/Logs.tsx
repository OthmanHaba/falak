import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { ChevronDown, ChevronRight, Search } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import { getJson, queryString } from '../lib';
import { type LogLineDto } from '../types';

interface Filters {
    server_id?: string;
    site_id?: string;
    service?: string;
    level?: string;
    search?: string;
    regex?: boolean | string;
    trace_id?: string;
    range?: string;
    from?: string;
    to?: string;
}

interface Props {
    filters: Filters;
    servers: { id: string; name: string }[];
    levels: string[];
    configured: boolean;
}

interface LogsResponse {
    lines: LogLineDto[];
    next_before: string | null;
}

const ANY = 'any';
const RANGES = ['15m', '1h', '6h', '24h', '7d'];
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Logs', href: '/telemetry/logs' }];

function levelOf(line: LogLineDto): string | undefined {
    return line.metadata.severity_text ?? line.metadata.detected_level ?? line.labels.detected_level ?? line.labels.level;
}

function levelClass(level: string | undefined): string {
    const value = level?.toLowerCase() ?? '';
    if (value.startsWith('err') || value.startsWith('fatal') || value.startsWith('crit')) return 'border-l-red-500';
    if (value.startsWith('warn')) return 'border-l-amber-500';
    if (value.startsWith('debug') || value.startsWith('trace')) return 'border-l-zinc-400';

    return 'border-l-sky-500';
}

function LogRow({ line }: { line: LogLineDto }) {
    const [open, setOpen] = useState(false);
    const level = levelOf(line);

    return (
        <div className={cn('border-b border-l-2 font-mono text-xs', levelClass(level))}>
            <button
                type="button"
                className="hover:bg-muted/50 flex w-full items-start gap-2 px-2 py-1 text-left"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
            >
                {open ? <ChevronDown className="mt-0.5 size-3 shrink-0" /> : <ChevronRight className="mt-0.5 size-3 shrink-0" />}
                <span className="text-muted-foreground shrink-0 whitespace-nowrap">{format(new Date(line.at), 'MM-dd HH:mm:ss.SSS')}</span>
                {level && <span className="w-12 shrink-0 uppercase">{level.slice(0, 5)}</span>}
                <span className="text-muted-foreground shrink-0">{line.labels.service_name ?? ''}</span>
                <span className="min-w-0 break-all whitespace-pre-wrap">{line.line}</span>
            </button>
            {open && (
                <div className="bg-muted/30 space-y-2 px-8 py-2">
                    {line.trace_id && (
                        <Link href={`/telemetry/traces/${line.trace_id}`} className="text-primary hover:underline">
                            View trace {line.trace_id}
                        </Link>
                    )}
                    <dl className="grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-3 gap-y-0.5">
                        {[...Object.entries(line.labels), ...Object.entries(line.metadata)].map(([key, value]) => (
                            <div key={key} className="contents">
                                <dt className="text-muted-foreground">{key}</dt>
                                <dd className="break-all">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </div>
    );
}

export default function Logs({ filters: initial, servers, levels, configured }: Props) {
    const [filters, setFilters] = useState<Filters>({ range: '1h', ...initial, regex: initial.regex === true || initial.regex === '1' });
    const [lines, setLines] = useState<LogLineDto[]>([]);
    const [nextBefore, setNextBefore] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const load = useCallback(async (current: Filters, before?: string) => {
        setLoading(true);
        setError(null);

        try {
            const params = { ...current, regex: current.regex === true, before, limit: 200 };
            const body = await getJson<LogsResponse>(`/telemetry/logs/data${queryString(params)}`);
            setLines((existing) => (before ? [...existing, ...body.lines] : body.lines));
            setNextBefore(body.next_before);

            if (!before) {
                window.history.replaceState(null, '', `/telemetry/logs${queryString({ ...current, regex: current.regex === true })}`);
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to load logs');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (configured) void load({ range: '1h', ...initial, regex: initial.regex === true || initial.regex === '1' });
    }, [configured, initial, load]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        void load(filters);
    };

    const set = (key: keyof Filters) => (value: string) => setFilters((current) => ({ ...current, [key]: value === ANY ? undefined : value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Logs" />
            <div className="space-y-6 p-4">
                <Heading title="Logs" description="Application, server and agent logs (Loki)" />
                {!configured && (
                    <Alert>
                        <AlertDescription>Loki is not configured (KILN_LOKI_URL).</AlertDescription>
                    </Alert>
                )}
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <div className="space-y-1 sm:col-span-2">
                        <Label htmlFor="search">Contains</Label>
                        <Input id="search" value={filters.search ?? ''} onChange={(e) => set('search')(e.target.value)} placeholder="Search text…" />
                        <label className="text-muted-foreground flex items-center gap-2 text-xs">
                            <Checkbox
                                checked={filters.regex === true}
                                onCheckedChange={(checked) => setFilters((c) => ({ ...c, regex: checked === true }))}
                            />
                            Regular expression
                        </label>
                    </div>
                    <div className="space-y-1">
                        <Label>Server</Label>
                        <Select value={filters.server_id ?? ANY} onValueChange={set('server_id')}>
                            <SelectTrigger aria-label="Server">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All servers</SelectItem>
                                {servers.map((server) => (
                                    <SelectItem key={server.id} value={server.id}>
                                        {server.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <Label>Level</Label>
                        <Select value={filters.level ?? ANY} onValueChange={set('level')}>
                            <SelectTrigger aria-label="Level">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any level</SelectItem>
                                {levels.map((level) => (
                                    <SelectItem key={level} value={level}>
                                        {level}
                                    </SelectItem>
                                ))}
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
                            <Search /> Run
                        </Button>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="service">Service</Label>
                        <Input
                            id="service"
                            value={filters.service ?? ''}
                            onChange={(e) => set('service')(e.target.value)}
                            placeholder="service.name"
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="site">Site id</Label>
                        <Input id="site" value={filters.site_id ?? ''} onChange={(e) => set('site_id')(e.target.value)} />
                    </div>
                    <div className="space-y-1 sm:col-span-2">
                        <Label htmlFor="trace">Trace id</Label>
                        <Input id="trace" value={filters.trace_id ?? ''} onChange={(e) => set('trace_id')(e.target.value)} className="font-mono" />
                    </div>
                </form>
                {error && <p className="text-destructive text-sm">{error}</p>}
                <Card>
                    <CardContent className="p-0">
                        {lines.length === 0 && !loading && (
                            <p className="text-muted-foreground py-10 text-center text-sm">No log lines in this range.</p>
                        )}
                        {lines.map((line, index) => (
                            <LogRow key={`${line.ts}-${index}`} line={line} />
                        ))}
                    </CardContent>
                </Card>
                {nextBefore && (
                    <div className="flex justify-center">
                        <Button variant="outline" disabled={loading} onClick={() => void load(filters, nextBefore)}>
                            Load older
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
