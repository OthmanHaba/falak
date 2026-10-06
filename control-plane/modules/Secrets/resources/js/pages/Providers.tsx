import {
    Button,
    Callout,
    ConfirmDestructive,
    DataTable,
    Dialog,
    EmptyState,
    Field,
    Input,
    RelativeTime,
    SecretInput,
    Select,
    StatusBadge,
    Switch,
    Textarea,
    toast,
} from '@/components/falak';
import { IntegrationTile } from '@/components/falak/integration-icon';
import SettingsLayout from '@/layouts/settings/layout';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Pencil, PlugZap, Plus, Trash2, Waypoints } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { fieldApplies, providerIcon, type ProviderField, type ProviderRow, type ProviderTypeOption, type ProviderTypeValue } from '../types';

interface Props {
    providers: ProviderRow[];
    types: ProviderTypeOption[];
    /** The instance lets providers reach private networks (self-hosted Vault, Infisical, …). */
    allow_private_network: boolean;
    can: { manage: boolean };
}

interface Form {
    name: string;
    type: ProviderTypeValue;
    config: Record<string, string>;
    allow_private_network: boolean;
    cache_ttl_seconds: string;
}

const defaults = (type: ProviderTypeOption): Record<string, string> =>
    Object.fromEntries(type.fields.filter((field) => field.default).map((field) => [field.name, field.default as string]));

const STATUS: Record<ProviderRow['status'], { status: string; label: string }> = {
    ok: { status: 'active', label: 'Connected' },
    error: { status: 'error', label: 'Failing' },
    untested: { status: 'queued', label: 'Not tested' },
};

/**
 * /settings/secrets/providers: the organization's external secret providers. Linked secrets store a reference
 * (`vault://…`, `aws-sm://…`) resolved through one of them at deploy time. Credentials go in, never out.
 */
