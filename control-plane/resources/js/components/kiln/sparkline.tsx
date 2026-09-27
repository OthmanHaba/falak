import { cn } from '@/lib/utils';

export interface SparklineProps {
    values: (number | null)[];
    /** Accessible description, e.g. "Occurrences per hour, last 24 hours". */
    label: string;
    width?: number;
    height?: number;
    /** `bar` for counts (occurrences), `line` for rates. */
    type?: 'bar' | 'line';
    tone?: 'accent' | 'danger' | 'muted';
    className?: string;
}

const TONES = { accent: 'var(--chart-1)', danger: 'var(--danger)', muted: 'var(--text-faint)' } as const;

/** Tiny inline trend (no axes): occurrences per hour in issue lists, request trends in tiles. */
export function Sparkline({ values, label, width = 96, height = 24, type = 'bar', tone = 'accent', className }: SparklineProps) {
    const numbers = values.map((value) => value ?? 0);
    const max = Math.max(...numbers, 0);
    const color = TONES[tone];
    const total = numbers.reduce((sum, value) => sum + value, 0);

    if (values.length === 0) return null;

    const step = width / values.length;

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            role="img"
            aria-label={`${label}: total ${total}, peak ${max}`}
            className={cn('shrink-0 overflow-visible', className)}
        >
            <title>{`${label}: total ${total}, peak ${max}`}</title>
            <line x1={0} x2={width} y1={height - 0.5} y2={height - 0.5} stroke="var(--border)" strokeWidth={1} />
            {type === 'bar'
                ? numbers.map((value, index) => {
                      if (value <= 0 || max === 0) return null;
                      const barHeight = Math.max(2, (value / max) * (height - 2));

                      return (
                          <rect
                              key={index}
                              x={index * step + 0.5}
                              y={height - barHeight}
                              width={Math.max(1, step - 1)}
                              height={barHeight}
                              rx={1}
                              fill={color}
                          />
                      );
                  })
                : max > 0 && (
                      <polyline
                          fill="none"
                          stroke={color}
                          strokeWidth={1.5}
                          strokeLinejoin="round"
                          strokeLinecap="round"
                          points={numbers.map((value, index) => `${index * step + step / 2},${height - 1 - (value / max) * (height - 3)}`).join(' ')}
                      />
                  )}
        </svg>
    );
}
