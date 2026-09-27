import {
    AppShell,
    Button,
    Dialog,
    EmptyState,
    Field,
    Input,
    Menu,
    PageHeader,
    RelativeTime,
    ServiceIcon,
    SetupChecklist,
    StatusBadge,
    Tag,
    Textarea,
    defaultSetupSteps,
    type SetupProgress,
} from '@/components/kiln';
import { Head, Link, useForm } from '@inertiajs/react';
import { FolderKanban, Plus, Settings } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { canvasUrl, type ProjectSummary } from '../types';

interface Props {
    projects: ProjectSummary[];
    setup: SetupProgress;
    can: { create: boolean };
}

const SETUP_DISMISSED = 'kiln:setup-dismissed';

function readDismissed(): boolean {
    try {
        return window.localStorage.getItem(SETUP_DISMISSED) === '1';
    } catch {
        return false;
    }
}

function productionOf(project: ProjectSummary) {
    return project.environments.find((env) => env.is_production) ?? project.environments[0] ?? null;
}

function ProjectCard({ project }: { project: ProjectSummary }) {
    const production = productionOf(project);
    const href = production ? canvasUrl(project.id, production.slug) : `/projects/${project.id}`;
    const extra = project.services_count - project.services.length;

    return (
        <div className="group border-border bg-surface-1 hover:border-border-strong relative grid gap-4 rounded-lg border p-4 transition-colors duration-150">
            <div className="flex items-start gap-3">
                <span className="border-border bg-surface-2 text-fg-muted flex size-9 shrink-0 items-center justify-center rounded-lg border">
                    {project.icon ? <ServiceIcon name={project.icon} size={16} /> : <FolderKanban className="size-4" aria-hidden />}
                </span>
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <Link href={href} className="text-fg truncate text-sm font-medium after:absolute after:inset-0 after:rounded-lg">
                        {project.name}
                    </Link>
                    <span className="text-fg-faint truncate text-xs">
                        {project.environments.length} {project.environments.length === 1 ? 'environment' : 'environments'} · {project.services_count}{' '}
                        {project.services_count === 1 ? 'service' : 'services'}
                    </span>
                </div>
                <div className="relative z-10">
                    <Menu
                        label={`${project.name} actions`}
                        actions={[
                            ...project.environments.map((env) => ({ label: `Open ${env.name}`, href: canvasUrl(project.id, env.slug) })),
                            { type: 'separator' as const },
                            { label: 'Project settings', icon: <Settings />, href: `/projects/${project.id}/settings` },
                        ]}
                    />
                </div>
            </div>
            {project.description && <p className="text-fg-muted line-clamp-2 text-sm">{project.description}</p>}
            <div className="flex min-h-7 items-center gap-1.5">
                {project.services.length === 0 ? (
                    <span className="text-fg-faint text-xs">No services yet</span>
                ) : (
                    project.services.map((service, index) => (
                        <span
                            key={`${service.name}-${index}`}
                            title={service.name}
                            className="border-border bg-surface-2 flex size-7 items-center justify-center rounded-md border"
                        >
                            <ServiceIcon name={service.icon} size={14} title={service.name} />
                        </span>
                    ))
                )}
                {extra > 0 && <span className="text-fg-faint text-xs">+{extra}</span>}
            </div>
            <div className="border-border text-fg-muted flex items-center justify-between gap-2 border-t pt-3 text-xs">
                {project.last_deployment ? (
                    <span className="truncate">
                        Deployed <RelativeTime value={project.last_deployment.finished_at ?? project.last_deployment.created_at} />
                    </span>
                ) : (
                    <span className="text-fg-faint">Never deployed</span>
                )}
                <span className="flex items-center gap-1.5">
                    {project.is_default && <Tag>default</Tag>}
                    <StatusBadge status={project.status} label={project.status === 'inactive' ? 'Idle' : undefined} />
                </span>
            </div>
        </div>
    );
}

function CreateProjectDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ name: '', description: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/projects', { onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) form.reset();
                onOpenChange(next);
            }}
            title="New project"
            description="A project groups the services you deploy together. It starts with a production environment."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="create-project" loading={form.processing}>
                        Create project
                    </Button>
                </>
            }
        >
            <form id="create-project" onSubmit={submit} className="grid gap-4">
                <Field label="Name" error={form.errors.name} required>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Storefront" autoFocus />
                </Field>
                <Field label="Description" hint="Optional." error={form.errors.description}>
                    <Textarea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} rows={2} />
                </Field>
            </form>
        </Dialog>
    );
}

/** §3 Projects grid: home of the app. */
export default function Index({ projects, setup, can }: Props) {
    const [creating, setCreating] = useState(false);
    const [dismissed, setDismissed] = useState(readDismissed);
    const first = projects.find((project) => project.is_default) ?? projects[0];
    const firstEnv = first ? productionOf(first) : null;
    const steps = defaultSetupSteps(setup, firstEnv && first ? { deploy: canvasUrl(first.id, firstEnv.slug) } : {}).map((step) =>
        step.id === 'project' && can.create ? { ...step, href: undefined, onAction: () => setCreating(true) } : step,
    );

    return (
        <AppShell>
            <Head title="Projects" />
            <div className="grid gap-6">
                <PageHeader
                    title="Projects"
                    description="Each project is a canvas of services — sites and databases — per environment."
                    actions={
                        can.create && (
                            <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                                New project
                            </Button>
                        )
                    }
                />
                {!dismissed && (
                    <SetupChecklist
                        steps={steps}
                        onDismiss={() => {
                            setDismissed(true);
                            try {
                                window.localStorage.setItem(SETUP_DISMISSED, '1');
                            } catch {
                                // Not persisted; hidden for this visit.
                            }
                        }}
                    />
                )}
                {projects.length === 0 ? (
                    <EmptyState
                        icon={<FolderKanban />}
                        title="No projects yet"
                        description="Projects group the sites and databases you deploy together, with production and staging environments."
                        action={
                            can.create && (
                                <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                                    New project
                                </Button>
                            )
                        }
                    />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <ProjectCard key={project.id} project={project} />
                        ))}
                    </div>
                )}
            </div>
            {can.create && <CreateProjectDialog open={creating} onOpenChange={setCreating} />}
        </AppShell>
    );
}
