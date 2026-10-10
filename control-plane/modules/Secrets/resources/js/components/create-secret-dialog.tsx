import { Button, Callout, Dialog, Field, Input, SecretInput, Segmented, Select, Switch, Textarea } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { Link } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { ON_CHANGE_LABELS, SCOPE_LABELS, type OnChange, type ProviderOption, type ScopeOption } from '../types';

interface Form {
    name: string;
    scope: string;
    kind: 'managed' | 'linked';
    value: string;
    reference: string;
    provider_id: string;
    watch_minutes: string;
    on_change: OnChange;
    sensitive: boolean;
    available_to_previews: boolean;
    description: string;
    rotation_days: string;
}

const scopeKey = (option: ScopeOption) => `${option.scope}:${option.id}`;

const blank = (scopes: ScopeOption[], providers: ProviderOption[]): Form => ({
    name: '',
    scope: scopes[0] ? scopeKey(scopes[0]) : '',
    kind: 'managed',
    value: '',
    reference: '',
    provider_id: providers[0]?.id ?? '',
    watch_minutes: '',
    on_change: 'none',
    sensitive: true,
    available_to_previews: false,
    description: '',
    rotation_days: '',
});

export function CreateSecretDialog({
    open,
    scopes,
    providers,
    onClose,
    onCreated,
}: {
    open: boolean;
    scopes: ScopeOption[];
    providers: ProviderOption[];
    onClose: () => void;
    onCreated: () => void;
}) {
    const [form, setForm] = useState<Form>(() => blank(scopes, providers));
    const provider = providers.find((option) => option.id === form.provider_id);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((current) => ({ ...current, [key]: value }));

    useEffect(() => {
        if (open) {
            setForm(blank(scopes, providers));
            setErrors({});
        }
    }, [open, scopes, providers]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const scope = scopes.find((option) => scopeKey(option) === form.scope);
        setBusy(true);
        try {
            await requestJson('/secrets', 'POST', {
                name: form.name,
                scope: scope?.scope,
                scope_id: scope?.id,
                kind: form.kind,
                value: form.kind === 'managed' ? form.value : null,
                reference: form.kind === 'linked' ? form.reference : null,
                ...(form.kind === 'linked'
                    ? {
                          provider_id: form.provider_id || null,
                          watch_minutes: form.watch_minutes ? Number(form.watch_minutes) : null,
                          on_change: form.on_change,
                      }
                    : {}),
                sensitive: form.sensitive,
                available_to_previews: form.available_to_previews,
                description: form.description || null,
                rotation_days: form.rotation_days ? Number(form.rotation_days) : null,
            });
            onCreated();
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { name: errorMessage(error) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="New secret"
            description={
                <>
                    Reference it from a service's variables as <code className="font-mono">{'${{ secrets.NAME }}'}</code>. The nearest scope wins:
                    service, environment, project, then organization.
                </>
            }
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="create-secret" loading={busy}>
                        Create secret
                    </Button>
                </>
            }
        >
            <form id="create-secret" onSubmit={submit} className="grid gap-4">
                <Field label="Name" hint="Capital letters, digits and underscores, like an environment variable." error={errors.name}>
                    <Input
                        mono
                        value={form.name}
                        onChange={(event) => set('name', event.target.value.toUpperCase())}
                        placeholder="STRIPE_SECRET"
                        autoFocus
                    />
                </Field>
                {scopes.length > 1 && (
                    <Field label="Scope" error={errors.scope_id ?? errors.scope}>
                        <Select
                            value={form.scope}
                            onValueChange={(value) => set('scope', value)}
                            options={scopes.map((option) => ({
                                value: scopeKey(option),
                                label: option.scope === 'project' ? 'Whole project' : option.label,
                                description: SCOPE_LABELS[option.scope],
                            }))}
                        />
                    </Field>
                )}
                <Field label="Source">
                    <Segmented
                        label="Secret source"
                        value={form.kind}
                        onValueChange={(value) => set('kind', value)}
                        options={[
                            { value: 'managed', label: 'Stored in Falak' },
                            { value: 'linked', label: 'Linked to a provider' },
                        ]}
                    />
                </Field>
                {form.kind === 'managed' ? (
                    <Field label="Value" error={errors.value}>
                        <SecretInput value={form.value} onChange={(value) => set('value', value)} />
                    </Field>
                ) : providers.length === 0 ? (
                    <Callout tone="info">
                        Add a secret provider first (Vault, AWS, 1Password, Doppler, Infisical or an HTTPS webhook):{' '}
                        <Link href="/settings/secrets/providers" className="text-primary hover:underline">
                            Secret providers
                        </Link>
                        .
                    </Callout>
                ) : (
                    <>
                        <Field label="Provider" error={errors.provider_id}>
                            <Select
                                value={form.provider_id}
                                onValueChange={(value) => set('provider_id', value)}
                                options={providers.map((option) => ({ value: option.id, label: option.name, description: `${option.scheme}://` }))}
                            />
                        </Field>
                        <Field label="Reference" hint="Resolved on the control plane at deploy time, cached encrypted." error={errors.reference}>
                            <Input
                                mono
                                value={form.reference}
                                onChange={(event) => set('reference', event.target.value)}
                                placeholder={provider?.example ?? 'vault://kv/data/app#KEY'}
                            />
                        </Field>
                        <div className="grid items-start gap-4 sm:grid-cols-2">
                            <Field label="Watch every" hint="Minutes. Empty: don't watch." error={errors.watch_minutes}>
                                <Input
                                    type="number"
                                    min={1}
                                    max={1440}
                                    value={form.watch_minutes}
                                    onChange={(event) => set('watch_minutes', event.target.value)}
                                    placeholder="Off"
                                />
                            </Field>
                            <Field label="When it changes" error={errors.on_change}>
                                <Select
                                    value={form.on_change}
                                    disabled={!form.watch_minutes}
                                    onValueChange={(value) => set('on_change', value as OnChange)}
                                    options={(Object.keys(ON_CHANGE_LABELS) as OnChange[]).map((value) => ({
                                        value,
                                        label: ON_CHANGE_LABELS[value],
                                    }))}
                                />
                            </Field>
                        </div>
                    </>
                )}
                <Field label="Sensitive" inline hint="Write-only: once saved, no one can read the value back (only replace it).">
                    <Switch checked={form.sensitive} onCheckedChange={(checked) => set('sensitive', checked)} />
                </Field>
                <Field label="Available to preview environments" inline>
                    <Switch checked={form.available_to_previews} onCheckedChange={(checked) => set('available_to_previews', checked)} />
                </Field>
                <Field label="Rotate every" hint="Days. Flags the secret when its value is older." error={errors.rotation_days}>
                    <Input
                        type="number"
                        min={1}
                        max={3650}
                        value={form.rotation_days}
                        onChange={(event) => set('rotation_days', event.target.value)}
                        placeholder="Never"
                    />
                </Field>
                <Field label="Description" error={errors.description}>
                    <Textarea rows={2} value={form.description} onChange={(event) => set('description', event.target.value)} />
                </Field>
            </form>
        </Dialog>
    );
}
