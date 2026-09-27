import { Button, Dialog, Field, Input, Select } from '@/components/kiln';
import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { type ProjectEnvironment } from '../types';

const EMPTY = 'empty';

/**
 * §4 New environment: empty, or a duplicate of an existing one (service configs + variables are copied; servers
 * are not — each service is then pointed at servers). Creating opens the new environment's canvas.
 */
export function NewEnvironmentDialog({
    projectId,
    environments,
    open,
    onOpenChange,
    from = null,
}: {
    projectId: string;
    environments: ProjectEnvironment[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Preselect "duplicate from" this environment id. */
    from?: string | null;
}) {
    const form = useForm<{ name: string; from_environment_id: string }>({ name: '', from_environment_id: from ?? EMPTY });

    useEffect(() => {
        if (open) {
            form.setData({ name: '', from_environment_id: from ?? EMPTY });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, from]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ name: data.name, from_environment_id: data.from_environment_id === EMPTY ? null : data.from_environment_id }));
        form.post(`/projects/${projectId}/environments`, { onSuccess: () => onOpenChange(false) });
    };

    const source = environments.find((env) => env.id === form.data.from_environment_id);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="New environment"
            description="Environments are isolated copies of a project's services — staging, previews, a sandbox."
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="new-environment" loading={form.processing}>
                        Create environment
                    </Button>
                </>
            }
        >
            <form id="new-environment" onSubmit={submit} className="grid gap-4">
                <Field label="Name" error={form.errors.name} required>
                    <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="staging" autoFocus />
                </Field>
                <Field
                    label="Start from"
                    error={form.errors.from_environment_id}
                    hint={
                        source
                            ? `Copies the service configurations and variables of ${source.name}. Servers are not copied — pick servers per service afterwards. Databases are not duplicated.`
                            : 'An empty canvas.'
                    }
                >
                    <Select
                        value={form.data.from_environment_id}
                        onValueChange={(value) => form.setData('from_environment_id', value)}
                        options={[
                            { value: EMPTY, label: 'Empty environment' },
                            ...environments.map((env) => ({ value: env.id, label: `Duplicate ${env.name}` })),
                        ]}
                    />
                </Field>
                {'environment' in form.errors && <p className="text-danger text-xs">{(form.errors as Record<string, string>).environment}</p>}
            </form>
        </Dialog>
    );
}
