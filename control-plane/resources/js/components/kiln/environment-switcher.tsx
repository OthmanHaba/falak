import { currentProject, projectUrl } from '@/lib/kiln';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Check, Plus } from 'lucide-react';
import { MenuContent, MenuLabel, MenuLink, MenuRoot, MenuSeparator, MenuTrigger } from './menu';
import { SwitcherTrigger } from './switcher-trigger';
import { Tag } from './tag';

/** Environment switcher for the current project (production, staging, …); hidden without a current project. */
export function EnvironmentSwitcher() {
    const { kiln } = usePage<SharedData>().props;
    const { project, environment } = currentProject(kiln);
    if (!project || !environment) return null;

    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <SwitcherTrigger
                    aria-label={`Environment: ${environment.name}`}
                    label={environment.name}
                    hint={environment.is_production ? <span className="bg-success size-1.5 shrink-0 rounded-full" aria-hidden /> : undefined}
                />
            </MenuTrigger>
            <MenuContent align="start" className="w-56">
                <MenuLabel>Environments</MenuLabel>
                {project.environments.map((env) => (
                    <MenuLink key={env.id} href={projectUrl(project, env)}>
                        <span className="flex items-center gap-2">
                            {env.name}
                            {env.is_production && <Tag tone="success">prod</Tag>}
                            {env.id === environment.id && <Check className="text-primary ml-auto size-4" aria-label="Current" />}
                        </span>
                    </MenuLink>
                ))}
                <MenuSeparator />
                <MenuLink href={`/projects/${project.id}/settings#environments`} icon={<Plus />}>
                    New environment
                </MenuLink>
            </MenuContent>
        </MenuRoot>
    );
}
