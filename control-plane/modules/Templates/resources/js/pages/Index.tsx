import { AppShell, Button, Callout, Dialog, Field, PageHeader, Select, toast } from '@/components/falak';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import { useState } from 'react';
import { ConfigureForm } from '../components/configure-form';
import { TemplateGallery } from '../components/template-gallery';
import { type Category, type TemplateSummary } from '../types';

interface Props {
    templates: TemplateSummary[];
    categories: Category[];
    can: { deploy: boolean; manage: boolean };
}

function DeployDialog({
    template,
    canDeploy,
    onOpenChange,
}: {
    template: TemplateSummary;
    canDeploy: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { props } = usePage<SharedData>();
    const projects = props.falak?.projects ?? [];
    const current = props.falak?.current;
    const [projectId, setProjectId] = useState(current?.project_id ?? projects[0]?.id ?? '');
    const project = projects.find((item) => item.id === projectId) ?? null;
    const [environmentId, setEnvironmentId] = useState(
        current?.environment_id ?? project?.environments.find((env) => env.is_production)?.id ?? project?.environments[0]?.id ?? '',
    );
    const environment = project?.environments.find((env) => env.id === environmentId) ?? project?.environments[0] ?? null;

    return (
        <Dialog open onOpenChange={onOpenChange} title={`Deploy ${template.name}`} size="lg">
            {!canDeploy && (
                <Callout tone="info" className="mb-4">
                    You can browse templates, but deploying needs permission to create sites in a project.
                </Callout>
            )}
            <ConfigureForm
                template={template}
                target={canDeploy && project && environment ? { projectId: project.id, environmentSlug: environment.slug } : null}
                header={
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Project">
                            <Select
                                value={project?.id}
                                onValueChange={(value) => {
                                    setProjectId(value);
                                    const next = projects.find((item) => item.id === value);
                                    setEnvironmentId(next?.environments.find((env) => env.is_production)?.id ?? next?.environments[0]?.id ?? '');
                                }}
                                options={projects.map((item) => ({ value: item.id, label: item.name }))}
                                placeholder="Pick a project"
                            />
                        </Field>
                        <Field label="Environment">
                            <Select
                                value={environment?.id}
                                onValueChange={setEnvironmentId}
                                options={(project?.environments ?? []).map((env) => ({ value: env.id, label: env.name }))}
                                placeholder="Pick an environment"
                            />
                        </Field>
                    </div>
                }
                onDeployed={(result, warnings) => {
                    warnings.forEach((warning) => toast.warning(warning));
                    toast.success(`${result.name} created`, result.deployment_id ? 'First deploy started.' : undefined);
                    onOpenChange(false);
                    if (result.panel_url) router.visit(result.panel_url);
                }}
            />
        </Dialog>
    );
}

/** /templates — the full-page gallery (docs/COMPOSE_TEMPLATES.md §3). */
export default function Index({ templates, categories, can }: Props) {
    const [picked, setPicked] = useState<TemplateSummary | null>(null);

    return (
        <AppShell>
            <Head title="Templates" />
            <div className="grid gap-6">
                <PageHeader
                    title="Templates"
                    description="One-click apps backed by Docker Compose: pick one, fill in a few settings, deploy it into a project."
                    actions={
                        can.manage && (
                            <Button asChild variant="secondary">
                                <Link href="/settings/templates">
                                    <Settings2 aria-hidden /> Your templates
                                </Link>
                            </Button>
                        )
                    }
                />
                <TemplateGallery templates={templates} categories={categories} onPick={setPicked} />
            </div>
            {picked && <DeployDialog template={picked} canDeploy={can.deploy} onOpenChange={(open) => !open && setPicked(null)} />}
        </AppShell>
    );
}
