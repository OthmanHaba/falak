import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { type Build, type BuildLine, ms, statusClass, TERMINAL_BUILD } from '../types';

interface Props {
    build: Build;
    lines: BuildLine[];
    can: { cancel: boolean };
}

export default function Show({ build: initial, lines: initialLines, can }: Props) {
    const [build, setBuild] = useState(initial);
    const [lines, setLines] = useState(initialLines);
    const lastSeq = useRef(initialLines.length ? initialLines[initialLines.length - 1].seq : 0);
    const pane = useRef<HTMLDivElement>(null);
    const terminal = TERMINAL_BUILD.includes(build.status);

    const append = useCallback((incoming: BuildLine[]) => {
        const fresh = incoming.filter((line) => line.seq > lastSeq.current);

        if (fresh.length > 0) {
            lastSeq.current = fresh[fresh.length - 1].seq;
            setLines((current) => [...current, ...fresh]);
        }
    }, []);

    const refresh = useCallback(async () => {
        const response = await fetch(`/builds/${initial.id}/output?after=${lastSeq.current}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const body = (await response.json()) as { data: { build: Build; lines: BuildLine[] } };
            setBuild(body.data.build);
            append(body.data.lines);
        }
    }, [initial.id, append]);

    const live = useEchoChannel<{ lines?: BuildLine[] }>(`builds.${initial.id}`, ['build.output', 'build.updated'], (event, payload) => {
        if (event === 'build.output' && payload.lines) {
            append(payload.lines);
        } else {
            void refresh();
        }
    });

    useEffect(() => {
        if (terminal) {
            return;
        }

        const timer = window.setInterval(() => void refresh(), live ? 10000 : 2000);

        return () => window.clearInterval(timer);
    }, [terminal, live, refresh]);

    useEffect(() => {
        if (pane.current) {
            pane.current.scrollTop = pane.current.scrollHeight;
        }
    }, [lines]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Builds', href: '/builds' },
                { title: build.id.toUpperCase().slice(-8), href: `/builds/${build.id}` },
            ]}
        >
            <Head title={`Build · ${build.site_name}`} />
            <div className="space-y-6 p-4">
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle className="flex items-center gap-3">
                                {build.site_name}
                                <Badge variant="outline" className={cn('border-transparent', statusClass(build.status))}>
                                    {build.status.replace('_', ' ')}
                                    {build.status === 'running' && build.progress !== null ? ` · ${Math.round(build.progress * 100)}%` : ''}
                                </Badge>
                            </CardTitle>
                            <CardDescription className="mt-1 flex flex-wrap gap-x-4">
                                <span className="font-mono">
                                    {build.branch}@{build.commit?.slice(0, 12) ?? 'HEAD'}
                                </span>
                                <span>{build.mode}</span>
                                {build.builder && <span>on {build.builder}</span>}
                                {build.duration_ms !== null && <span>{ms(build.duration_ms)}</span>}
                                {build.deployment_id && (
                                    <Link href={`/sites/${build.site_id}/deployments/${build.deployment_id}`} className="hover:underline">
                                        deployment
                                    </Link>
                                )}
                            </CardDescription>
                        </div>
                        {can.cancel && !terminal && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.post(`/builds/${build.id}/cancel`, {}, { onSuccess: () => void refresh() })}
                            >
                                Cancel build
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {build.error && <p className="text-red-600">{build.error}</p>}
                        {build.artifact && (
                            <p className="text-muted-foreground">
                                Artifact {build.artifact.format} · <span className="font-mono">{build.artifact.sha256.slice(0, 16)}…</span>
                                {build.artifact.size_bytes !== null && ` · ${(build.artifact.size_bytes / 1024 / 1024).toFixed(1)} MB`}
                                {build.artifact.pruned && ' · pruned'}
                            </p>
                        )}
                        {build.image && <p className="text-muted-foreground font-mono text-xs break-all">{build.image}</p>}
                    </CardContent>
                </Card>

                <div
                    ref={pane}
                    className="max-h-[36rem] min-h-40 overflow-auto rounded-lg border bg-neutral-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-neutral-100"
                >
                    {lines.length === 0 && <span className="text-neutral-500">{terminal ? 'No output.' : 'Waiting for a builder…'}</span>}
                    {lines.map((line) => (
                        <div key={line.seq} className={cn(line.stream === 'stderr' && 'text-red-300')}>
                            {line.data.replace(/\n$/, '')}
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
