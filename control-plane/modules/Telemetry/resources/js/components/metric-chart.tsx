import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { format } from 'date-fns';
import { useMemo } from 'react';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { type MetricSeriesDto } from '../types';

const PALETTE = ['#3b82f6', '#10b981', '#f59e0b', '#a855f7', '#ef4444', '#06b6d4', '#64748b'];

export interface ChartSeries {
    /** Series name shown in the legend. */
    name: string;
    series: MetricSeriesDto[];
    /** Label used to split one query into several lines (e.g. mountpoint); defaults to one line. */
    splitBy?: string;
}

interface Props {
    title: string;
    lines: ChartSeries[];
    unit?: string;
    domain?: [number, number];
    formatValue?: (value: number) => string;
    error?: string;
    longRange?: boolean;
}

/** Merge Prometheus series into recharts rows keyed by timestamp. */
export function mergeSeries(lines: ChartSeries[]): { rows: Record<string, number | null>[]; keys: string[] } {
    const byTime = new Map<number, Record<string, number | null>>();
    const keys: string[] = [];

    lines.forEach((line) => {
        line.series.forEach((series) => {
            const key = line.splitBy ? `${line.name} ${series.labels[line.splitBy] ?? ''}`.trim() : line.name;
            if (!keys.includes(key)) keys.push(key);

            series.points.forEach(([ts, value]) => {
                const row = byTime.get(ts) ?? { at: ts * 1000 };
                row[key] = value === null ? null : Math.round(value * 100) / 100;
                byTime.set(ts, row);
            });
        });
    });

    return { rows: [...byTime.values()].sort((a, b) => (a.at ?? 0) - (b.at ?? 0)), keys };
}

export function MetricChart({ title, lines, unit, domain, formatValue, error, longRange }: Props) {
    const { rows, keys } = useMemo(() => mergeSeries(lines), [lines]);

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {error && <p className="text-destructive mb-2 text-xs">{error}</p>}
                {rows.length === 0 ? (
                    <p className="text-muted-foreground py-10 text-center text-sm">No data in this range.</p>
                ) : (
                    <div className="h-56 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={rows} margin={{ top: 5, right: 10, bottom: 0, left: 0 }}>
                                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                <XAxis
                                    dataKey="at"
                                    type="number"
                                    scale="time"
                                    domain={['dataMin', 'dataMax']}
                                    tickFormatter={(value: number) => format(value, longRange ? 'MM-dd HH:mm' : 'HH:mm')}
                                    fontSize={11}
                                />
                                <YAxis
                                    domain={domain ?? ['auto', 'auto']}
                                    unit={unit}
                                    fontSize={11}
                                    width={formatValue ? 70 : 50}
                                    tickFormatter={formatValue}
                                />
                                <Tooltip
                                    labelFormatter={(value) => format(Number(value), 'PPpp')}
                                    formatter={(value) => (typeof value === 'number' && formatValue ? formatValue(value) : `${value}${unit ?? ''}`)}
                                />
                                <Legend />
                                {keys.map((key, index) => (
                                    <Line
                                        key={key}
                                        type="monotone"
                                        dataKey={key}
                                        stroke={PALETTE[index % PALETTE.length]}
                                        dot={false}
                                        strokeWidth={2}
                                        connectNulls
                                        isAnimationActive={false}
                                    />
                                ))}
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
