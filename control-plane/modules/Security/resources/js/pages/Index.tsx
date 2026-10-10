import { DataTable } from '@/components/falak/data-table';
import { RelativeTime } from '@/components/falak/relative-time';
import InfrastructureLayout from '@/layouts/infrastructure-layout';
import { router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { ReadyBadge, ScoreRing, type AuditSummary } from '../components/security-ui';

interface ServerRow {
    id: string;
    name: string;
    type_label: string;
    status: string;
    ipv4: string | null;
    audit: AuditSummary | null;
}

interface Props {
    servers: ServerRow[];
    weights: { fail: Record<string, number>; warn: Record<string, number> };
}

export default function SecurityIndex({ servers, weights }: Props) {
    return (
        <InfrastructureLayout
            section="security"
            description={`Each server's security baseline, audited daily. A failing check costs ${weights.fail.critical} (critical), ${weights.fail.high} (high), ${weights.fail.medium} (medium) or ${weights.fail.low} (low) points; warnings about half. Servers are production ready with no high or critical failures.`}
        >
            <DataTable<ServerRow>
                label="Servers' security scores"
                rows={servers}
                rowKey={(row) => row.id}
                onRowClick={(row) => router.visit(`/servers/${row.id}/security`)}
                defaultSort={{ column: 'score', direction: 'asc' }}
                empty={{ icon: <ShieldCheck />, title: 'No servers', description: 'Servers are audited once they are active.' }}
                columns={[
                    {
                        id: 'name',
                        header: 'Server',
                        sortValue: (row) => row.name,
                        cell: (row) => (
                            <span className="grid gap-0.5">
                                <span className="text-fg font-medium">{row.name}</span>
                                <span className="text-fg-muted text-xs">
                                    {row.type_label}
                                    {row.ipv4 && <span className="font-mono"> · {row.ipv4}</span>}
                                </span>
                            </span>
                        ),
                    },
                    {
                        id: 'score',
                        header: 'Score',
                        sortValue: (row) => row.audit?.score ?? null,
                        cell: (row) => <ScoreRing score={row.audit?.score ?? null} size={36} />,
                    },
                    {
                        id: 'badge',
                        header: 'Status',
                        cell: (row) =>
                            row.audit ? (
                                <ReadyBadge ready={row.audit.production_ready} />
                            ) : (
                                <span className="text-fg-faint text-xs">Not audited</span>
                            ),
                    },
                    {
                        id: 'findings',
                        header: 'Needs attention',
                        hideOnMobile: true,
                        cell: (row) =>
                            row.audit ? (
                                <span className="text-fg-muted tabular text-xs">
                                    {row.audit.counts.fail ?? 0} failing · {row.audit.counts.warn ?? 0} warnings
                                </span>
                            ) : null,
                    },
                    {
                        id: 'ran_at',
                        header: 'Last audit',
                        hideOnMobile: true,
                        sortValue: (row) => row.audit?.ran_at ?? null,
                        cell: (row) => <RelativeTime value={row.audit?.ran_at} className="text-fg-muted text-xs" fallback="–" />,
                    },
                ]}
            />
        </InfrastructureLayout>
    );
}
