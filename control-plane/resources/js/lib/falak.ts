import { type FalakEnvironment, type FalakProject, type FalakShared } from '@/types';

/** URL helpers for the Projects routes (§3). Kept in one place so the Projects module can adjust them. */
export function projectUrl(project: Pick<FalakProject, 'id'>, environment?: Pick<FalakEnvironment, 'slug'> | null): string {
    return environment ? `/projects/${project.id}/${environment.slug}` : `/projects/${project.id}`;
}

export function defaultEnvironment(project: FalakProject): FalakEnvironment | null {
    return project.environments.find((env) => env.is_production) ?? project.environments[0] ?? null;
}

export function currentProject(falak: FalakShared | null | undefined): { project: FalakProject | null; environment: FalakEnvironment | null } {
    if (!falak) return { project: null, environment: null };
    const project = falak.projects.find((item) => item.id === falak.current.project_id) ?? null;
    const environment =
        project?.environments.find((env) => env.id === falak.current.environment_id) ?? (project ? defaultEnvironment(project) : null);

    return { project, environment };
}
