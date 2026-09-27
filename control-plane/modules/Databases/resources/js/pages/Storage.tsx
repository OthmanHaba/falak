import { Button } from '@/components/kiln/button';
import { Callout } from '@/components/kiln/callout';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { DataTable } from '@/components/kiln/data-table';
import { Dialog } from '@/components/kiln/dialog';
import { EmptyState } from '@/components/kiln/empty-state';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { IntegrationTile } from '@/components/kiln/integration-icon';
import { RelativeTime } from '@/components/kiln/relative-time';
import { SecretInput } from '@/components/kiln/secret-input';
import { StatusBadge } from '@/components/kiln/status';
import { Switch } from '@/components/kiln/switch';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { router, useForm, usePage } from '@inertiajs/react';
import { HardDrive, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { type StorageProviderRow } from '../types';

interface DriverOption {
    value: StorageProviderRow['driver'];
    label: string;
    requires_endpoint: boolean;
    default_region: string | null;
    region_hint: string;
}

interface Props {
    providers: StorageProviderRow[];
    drivers: DriverOption[];
    can: { manage: boolean };
}

interface ProviderForm {
    name: string;
    driver: StorageProviderRow['driver'];
    region: string;
    bucket: string;
    prefix: string;
    endpoint: string;
    account_id: string;
    path_style: boolean;
    access_key_id: string;
    secret_access_key: string;
}

const EMPTY: ProviderForm = {
    name: '',
    driver: 's3',
    region: '',
    bucket: '',
    prefix: '',
    endpoint: '',
    account_id: '',
    path_style: false,
    access_key_id: '',
    secret_access_key: '',
};

/** Client-side checks mirroring the server rules, shown as you type (the server stays authoritative). */
function localErrors(data: ProviderForm): Partial<Record<keyof ProviderForm, string>> {
    const errors: Partial<Record<keyof ProviderForm, string>> = {};

    if (data.bucket && !/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/.test(data.bucket)) {
        errors.bucket = 'Lowercase letters, digits, dots and dashes; must start and end with a letter or digit.';
    } else if (data.bucket && (data.bucket.length < 3 || data.bucket.length > 63)) {
        errors.bucket = 'Bucket names are 3–63 characters.';
    }
    if (data.region && !/^[a-z0-9-]+$/.test(data.region)) errors.region = 'Lowercase letters, digits and dashes only.';
    if (data.driver === 'minio' && data.endpoint && !/^https:\/\//.test(data.endpoint)) errors.endpoint = 'Use an https:// URL.';
    if (data.driver === 'r2' && data.account_id && !/^[a-f0-9]{32}$/.test(data.account_id)) {
        errors.account_id = 'The 32-character hex account ID from the Cloudflare dashboard.';
    }

    return errors;
}

export default function Storage({ providers, drivers, can }: Props) {
    const [editing, setEditing] = useState<StorageProviderRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<StorageProviderRow | null>(null);
    const [verifying, setVerifying] = useState<string | null>(null);
    const [verifyError, setVerifyError] = useState<{ id: string; message: string } | null>(null);
    const form = useForm<ProviderForm>(EMPTY);
    const pageErrors = usePage().props.errors as Record<string, string | undefined>;
    const driver = drivers.find((option) => option.value === form.data.driver);
    const isNew = editing === 'new';
    const current = editing !== 'new' ? editing : null;
    const hints = localErrors(form.data);
    const error = (key: keyof ProviderForm) => form.errors[key] ?? hints[key];

    const open = (provider: StorageProviderRow | 'new') => {
        form.clearErrors();
        form.setData(
            provider === 'new'
                ? { ...EMPTY, region: drivers[0]?.default_region ?? '' }
                : {
                      name: provider.name,
                      driver: provider.driver,
                      region: provider.region,
                      bucket: provider.bucket,
                      prefix: provider.prefix ?? '',
                      endpoint: provider.endpoint ?? '',
                      account_id: '',
                      path_style: provider.path_style,
                      access_key_id: '',
                      secret_access_key: '',
                  },
        );
        setEditing(provider);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            region: data.region || null,
            prefix: data.prefix || null,
            endpoint: data.driver === 'minio' ? data.endpoint : null,
            account_id: data.driver === 'r2' ? data.account_id || null : null,
            access_key_id: data.access_key_id || null,
            secret_access_key: data.secret_access_key || null,
        }));
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (isNew) {
            form.post(route('databases.storage.store'), options);
        } else if (current) {
            form.put(route('databases.storage.update', current.id), options);
        }
    };

    const verify = (provider: StorageProviderRow) => {
        setVerifying(provider.id);
        setVerifyError(null);
        router.post(
            route('databases.storage.verify', provider.id),
            {},
            {
                preserveScroll: true,
                onError: (errors) => setVerifyError({ id: provider.id, message: errors.provider ?? 'Verification failed.' }),
                onFinish: () => setVerifying(null),
            },
        );
    };

    const remove = () =>
        new Promise<void>((resolve) => {
            if (!deleting) return resolve();
            router.delete(route('databases.storage.destroy', deleting.id), {
                preserveScroll: true,
                onSuccess: () => setDeleting(null),
                onFinish: () => resolve(),
            });
        });

    return (
        <SettingsLayout
            title="Backup storage"
            description="S3-compatible buckets for database backups. Keys stay on the control plane — servers only ever receive short-lived presigned URLs."
            actions={
                can.manage &&
                providers.length > 0 && (
                    <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                        Add bucket
                    </Button>
                )
            }
            wide
        >
            {pageErrors.provider && !verifyError && <Callout tone="danger">{pageErrors.provider}</Callout>}

            {providers.length === 0 ? (
                <EmptyState
                    icon={<HardDrive />}
                    title="No backup storage yet"
                    description="Add an Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces or MinIO bucket. Backup schedules on your databases upload here."
                    action={
                        can.manage && (
                            <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                                Add a bucket
                            </Button>
                        )
                    }
                    secondary={
                        <div className="flex items-center gap-1.5" aria-hidden>
                            {drivers.map((option) => (
                                <IntegrationTile key={option.value} name={option.value} size="sm" />
                            ))}
                        </div>
                    }
                />
            ) : (
                <DataTable
                    label="Backup storage providers"
                    rows={providers}
                    rowKey={(provider) => provider.id}
                    columns={[
                        {
                            id: 'name',
                            header: 'Bucket',
                            sortValue: (provider) => provider.name,
                            cell: (provider) => (
                                <span className="flex min-w-0 items-center gap-2.5 py-1.5">
                                    <IntegrationTile name={provider.driver} size="sm" />
                                    <span className="grid min-w-0">
                                        <span className="truncate font-medium">{provider.name}</span>
                                        <span className="text-fg-faint truncate font-mono text-xs">
                                            {provider.bucket}
                                            {provider.prefix ? `/${provider.prefix}` : ''}
                                        </span>
                                    </span>
                                </span>
                            ),
                        },
                        {
                            id: 'driver',
                            header: 'Provider',
                            hideOnMobile: true,
                            cell: (provider) => (
                                <span className="grid">
                                    <span className="text-fg-muted">{provider.driver_label}</span>
                                    <span className="text-fg-faint truncate text-xs">{provider.endpoint ?? provider.region}</span>
                                </span>
                            ),
                        },
                        {
                            id: 'key',
                            header: 'Access key',
                            hideOnMobile: true,
                            cell: (provider) => <span className="text-fg-muted font-mono text-xs">{provider.access_key_hint}</span>,
                        },
                        {
                            id: 'verified',
                            header: 'Status',
                            cell: (provider) => (
                                <span className="grid justify-items-start gap-0.5 py-1.5">
                                    {verifyError?.id === provider.id ? (
                                        <StatusBadge status="failed" label="Check failed" />
                                    ) : provider.verified_at ? (
                                        <StatusBadge status="active" label="Verified" />
                                    ) : (
                                        <StatusBadge status="queued" label="Unverified" />
                                    )}
                                    {verifyError?.id === provider.id ? (
                                        <span className="text-danger line-clamp-2 max-w-xs text-xs" title={verifyError.message}>
                                            {verifyError.message}
                                        </span>
                                    ) : (
                                        provider.verified_at && <RelativeTime value={provider.verified_at} className="text-fg-faint text-xs" />
                                    )}
                                </span>
                            ),
                        },
                        ...(can.manage
                            ? [
                                  {
                                      id: 'verify',
                                      hideOnMobile: true,
                                      header: <span className="sr-only">Verify</span>,
                                      align: 'right' as const,
                                      cell: (provider: StorageProviderRow) => (
                                          <Button
                                              size="sm"
                                              variant="ghost"
                                              icon={<ShieldCheck />}
                                              loading={verifying === provider.id}
                                              onClick={() => verify(provider)}
                                              aria-label={`Verify ${provider.name}`}
                                              title="Writes and deletes a probe object"
                                          >
                                              <span className="hidden sm:inline">Verify</span>
                                          </Button>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                    rowActions={
                        can.manage
                            ? (provider) => [
                                  { label: 'Verify now', icon: <ShieldCheck />, onSelect: () => verify(provider) },
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => open(provider) },
                                  { type: 'separator' },
                                  { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(provider) },
                              ]
                            : undefined
                    }
                />
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                title={isNew ? 'Add backup storage' : `Edit ${current?.name ?? ''}`}
                description="The key needs PutObject, GetObject and DeleteObject on the bucket. Use Verify after saving to prove it."
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            type="submit"
                            form="storage-form"
                            loading={form.processing}
                            disabled={Object.keys(hints).length > 0}
                        >
                            {isNew ? 'Add bucket' : 'Save changes'}
                        </Button>
                    </>
                }
            >
                <form id="storage-form" onSubmit={submit} className="grid gap-4">
                    <fieldset className="grid gap-1.5">
                        <legend className="text-fg mb-1.5 text-xs font-medium">Provider</legend>
                        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3" role="radiogroup" aria-label="Storage provider">
                            {drivers.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.driver === option.value}
                                    onClick={() =>
                                        form.setData({
                                            ...form.data,
                                            driver: option.value,
                                            region: option.default_region ?? '',
                                            path_style: option.value === 'r2' || option.value === 'minio',
                                        })
                                    }
                                    className={cn(
                                        'flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-xs transition-colors duration-150',
                                        form.data.driver === option.value
                                            ? 'border-primary bg-primary-soft text-fg'
                                            : 'border-border bg-surface-2 text-fg-muted hover:border-border-strong hover:text-fg',
                                    )}
                                >
                                    <IntegrationTile name={option.value} size="sm" className="bg-surface-1" />
                                    <span className="truncate">{option.label}</span>
                                </button>
                            ))}
                        </div>
                        {form.errors.driver && <p className="text-danger text-xs">{form.errors.driver}</p>}
                    </fieldset>

                    <Field label="Name" error={form.errors.name} required>
                        <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Offsite backups" />
                    </Field>

                    {form.data.driver === 'minio' && (
                        <Field label="Endpoint" error={error('endpoint')} required>
                            <Input
                                mono
                                value={form.data.endpoint}
                                onChange={(event) => form.setData('endpoint', event.target.value)}
                                placeholder="https://minio.example.com"
                            />
                        </Field>
                    )}
                    {form.data.driver === 'r2' && (
                        <Field
                            label="Cloudflare account ID"
                            error={error('account_id')}
                            hint={!isNew ? 'Leave empty to keep the stored account.' : undefined}
                            required={isNew}
                        >
                            <Input
                                mono
                                value={form.data.account_id}
                                onChange={(event) => form.setData('account_id', event.target.value)}
                                placeholder={isNew ? '0123456789abcdef0123456789abcdef' : 'Unchanged'}
                            />
                        </Field>
                    )}

                    <div className="grid items-start gap-4 sm:grid-cols-3">
                        <Field label="Bucket" error={error('bucket')} required>
                            <Input mono value={form.data.bucket} onChange={(event) => form.setData('bucket', event.target.value)} />
                        </Field>
                        <Field label="Region" error={error('region')} hint={driver?.region_hint}>
                            <Input mono value={form.data.region} onChange={(event) => form.setData('region', event.target.value)} />
                        </Field>
                        <Field label="Path prefix" error={form.errors.prefix} hint="Optional folder">
                            <Input
                                mono
                                value={form.data.prefix}
                                onChange={(event) => form.setData('prefix', event.target.value)}
                                placeholder="kiln/"
                            />
                        </Field>
                    </div>

                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <Field label="Access key ID" error={form.errors.access_key_id} required={isNew}>
                            {isNew ? (
                                <Input
                                    mono
                                    autoComplete="off"
                                    value={form.data.access_key_id}
                                    onChange={(event) => form.setData('access_key_id', event.target.value)}
                                />
                            ) : (
                                <SecretInput
                                    stored
                                    storedHint={current?.access_key_hint}
                                    value={form.data.access_key_id}
                                    onChange={(value) => form.setData('access_key_id', value)}
                                />
                            )}
                        </Field>
                        <Field label="Secret access key" error={form.errors.secret_access_key} required={isNew}>
                            <SecretInput
                                stored={!isNew}
                                value={form.data.secret_access_key}
                                onChange={(value) => form.setData('secret_access_key', value)}
                            />
                        </Field>
                    </div>

                    <Field inline label="Path-style URLs (endpoint/bucket/key)" hint="Required by MinIO and R2; virtual-hosted style otherwise.">
                        <Switch checked={form.data.path_style} onCheckedChange={(value) => form.setData('path_style', value)} />
                    </Field>
                </form>
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(value) => !value && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}`}
                description="Objects in the bucket are not touched, but backups stored there can no longer be restored or pruned from Kiln."
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete storage"
                onConfirm={remove}
            />
        </SettingsLayout>
    );
}
