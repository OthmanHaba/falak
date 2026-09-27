import { currentProject, defaultEnvironment, projectUrl } from '@/lib/kiln';
import { projectsUi } from '@/lib/pages';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Check, FolderKanban, LayoutGrid } from 'lucide-react';
import { MenuContent, MenuLabel, MenuLink, MenuRoot, MenuSeparator, MenuTrigger } from './menu';
import { ServiceIcon, hasServiceIcon } from './service-icon';
import { SwitcherTrigger } from './switcher-trigger';

function ProjectIcon({ icon }: { icon: string | null }) {
    return icon && hasServiceIcon(icon) ? <ServiceIcon name={icon} size={14} /> : <FolderKanban className="text-fg-muted size-3.5" aria-hidden />;
}

/** Project switcher from the `kiln` shared prop (§9); renders nothing until the Projects module provides it. */
export function ProjectSwitcher() {
    const { kiln } = usePage<SharedData>().props;
    if (!kiln || kiln.projects.length === 0) return null;

    const { project } = currentProject(kiln);
    const canvasReady = projectsUi.canvas();

    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <SwitcherTrigger
                    aria-label={`Project: ${project?.name ?? 'none selected'}`}
                    icon={project ? <ProjectIcon icon={project.icon} /> : <FolderKanban className="text-fg-muted size-3.5" aria-hidden />}
                    label={project?.name ?? 'Select project'}
                />
            </MenuTrigger>
            <MenuContent align="start" className="w-64">
                <MenuLabel>Projects</MenuLabel>
                {kiln.projects.map((item) => (
                    <MenuLink
                        key={item.id}
                        href={projectUrl(item, defaultEnvironment(item))}
                        icon={<ProjectIcon icon={item.icon} />}
                        disabled={!canvasReady}
                    >
                        <span className="flex items-center gap-2">
                            {item.name}
                            {item.id === project?.id && <Check className="text-primary ml-auto size-4" aria-label="Current" />}
                        </span>
                    </MenuLink>
                ))}
                <MenuSeparator />
                {!canvasReady && <MenuLabel className="normal-case">Project canvas coming soon</MenuLabel>}
                <MenuLink href="/projects" icon={<LayoutGrid />} disabled={!projectsUi.index()}>
                    All projects
                </MenuLink>
            </MenuContent>
        </MenuRoot>
    );
}
