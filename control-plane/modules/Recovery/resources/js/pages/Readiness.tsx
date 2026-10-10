import { AppShell, EmptyState, PageHeader, Section, Tag } from '@/components/falak';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { type ProjectReadiness } from '../types';

interface Props {
    projects: ProjectReadiness[];
}

function scoreTone(score: number): string {
    if (score >= 90) return 'text-success';
    if (score >= 50) return 'text-warning';

    return 'text-danger';
}

/** DR readiness per project: backups (encrypted, scheduled, drilled), volume backups and PITR in production. */
export default function Readiness({ projects }: Props) {
    return (
        <AppShell>
            <Head title="Disaster recovery readiness" />
            <div className="grid gap-6">
                <PageHeader
                    title="Disaster recovery readiness"
                    description="Is every database backed up on a schedule (always encrypted) and proven by a restore drill, every volume backed up, and point-in-time recovery on in production?"
                />
                {projects.length === 0 && (
                    <EmptyState icon={<ShieldCheck />} title="No projects yet" description="Readiness is scored per project." />
                )}
                {projects.map((project) => (
                    <Section
                        key={project.project_id}
                        title={project.name}
                        description={project.checks === 0 ? 'Nothing to protect yet.' : `${project.passed} of ${project.checks} checks pass.`}
                        aside={<span className={cn('text-2xl font-semibold tabular-nums', scoreTone(project.score))}>{project.score}%</span>}
                    >
                        {project.gaps.length === 0 ? (
                            <p className="text-success text-sm">Ready.</p>
                        ) : (
                            <ul className="divide-border grid divide-y text-sm">
                                {project.gaps.map((gap, index) => (
                                    <li key={`${gap.id}-${index}`} className="flex flex-wrap items-center gap-2 py-2">
                                        <Tag>{gap.kind}</Tag>
                                        <span className="text-fg font-medium">{gap.name}</span>
                                        <span className="text-fg-faint">{gap.environment}</span>
                                        <span className="text-fg-muted">{gap.problem}</span>
                                        {gap.url && (
                                            <Link href={gap.url} className="text-primary ml-auto underline-offset-2 hover:underline">
                                                {gap.fix}
                                            </Link>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                ))}
            </div>
        </AppShell>
    );
}
