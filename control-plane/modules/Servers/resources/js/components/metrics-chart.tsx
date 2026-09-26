import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { format } from 'date-fns';
import { useEffect, useMemo, useState } from 'react';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { type MetricSample } from '../types';

const RANGES = ['1h', '6h', '24h'] as const;
type Range = (typeof RANGES)[number];

interface Props {
    serverId: string;
    initial: MetricSample[];
    memoryBytes: number | null;
    diskBytes: number | null;
}

export function MetricsChart({ serverId, initial, memoryBytes, diskBytes }: Props) {
    const [range, setRange] = useState<Range>('1h');
    const [samples, setSamples] = useState<MetricSample[]>(initial);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (range === '1h') {
            setSamples(initial);
        }
    }, [initial, range]);

    const load = async (next: Range) => {
        setRange(next);

        try {
            const response = await fetch(`/servers/${serverId}/metrics?range=${next}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const body = (await response.json()) as { data: MetricSample[] };
            setSamples(body.data);
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to load metrics');
        }
    };

    const data = useMemo(
        () =>
            samples.map((sample) => ({
                at: new Date(sample.at).getTime(),
                load1: sample.load1,
                cpu: sample.cpu_percent,
                memory: memoryBytes ? Math.round((sample.memory_used_bytes / memoryBytes) * 1000) / 10 : null,
                disk: diskBytes ? Math.round((sample.disk_used_bytes / diskBytes) * 1000) / 10 : null,
            })),
        [samples, memoryBytes, diskBytes],
    );

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle>Metrics</CardTitle>
                <div className="flex gap-1" role="group" aria-label="Metrics range">
                    {RANGES.map((option) => (
                        <Button
                            key={option}
                            type="button"
                            size="sm"
                            variant={option === range ? 'secondary' : 'ghost'}
                            onClick={() => void load(option)}
                        >
                            {option}
                        </Button>
                    ))}
                </div>
            </CardHeader>
            <CardContent>
                {error && <p className="text-destructive mb-2 text-xs">{error}</p>}
                {data.length === 0 ? (
                    <p className="text-muted-foreground py-10 text-center text-sm">No heartbeat samples in this range yet.</p>
                ) : (
                    <div className="h-64 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={data} margin={{ top: 5, right: 10, bottom: 0, left: -10 }}>
                                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                <XAxis
                                    dataKey="at"
                                    type="number"
                                    domain={['dataMin', 'dataMax']}
                                    scale="time"
                                    tickFormatter={(value: number) => format(value, range === '24h' ? 'HH:mm' : 'HH:mm')}
                                    fontSize={11}
                                />
                                <YAxis yAxisId="percent" domain={[0, 100]} unit="%" fontSize={11} />
                                <YAxis yAxisId="load" orientation="right" fontSize={11} />
                                <Tooltip labelFormatter={(value) => format(Number(value), 'PPpp')} />
                                <Legend />
                                <Line yAxisId="percent" type="monotone" dataKey="cpu" name="CPU %" stroke="#3b82f6" dot={false} strokeWidth={2} />
                                <Line
                                    yAxisId="percent"
                                    type="monotone"
                                    dataKey="memory"
                                    name="Memory %"
                                    stroke="#10b981"
                                    dot={false}
                                    strokeWidth={2}
                                />
                                <Line yAxisId="percent" type="monotone" dataKey="disk" name="Disk %" stroke="#f59e0b" dot={false} strokeWidth={2} />
                                <Line yAxisId="load" type="monotone" dataKey="load1" name="Load (1m)" stroke="#a855f7" dot={false} strokeWidth={2} />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
