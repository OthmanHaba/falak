import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { HardDrive, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Databases', href: '/databases' },
    { title: 'Storage', href: '/databases/storage' },
];

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

export default function Storage({ providers, drivers, can }: Props) {
    const [editing, setEditing] = useState<StorageProviderRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<StorageProviderRow | null>(null);
    const [verifying, setVerifying] = useState<string | null>(null);
    const form = useForm<ProviderForm>(EMPTY);
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const driver = drivers.find((d) => d.value === form.data.driver);

    const open = (provider: StorageProviderRow | 'new') => {
        form.clearErrors();
        form.setData(
            provider === 'new'
                ? EMPTY
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

        if (editing === 'new') {
            form.post('/databases/storage', options);
        } else if (editing) {
            form.put(`/databases/storage/${editing.id}`, options);
        }
    };

    const verify = (provider: StorageProviderRow) => {
        setVerifying(provider.id);
        router.post(`/databases/storage/${provider.id}/verify`, {}, { preserveScroll: true, onFinish: () => setVerifying(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Backup storage" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Backup storage"
                        description="S3-compatible buckets for database backups. Credentials stay on the control plane; servers only receive short-lived presigned URLs."
                    />
                    {can.manage && (
                        <Button onClick={() => open('new')}>
                            <Plus /> Add provider
                        </Button>
                    )}
                </div>

                {errors.provider && <p className="text-sm text-red-600">{errors.provider}</p>}

                {providers.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <HardDrive className="text-muted-foreground size-10" />
                            <p className="font-medium">No storage providers</p>
                            <p className="text-muted-foreground text-sm">Add Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces or MinIO.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>Bucket</TableHead>
                                    <TableHead>Access key</TableHead>
                                    <TableHead>Verified</TableHead>
                                    {can.manage && <TableHead className="w-32" />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {providers.map((provider) => (
                                    <TableRow key={provider.id}>
                                        <TableCell className="font-medium">{provider.name}</TableCell>
                                        <TableCell>
                                            {provider.driver_label}
                                            <div className="text-muted-foreground text-xs">{provider.endpoint}</div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {provider.bucket}
                                            {provider.prefix ? `/${provider.prefix}` : ''}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{provider.access_key_hint}</TableCell>
                                        <TableCell>
                                            {provider.verified_at ? (
                                                <span className="text-sm text-emerald-700 dark:text-emerald-300">
                                                    {formatDistanceToNow(new Date(provider.verified_at), { addSuffix: true })}
                                                </span>
                                            ) : (
                                                <Badge variant="outline">unverified</Badge>
                                            )}
                                        </TableCell>
                                        {can.manage && (
                                            <TableCell className="text-right whitespace-nowrap">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Verify (writes and deletes a probe object)"
                                                    aria-label={`Verify ${provider.name}`}
                                                    disabled={verifying === provider.id}
                                                    onClick={() => verify(provider)}
                                                >
                                                    <ShieldCheck />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${provider.name}`}
                                                    onClick={() => open(provider)}
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Delete ${provider.name}`}
                                                    onClick={() => setDeleting(provider)}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(value) => !value && setEditing(null)}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editing === 'new' ? 'Add storage provider' : 'Edit storage provider'}</DialogTitle>
                            <DialogDescription>
                                The key needs PutObject, GetObject and DeleteObject on the bucket.
                                {editing !== 'new' && ' Leave the credentials empty to keep the stored ones.'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="provider-name">Name</Label>
                                <Input id="provider-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Provider</Label>
                                <Select
                                    value={form.data.driver}
                                    onValueChange={(value) => {
                                        const next = drivers.find((d) => d.value === value);
                                        form.setData({
                                            ...form.data,
                                            driver: value as ProviderForm['driver'],
                                            region: next?.default_region ?? '',
                                            path_style: value === 'r2' || value === 'minio',
                                        });
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {drivers.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.driver} />
                            </div>
                        </div>
                        {form.data.driver === 'minio' && (
                            <div className="grid gap-2">
                                <Label htmlFor="provider-endpoint">Endpoint</Label>
                                <Input
                                    id="provider-endpoint"
                                    value={form.data.endpoint}
                                    onChange={(e) => form.setData('endpoint', e.target.value)}
                                    placeholder="https://minio.example.com"
                                />
                                <InputError message={form.errors.endpoint} />
                            </div>
                        )}
                        {form.data.driver === 'r2' && (
                            <div className="grid gap-2">
                                <Label htmlFor="provider-account">Cloudflare account ID</Label>
                                <Input
                                    id="provider-account"
                                    className="font-mono"
                                    value={form.data.account_id}
                                    onChange={(e) => form.setData('account_id', e.target.value)}
                                    placeholder={editing !== 'new' ? 'Unchanged' : ''}
                                />
                                <InputError message={form.errors.account_id} />
                            </div>
                        )}
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="provider-region">Region</Label>
                                <Input
                                    id="provider-region"
                                    value={form.data.region}
                                    onChange={(e) => form.setData('region', e.target.value)}
                                    placeholder={driver?.region_hint}
                                />
                                <InputError message={form.errors.region} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="provider-bucket">Bucket</Label>
                                <Input id="provider-bucket" value={form.data.bucket} onChange={(e) => form.setData('bucket', e.target.value)} />
                                <InputError message={form.errors.bucket} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="provider-prefix">Path prefix</Label>
                                <Input
                                    id="provider-prefix"
                                    value={form.data.prefix}
                                    onChange={(e) => form.setData('prefix', e.target.value)}
                                    placeholder="optional"
                                />
                                <InputError message={form.errors.prefix} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="provider-key">Access key ID</Label>
                                <Input
                                    id="provider-key"
                                    autoComplete="off"
                                    className="font-mono"
                                    value={form.data.access_key_id}
                                    onChange={(e) => form.setData('access_key_id', e.target.value)}
                                    placeholder={editing !== 'new' && editing ? `Unchanged (${editing.access_key_hint})` : ''}
                                />
                                <InputError message={form.errors.access_key_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="provider-secret">Secret access key</Label>
                                <Input
                                    id="provider-secret"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.secret_access_key}
                                    onChange={(e) => form.setData('secret_access_key', e.target.value)}
                                    placeholder={editing !== 'new' ? 'Unchanged' : ''}
                                />
                                <InputError message={form.errors.secret_access_key} />
                            </div>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.path_style} onCheckedChange={(value) => form.setData('path_style', value === true)} />
                            Path-style URLs (endpoint/bucket/key)
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>Save provider</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>
                            Objects in the bucket are not touched, but backups stored there can no longer be restored or pruned from Kiln.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(`/databases/storage/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })
                            }
                        >
                            Delete provider
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
