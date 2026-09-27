import { type KilnEnvironment, type KilnProject, type KilnShared } from '@/types';

/** URL helpers for the Projects routes (§3). Kept in one place so the Projects module can adjust them. */
export function projectUrl(project: Pick<KilnProject, 'id'>, environment?: Pick<KilnEnvironment, 'slug'> | null): string {
    return environment ? `/projects/${project.id}/${environment.slug}` : `/projects/${project.id}`;
}

export function defaultEnvironment(project: KilnProject): KilnEnvironment | null {
    return project.environments.find((env) => env.is_production) ?? project.environments[0] ?? null;
}

export function currentProject(kiln: KilnShared | null | undefined): { project: KilnProject | null; environment: KilnEnvironment | null } {
    if (!kiln) return { project: null, environment: null };
    const project = kiln.projects.find((item) => item.id === kiln.current.project_id) ?? null;
    const environment = project?.environments.find((env) => env.id === kiln.current.environment_id) ?? (project ? defaultEnvironment(project) : null);

    return { project, environment };
}
