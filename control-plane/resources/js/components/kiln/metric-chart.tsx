import { cn } from '@/lib/utils';
import { useId, useMemo, useState, type ReactNode } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
    type TooltipContentProps,
} from 'recharts';
import { Skeleton } from './skeleton';

export interface MetricSeries {
    /** Key in each data point. */
    key: string;
    label: string;
}

export type MetricPoint = { t: number | string } & Record<string, number | string | null>;

export interface MetricChartProps {
    title: ReactNode;
    data: MetricPoint[];
    series: MetricSeries[];
    type?: 'line' | 'area' | 'bar';
    /** Formats y values in ticks/tooltip (e.g. `(v) => `${v.toFixed(1)}%``). */
    format?: (value: number) => string;
    /** Headline value shown next to the title (e.g. latest). */
    value?: ReactNode;
    height?: number;
    loading?: boolean;
    emptyText?: ReactNode;
    /** Extra header content (range selector …). */
    actions?: ReactNode;
    /** Formats x (time) ticks and tooltip headers; defaults to HH:mm (use a date format for multi-day ranges). */
    timeFormat?: (value: number | string) => string;
    className?: string;
}

// Fixed categorical order (never cycled past 5: fold extra series into small multiples).
const SERIES_COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

const tickStyle = { fill: 'var(--text-faint)', fontSize: 11, fontFamily: 'var(--font-sans)' };

function chartDate(value: number | string): Date {
    return typeof value === 'number' ? new Date(value < 1e12 ? value * 1000 : value) : new Date(value);
}

