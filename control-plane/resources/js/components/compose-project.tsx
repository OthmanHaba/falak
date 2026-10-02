import { domainPayload } from '@/components/domain-picker';
import { Skeleton } from '@/components/kiln';
import { registeredComposeProject, type ComposeProjectProps, type ComposeProjectValue } from '@/lib/registry';
import { Suspense } from 'react';

export type { ComposeProjectProps, ComposeProjectValue, ComposeServiceChoice } from '@/lib/registry';

export const emptyComposeProject: ComposeProjectValue = { files: [], profiles: [], services: {}, public: [], variables: {}, keepBinds: [] };

/** The registered compose app form (Sites); nothing when no module registers one. */
export function ComposeProject(props: ComposeProjectProps) {
    const Registered = registeredComposeProject();
    if (!Registered) return null;

    return (
        <Suspense fallback={<Skeleton className="h-24" />}>
            <Registered {...props} />
        </Suspense>
    );
}

/** The create request fields of a compose app (runtime compose, repository source). */
export function composeProjectPayload(value: ComposeProjectValue): Record<string, unknown> {
    const services = Object.fromEntries(Object.entries(value.services).filter(([, choice]) => choice.mode !== 'keep'));

    return {
        runtime: 'compose',
        compose_source: 'repo',
        compose_files: value.files.filter((file) => file.trim() !== ''),
        compose_profiles: value.profiles,
        compose_services: services,
        compose_adjustments: { keep_binds: value.keepBinds },
        public_services: value.public
            .filter((item) => item.service && !(value.services[item.service]?.mode && value.services[item.service].mode !== 'keep'))
            .map((item) => ({
                service: item.service,
                port: Number(item.port),
                domain: domainPayload(item.domain) ?? null,
                ...(item.health_check_path.trim() ? { health_check_path: item.health_check_path.trim() } : {}),
            })),
        variables: Object.fromEntries(Object.entries(value.variables).filter(([, v]) => v !== '')),
    };
}
