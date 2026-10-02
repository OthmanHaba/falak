import { DomainPicker } from '@/components/domain-picker';
import { Callout, Field, Input, Section, Select, Skeleton } from '@/components/kiln';
import { type ComposeProjectProps, type ComposeServiceChoice } from '@/lib/registry';
import { useEffect } from 'react';
import { useCandidates, useInspection, type InspectedService } from './project-api';
import { AdjustmentsList, InspectionProblems, ProjectFiles, ServicesTable, VariablesList, type RowChoice } from './project-parts';

/**
 * The Git create flow's "Docker Compose app" (docs/plans/COMPOSE_APPS.md): the user names the compose files, Kiln
 * reads them from the branch and lists the services; for each the user decides where it runs.
 */
export function ComposeProjectForm({
    connectionId,
    repository,
    branch,
    name,
    serverIds,
    value,
    onChange,
    errors,
    onReadyChange,
}: ComposeProjectProps) {
    const candidates = useCandidates(connectionId, repository, branch);
    const files = value.files.filter((file) => file !== '');
    // useInspection compares bodies by their JSON, so a new object per render doesn't refetch.
    const body =
        connectionId && repository && branch && files.length > 0
            ? {
                  source_connection_id: connectionId,
                  repository,
                  branch,
                  compose_files: files,
                  compose_profiles: value.profiles,
                  compose_services: Object.fromEntries(Object.entries(value.services).filter(([, c]) => c.mode !== 'keep')),
                  compose_adjustments: { keep_binds: value.keepBinds },
                  public_services: value.public.filter((p) => p.service).map((p) => ({ service: p.service, port: Number(p.port) || 1 })),
              }
            : null;
    const { inspection, loading, error } = useInspection('/sites/compose/inspect', body);

    // Suggest the first compose file of the repository once.
    useEffect(() => {
        if (value.files.length === 0 && candidates.length > 0) onChange({ ...value, files: [candidates[0]] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [candidates]);

    const missingRequired = (inspection?.variables ?? []).filter((v) => v.required && v.default === null && !(value.variables[v.name] ?? '').trim());
    const ready = !!inspection && !inspection.no_api && inspection.errors.length === 0 && missingRequired.length === 0 && !loading;

    useEffect(() => onReadyChange(ready || inspection?.no_api === true), [ready, inspection?.no_api, onReadyChange]);

    const choiceOf = (service: string): { row: RowChoice; choice: ComposeServiceChoice } => {
        const choice = value.services[service] ?? { mode: 'keep' };
        if (choice.mode !== 'keep') return { row: choice.mode, choice };

        return { row: value.public.some((p) => p.service === service) ? 'public' : 'keep', choice };
    };

    const setChoice = (service: string, row: RowChoice, choice: ComposeServiceChoice) => {
        const target = inspection?.services.find((s) => s.name === service);
        const isPublic = value.public.some((p) => p.service === service);
        const publicList =
            row === 'public'
                ? isPublic
                    ? value.public
                    : [
                          ...value.public,
                          { service, port: String(target?.ports[0] ?? ''), domain: { type: 'generated' as const }, health_check_path: '' },
                      ]
                : value.public.filter((p) => p.service !== service);
        const services = { ...value.services };
        if (choice.mode === 'keep') delete services[service];
        else services[service] = choice;
        onChange({ ...value, services, public: publicList });
    };

    const updatePublic = (service: string, patch: Partial<ComposeProjectProps['value']['public'][number]>) =>
        onChange({ ...value, public: value.public.map((p) => (p.service === service ? { ...p, ...patch } : p)) });

    const renderPublic = (service: InspectedService) => {
        const index = value.public.findIndex((p) => p.service === service.name);
        const item = value.public[index];
        if (!item) return null;

        return (
            <div className="grid gap-2 sm:grid-cols-[7rem_minmax(0,1fr)]">
                <Field label="Port" error={errors[`public_services.${index}.port`]}>
                    {service.ports.length > 0 ? (
                        <Select
                            aria-label={`${service.name} port`}
                            value={item.port || undefined}
                            onValueChange={(port) => updatePublic(service.name, { port })}
                            options={service.ports.map((port) => ({ value: String(port), label: String(port) }))}
                        />
                    ) : (
                        <Input
                            mono
                            inputMode="numeric"
                            aria-label={`${service.name} port`}
                            value={item.port}
                            placeholder="8080"
                            onChange={(event) => updatePublic(service.name, { port: event.target.value.replace(/\D/g, '') })}
                        />
                    )}
                </Field>
                <Field label="Health check path" hint="Optional; Kiln checks this path after each deploy.">
                    <Input
                        mono
                        value={item.health_check_path}
                        placeholder="/"
                        onChange={(event) => updatePublic(service.name, { health_check_path: event.target.value })}
                    />
                </Field>
                <div className="sm:col-span-2">
                    <Field label="Domain" error={errors[`public_services.${index}.domain`]}>
                        <DomainPicker
                            label={`${service.name}-${name || 'app'}`}
                            serverIds={serverIds}
                            value={item.domain}
                            onChange={(domain) => updatePublic(service.name, { domain })}
                            ariaLabel={`${service.name} domain`}
                        />
                    </Field>
                </div>
            </div>
        );
    };

    return (
        <div className="grid gap-4" data-testid="compose-project-form">
            <ProjectFiles
                files={value.files}
                profiles={value.profiles}
                candidates={candidates}
                errors={errors}
                onChange={(nextFiles, profiles) => onChange({ ...value, files: nextFiles, profiles })}
            />

            {error && <Callout tone="danger">{error}</Callout>}
            {inspection?.no_api && (
                <Callout tone="info" title="Kiln can’t read this git server’s files">
                    Services are listed after the first deploy; add public services in Settings → Compose then.
                </Callout>
            )}
            {loading && !inspection && <Skeleton className="h-24" />}

            {inspection && !inspection.no_api && (
                <>
                    <InspectionProblems inspection={inspection} />
                    {inspection.services.length > 0 && (
                        <Section title="Services" description="Where each service runs. Public services get a domain through Kiln’s edge.">
                            <ServicesTable
                                services={inspection.services}
                                choiceOf={choiceOf}
                                onChoice={setChoice}
                                keepBinds={value.keepBinds}
                                onKeepBinds={(keepBinds) => onChange({ ...value, keepBinds })}
                                renderPublic={renderPublic}
                                errors={errors}
                            />
                        </Section>
                    )}
                    <Section title="Variables" description="Values for the stack’s ${VAR}s and env files; saved as the service’s variables.">
                        <VariablesList
                            variables={inspection.variables}
                            values={value.variables}
                            onChange={(variables) => onChange({ ...value, variables })}
                            note={errors.variables ? <p className="text-danger text-xs">{errors.variables}</p> : undefined}
                        />
                    </Section>
                    <Section title="Kiln adjustments" description="Applied when the stack runs; your repository is never changed.">
                        <AdjustmentsList inspection={inspection} />
                    </Section>
                </>
            )}
        </div>
    );
}