function formatTime(value: number | string): string {
    const date = chartDate(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function ChartTooltip({
    active,
    payload,
    label,
    format,
    series,
    timeFormat,
}: TooltipContentProps<number, string> & {
    format: (value: number) => string;
    series: MetricSeries[];
    timeFormat: (value: number | string) => string;
}) {
    if (!active || !payload?.length) return null;

    return (
        <div className="border-border bg-surface-1 shadow-panel min-w-36 rounded-lg border px-3 py-2 text-xs">
            <p className="text-fg-faint mb-1">{timeFormat(label as number | string)}</p>
            {payload.map((entry) => {
                const index = series.findIndex((item) => item.key === entry.dataKey);

                return (
                    <p key={String(entry.dataKey)} className="flex items-center gap-2">
                        <span className="size-2 rounded-full" style={{ background: SERIES_COLORS[index] ?? 'var(--text-faint)' }} aria-hidden />
                        <span className="text-fg-muted">{series[index]?.label ?? entry.name}</span>
                        <span className="text-fg tabular ml-auto font-medium">{typeof entry.value === 'number' ? format(entry.value) : '—'}</span>
                    </p>
                );
            })}
        </div>
    );
}

/** Themed time-series chart (recharts): one y-axis, thin marks, legend for ≥2 series, crosshair tooltip, table view. */
export function MetricChart({
    title,
    data,
    series,
    type = 'line',
    format = (value) => (Math.abs(value) >= 100 ? value.toFixed(0) : value.toFixed(1)),
    value,
    height = 180,
    loading = false,
    emptyText = 'No data for this range.',
    actions,
    timeFormat = formatTime,
    className,
}: MetricChartProps) {
    const gradientId = useId().replace(/:/g, '');
    const [showTable, setShowTable] = useState(false);
    const visible = useMemo(() => series.slice(0, SERIES_COLORS.length), [series]);

    const common = {
        data,
        margin: { top: 8, right: 8, bottom: 0, left: 0 },
    };

    const axes = (
        <>
            <CartesianGrid vertical={false} stroke="var(--border)" strokeDasharray="0" />
            <XAxis dataKey="t" tickFormatter={timeFormat} tick={tickStyle} axisLine={false} tickLine={false} minTickGap={32} />
            <YAxis tickFormatter={format} tick={tickStyle} axisLine={false} tickLine={false} width={44} />
            <Tooltip
                cursor={type === 'bar' ? { fill: 'var(--surface-2)' } : { stroke: 'var(--border-strong)', strokeWidth: 1 }}
                content={(props) => (
                    <ChartTooltip {...(props as TooltipContentProps<number, string>)} format={format} series={visible} timeFormat={timeFormat} />
                )}
            />
        </>
    );

    const chart =
        type === 'bar' ? (
            <BarChart {...common} barCategoryGap={2}>
                {axes}
                {visible.map((item, index) => (
                    <Bar
                        key={item.key}
                        dataKey={item.key}
                        name={item.label}
                        fill={SERIES_COLORS[index]}
                        radius={[4, 4, 0, 0]}
                        isAnimationActive={false}
                    />
                ))}
            </BarChart>
        ) : type === 'area' ? (
            <AreaChart {...common}>
                <defs>
                    {visible.map((item, index) => (
                        <linearGradient key={item.key} id={`${gradientId}-${index}`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={SERIES_COLORS[index]} stopOpacity={0.24} />
                            <stop offset="100%" stopColor={SERIES_COLORS[index]} stopOpacity={0} />
                        </linearGradient>
                    ))}
                </defs>
                {axes}
                {visible.map((item, index) => (
                    <Area
                        key={item.key}
                        type="monotone"
                        dataKey={item.key}
                        name={item.label}
                        stroke={SERIES_COLORS[index]}
                        strokeWidth={2}
                        fill={`url(#${gradientId}-${index})`}
                        dot={false}
                        activeDot={{ r: 4, stroke: 'var(--surface-1)', strokeWidth: 2 }}
                        isAnimationActive={false}
                    />
                ))}
            </AreaChart>
        ) : (
            <LineChart {...common}>
                {axes}
                {visible.map((item, index) => (
                    <Line
                        key={item.key}
                        type="monotone"
                        dataKey={item.key}
                        name={item.label}
                        stroke={SERIES_COLORS[index]}
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 4, stroke: 'var(--surface-1)', strokeWidth: 2 }}
                        isAnimationActive={false}
                    />
                ))}
            </LineChart>
        );

    return (
        <figure className={cn('border-border bg-surface-1 grid gap-2 rounded-lg border p-4', className)}>
            <figcaption className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-fg-muted text-xs font-medium">{title}</span>
                {value !== undefined && <span className="text-fg tabular text-base font-semibold">{value}</span>}
                {visible.length > 1 && (
                    <ul className="text-fg-muted flex flex-wrap items-center gap-3 text-xs" aria-label="Legend">
                        {visible.map((item, index) => (
                            <li key={item.key} className="flex items-center gap-1.5">
                                <span className="h-0.5 w-3 rounded-full" style={{ background: SERIES_COLORS[index] }} aria-hidden />
                                {item.label}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="ml-auto flex items-center gap-2">
                    {actions}
                    {data.length > 0 && (
                        <button
                            type="button"
                            className="text-2xs text-fg-faint hover:text-fg rounded-sm"
                            onClick={() => setShowTable((current) => !current)}
                            aria-pressed={showTable}
                        >
                            {showTable ? 'Chart' : 'Table'}
                        </button>
                    )}
                </div>
            </figcaption>
            {loading ? (
                <Skeleton style={{ height }} />
            ) : data.length === 0 ? (
                <div className="text-fg-faint flex items-center justify-center text-sm" style={{ height }}>
                    {emptyText}
                </div>
            ) : showTable ? (
                <div className="overflow-auto" style={{ maxHeight: height }}>
                    <table className="w-full text-xs">
                        <thead className="bg-surface-1 text-fg-faint sticky top-0">
                            <tr>
                                <th className="py-1 text-left font-medium">Time</th>
                                {visible.map((item) => (
                                    <th key={item.key} className="py-1 text-right font-medium">
                                        {item.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="text-fg tabular">
                            {data.map((point, index) => (
                                <tr key={index} className="border-border border-t">
                                    <td className="text-fg-muted py-1">{timeFormat(point.t)}</td>
                                    {visible.map((item) => {
                                        const cell = point[item.key];

                                        return (
                                            <td key={item.key} className="py-1 text-right">
                                                {typeof cell === 'number' ? format(cell) : '—'}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div style={{ height }} role="img" aria-label={typeof title === 'string' ? `${title} chart` : 'Metric chart'}>
                    <ResponsiveContainer width="100%" height="100%">
                        {chart}
                    </ResponsiveContainer>
                </div>
            )}
        </figure>
    );
}