export default function Providers({ providers, types, allow_private_network, can }: Props) {
    const [editing, setEditing] = useState<ProviderRow | 'new' | null>(null);
    const [form, setForm] = useState<Form | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<ProviderRow | null>(null);
    const current = editing !== 'new' ? editing : null;
    const type = types.find((option) => option.value === form?.type) ?? types[0];
    const refresh = () => router.reload({ only: ['providers'] });

    const open = (provider: ProviderRow | 'new') => {
        const base = provider === 'new' ? types[0] : (types.find((option) => option.value === provider.type) ?? types[0]);
        setForm(
            provider === 'new'
                ? { name: '', type: base.value, config: defaults(base), allow_private_network: false, cache_ttl_seconds: '300' }
                : {
                      name: provider.name,
                      type: provider.type,
                      config: { ...defaults(base), ...provider.settings },
                      allow_private_network: provider.allow_private_network,
                      cache_ttl_seconds: String(provider.cache_ttl_seconds),
                  },
        );
        setErrors({});
        setEditing(provider);
    };

    const pickType = (value: ProviderTypeValue) => {
        const next = types.find((option) => option.value === value);
        if (!form || !next) return;
        setForm({ ...form, type: value, config: defaults(next), name: form.name || next.label });
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!form) return;
        setBusy('save');
        try {
            const body = {
                name: form.name,
                config: form.config,
                allow_private_network: form.allow_private_network,
                cache_ttl_seconds: Number(form.cache_ttl_seconds || 0),
            };
            if (current) await requestJson(`/secrets/providers/${current.id}`, 'PATCH', body);
            else await requestJson('/secrets/providers', 'POST', { ...body, type: form.type });
            toast.success(current ? 'Provider updated' : 'Provider added', 'Use Test to check the connection.');
            setEditing(null);
            refresh();
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { name: errorMessage(error) });
        } finally {
            setBusy(null);
        }
    };

    const test = async (provider: ProviderRow) => {
        setBusy(`test-${provider.id}`);
        try {
            await requestJson(`/secrets/providers/${provider.id}/test`, 'POST');
            toast.success(`${provider.name} is reachable`, 'The credentials were accepted.');
        } catch (error) {
            toast.error(error instanceof HttpError ? (error.errors.provider ?? errorMessage(error)) : errorMessage(error));
        } finally {
            setBusy(null);
            refresh();
        }
    };

    const remove = async () => {
        if (!deleting) return;
        setBusy('delete');
        try {
            await requestJson(`/secrets/providers/${deleting.id}`, 'DELETE');
            toast.success('Provider deleted');
            setDeleting(null);
            refresh();
        } catch (error) {
            toast.error(error instanceof HttpError ? (error.errors.provider ?? errorMessage(error)) : errorMessage(error));
        } finally {
            setBusy(null);
        }
    };

    const setConfig = (name: string, value: string) => form && setForm({ ...form, config: { ...form.config, [name]: value } });

    return (
        <SettingsLayout
            title="Secret providers"
            description="Link secrets to Vault, AWS, 1Password, Doppler, Infisical or your own HTTPS endpoint. Values are fetched on the control plane at deploy time and cached encrypted."
            actions={
                can.manage &&
                providers.length > 0 && (
                    <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                        Add provider
                    </Button>
                )
            }
            wide
        >
            <Link href="/settings/secrets" className="text-fg-muted hover:text-fg -mt-4 flex w-fit items-center gap-1 text-xs">
                <ArrowLeft className="size-3.5" aria-hidden /> Secrets
            </Link>

            {providers.length === 0 ? (
                <EmptyState
                    icon={<Waypoints />}
                    title="No secret providers yet"
                    description="Add a provider, then create a linked secret with a reference such as vault://kv/data/app#DB_PASS. Deployments resolve it; nothing is copied into Falak except an encrypted cache."
                    action={
                        can.manage && (
                            <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                                Add a provider
                            </Button>
                        )
                    }
                />
            ) : (
                <DataTable
                    label="Secret providers"
                    rows={providers}
                    rowKey={(provider) => provider.id}
                    defaultSort={{ column: 'name', direction: 'asc' }}
                    columns={[
                        {
                            id: 'name',
                            header: 'Provider',
                            sortValue: (provider) => provider.name,
                            cell: (provider) => (
                                <span className="flex min-w-0 items-center gap-2.5 py-1.5">
                                    <IntegrationTile name={providerIcon(provider.type)} size="sm" />
                                    <span className="grid min-w-0">
                                        <span className="truncate font-medium">{provider.name}</span>
                                        <span className="text-fg-faint truncate text-xs">
                                            {provider.type_label} · <span className="font-mono">{provider.scheme}://</span>
                                        </span>
                                    </span>
                                </span>
                            ),
                        },
                        {
                            id: 'secrets',
                            header: 'Linked secrets',
                            align: 'right',
                            hideOnMobile: true,
                            sortValue: (provider) => provider.secrets_count,
                            cell: (provider) => <span className="tabular text-sm">{provider.secrets_count}</span>,
                        },
                        {
                            id: 'cache',
                            header: 'Cache',
                            hideOnMobile: true,
                            cell: (provider) => (
                                <span className="text-fg-muted text-xs">
                                    {provider.cache_ttl_seconds > 0 ? `${provider.cache_ttl_seconds}s` : 'Always ask'}
                                </span>
                            ),
                        },
                        {
                            id: 'status',
                            header: 'Status',
                            cell: (provider) => (
                                <span className="grid justify-items-start gap-0.5 py-1.5">
                                    <StatusBadge status={STATUS[provider.status].status} label={STATUS[provider.status].label} />
                                    {provider.status === 'error' && provider.last_error ? (
                                        <span className="text-danger line-clamp-2 max-w-xs text-xs" title={provider.last_error}>
                                            {provider.last_error}
                                        </span>
                                    ) : (
                                        provider.last_checked_at && (
                                            <RelativeTime value={provider.last_checked_at} className="text-fg-faint text-xs" />
                                        )
                                    )}
                                </span>
                            ),
                        },
                        ...(can.manage
                            ? [
                                  {
                                      id: 'test',
                                      hideOnMobile: true,
                                      header: <span className="sr-only">Test</span>,
                                      align: 'right' as const,
                                      cell: (provider: ProviderRow) => (
                                          <Button
                                              size="sm"
                                              variant="ghost"
                                              icon={<PlugZap />}
                                              loading={busy === `test-${provider.id}`}
                                              onClick={() => void test(provider)}
                                              aria-label={`Test ${provider.name}`}
                                          >
                                              <span className="hidden sm:inline">Test</span>
                                          </Button>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                    rowActions={
                        can.manage
                            ? (provider) => [
                                  { label: 'Test connection', icon: <PlugZap />, onSelect: () => void test(provider) },
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => open(provider) },
                                  { type: 'separator' },
                                  { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(provider) },
                              ]
                            : undefined
                    }
                />
            )}

            <Dialog
                open={editing !== null && form !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                title={current ? `Edit ${current.name}` : 'Add a secret provider'}
                description={
                    type && (
                        <>
                            References look like <code className="font-mono">{type.example}</code>.
                        </>
                    )
                }
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="provider-form" loading={busy === 'save'}>
                            {current ? 'Save changes' : 'Add provider'}
                        </Button>
                    </>
                }
            >
                {form && type && (
                    <form id="provider-form" onSubmit={submit} className="grid gap-4">
                        {!current && (
                            <fieldset className="grid gap-1.5">
                                <legend className="text-fg mb-1.5 text-xs font-medium">Provider</legend>
                                <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3" role="radiogroup" aria-label="Provider type">
                                    {types.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={form.type === option.value}
                                            onClick={() => pickType(option.value)}
                                            className={cn(
                                                'flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-xs transition-colors duration-150',
                                                form.type === option.value
                                                    ? 'border-primary bg-primary-soft text-fg'
                                                    : 'border-border bg-surface-2 text-fg-muted hover:border-border-strong hover:text-fg',
                                            )}
                                        >
                                            <IntegrationTile name={providerIcon(option.value)} size="sm" className="bg-surface-1" />
                                            <span className="truncate">{option.label}</span>
                                        </button>
                                    ))}
                                </div>
                                {errors.type && <p className="text-danger text-xs">{errors.type}</p>}
                            </fieldset>
                        )}

                        {form.type === 'onepassword' && (
                            <Callout tone="info">
                                1Password service account tokens only work through 1Password's SDKs and CLI. Run a 1Password Connect server and enter
                                its URL and token here.
                            </Callout>
                        )}

                        <Field label="Name" error={errors.name} required>
                            <Input
                                value={form.name}
                                onChange={(event) => setForm({ ...form, name: event.target.value })}
                                placeholder="Production Vault"
                            />
                        </Field>

                        {type.fields
                            .filter((field) => fieldApplies(field, type.fields, form.config))
                            .map((field) => (
                                <SettingField
                                    key={field.name}
                                    field={field}
                                    value={form.config[field.name] ?? ''}
                                    stored={current?.stored_credentials.includes(field.name) ?? false}
                                    error={errors[`config.${field.name}`]}
                                    onChange={(value) => setConfig(field.name, value)}
                                />
                            ))}

                        <Field
                            label="Cache values for"
                            hint="Seconds. Within it deployments reuse the encrypted cached value; after it the provider is asked again, and the cached value is the fallback when it is down. 0: always ask."
                            error={errors.cache_ttl_seconds}
                        >
                            <Input
                                type="number"
                                min={0}
                                max={86400}
                                value={form.cache_ttl_seconds}
                                onChange={(event) => setForm({ ...form, cache_ttl_seconds: event.target.value })}
                            />
                        </Field>

                        {type.self_hostable && allow_private_network && (
                            <Field
                                inline
                                label="Allow private network"
                                hint="For a self-hosted provider on your LAN or VPN. Cloud metadata and link-local addresses are always refused."
                                error={errors.allow_private_network}
                            >
                                <Switch
                                    checked={form.allow_private_network}
                                    onCheckedChange={(checked) => setForm({ ...form, allow_private_network: checked })}
                                />
                            </Field>
                        )}
                    </form>
                )}
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(value) => !value && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}`}
                description={
                    deleting && deleting.secrets_count > 0
                        ? `${deleting.secrets_count} linked secret(s) use it: link them to another provider or delete them first.`
                        : 'Its cached values are deleted with it. Nothing changes at the provider.'
                }
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete provider"
                processing={busy === 'delete'}
                onConfirm={remove}
            />
        </SettingsLayout>
    );
}

function SettingField({
    field,
    value,
    stored,
    error,
    onChange,
}: {
    field: ProviderField;
    value: string;
    stored: boolean;
    error?: string;
    onChange: (value: string) => void;
}) {
    const hint = stored && field.secret ? 'Stored. Leave empty to keep it.' : field.hint;

    return (
        <Field label={field.label} hint={hint} error={error} required={field.required && !stored}>
            {field.kind === 'select' ? (
                <Select value={value || field.default} onValueChange={onChange} options={field.options ?? []} />
            ) : field.kind === 'secret' ? (
                <SecretInput stored={stored} value={value} onChange={onChange} placeholder={field.placeholder} mono />
            ) : field.kind === 'textarea' ? (
                <Textarea
                    rows={4}
                    className="font-mono text-xs"
                    value={value}
                    placeholder={field.placeholder}
                    onChange={(event) => onChange(event.target.value)}
                />
            ) : (
                <Input mono value={value} placeholder={field.placeholder} onChange={(event) => onChange(event.target.value)} />
            )}
        </Field>
    );
}
