import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Cloud, MoreHorizontal, Plus, RefreshCw } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface CredentialField {
    name: string;
    label: string;
    secret: boolean;
    help: string;
}

interface ProviderOption {
    value: string;
    label: string;
    fields: CredentialField[];
}

interface Credential {
    id: string;
    name: string;
    provider: string;
    provider_label: string;
    status: 'active' | 'invalid';
    last_verified_at: string | null;
    last_error: string | null;
    created_at: string | null;
}

interface Props {
    credentials: Credential[];
    providers: ProviderOption[];
    can: { manage: boolean };
}

interface CredentialForm {
    provider: string;
    name: string;
    credentials: Record<string, string>;
    [key: string]: string | Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Providers', href: '/providers' }];

function fieldError(errors: Partial<Record<string, string>>, field: string): string | undefined {
    return errors[`credentials.${field}`] ?? undefined;
}

function CredentialFields({
    fields,
    values,
    errors,
    onChange,
    optional = false,
}: {
    fields: CredentialField[];
    values: Record<string, string>;
    errors: Partial<Record<string, string>>;
    onChange: (name: string, value: string) => void;
    optional?: boolean;
}) {
    return (
        <>
            {fields.map((field) => (
                <div key={field.name} className="grid gap-2">
                    <Label htmlFor={`credential-${field.name}`}>
                        {field.label}
                        {optional && <span className="text-muted-foreground ml-1 font-normal">(leave blank to keep)</span>}
                    </Label>
                    <Input
                        id={`credential-${field.name}`}
                        type={field.secret ? 'password' : 'text'}
                        autoComplete="off"
                        value={values[field.name] ?? ''}
                        onChange={(e) => onChange(field.name, e.target.value)}
                    />
                    {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
                    <InputError message={fieldError(errors, field.name)} />
                </div>
            ))}
        </>
    );
}

function AddCredentialDialog({
    providers,
    open,
    onOpenChange,
}: {
    providers: ProviderOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<CredentialForm>({ provider: providers[0]?.value ?? '', name: '', credentials: {} });
    const provider = providers.find((p) => p.value === form.data.provider);
    const errors = form.errors as Partial<Record<string, string>>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('providers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Add provider credential</DialogTitle>
                        <DialogDescription>
                            The credential is verified against the provider before it is saved, then stored encrypted.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label>Provider</Label>
                        <Select
                            value={form.data.provider}
                            onValueChange={(value) => form.setData({ ...form.data, provider: value, credentials: {} })}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Choose a provider" />
                            </SelectTrigger>
                            <SelectContent>
                                {providers.map((p) => (
                                    <SelectItem key={p.value} value={p.value}>
                                        {p.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.provider} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="credential-name">Name</Label>
                        <Input
                            id="credential-name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Production account"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <CredentialFields
                        fields={provider?.fields ?? []}
                        values={form.data.credentials}
                        errors={errors}
                        onChange={(name, value) => form.setData('credentials', { ...form.data.credentials, [name]: value })}
                    />
                    <InputError message={errors.credentials} />

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>{form.processing ? 'Verifying…' : 'Add credential'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditCredentialDialog({
    credential,
    provider,
    onClose,
}: {
    credential: Credential;
    provider: ProviderOption | undefined;
    onClose: () => void;
}) {
    const form = useForm<{ name: string; credentials: Record<string, string>; [key: string]: string | Record<string, string> }>({
        name: credential.name,
        credentials: {},
    });
    const errors = form.errors as Partial<Record<string, string>>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('providers.update', credential.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Edit {credential.name}</DialogTitle>
                        <DialogDescription>
                            Rename the credential or rotate its secret. New secrets are verified before they replace the old ones.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="edit-name">Name</Label>
                        <Input id="edit-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <CredentialFields
                        fields={provider?.fields ?? []}
                        values={form.data.credentials}
                        errors={errors}
                        optional
                        onChange={(name, value) => form.setData('credentials', { ...form.data.credentials, [name]: value })}
                    />
                    <InputError message={errors.credentials} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ProvidersIndex({ credentials, providers, can }: Props) {
    const [adding, setAdding] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('add') && can.manage);
    const [editing, setEditing] = useState<Credential | null>(null);
    const [verifying, setVerifying] = useState<string | null>(null);
    const providerByValue = useMemo(() => new Map(providers.map((p) => [p.value, p])), [providers]);

    const verify = (credential: Credential) => {
        setVerifying(credential.id);
        router.post(route('providers.verify', credential.id), {}, { preserveScroll: true, onFinish: () => setVerifying(null) });
    };

    const remove = (credential: Credential) => {
        if (
            window.confirm(
                `Remove "${credential.name}"? Existing servers keep running, but Kiln can no longer manage them through ${credential.provider_label}.`,
            )
        ) {
            router.delete(route('providers.destroy', credential.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Providers" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Cloud providers"
                        description="API credentials Kiln uses to create and destroy servers. Secrets are encrypted and never shown again."
                    />
                    {can.manage && (
                        <Button onClick={() => setAdding(true)}>
                            <Plus className="mr-1 size-4" />
                            Add credential
                        </Button>
                    )}
                </div>

                {credentials.length === 0 ? (
                    <Card>
                        <CardHeader className="items-center text-center">
                            <Cloud className="text-muted-foreground mb-2 size-10" />
                            <CardTitle>No provider credentials yet</CardTitle>
                            <CardDescription>
                                Connect Hetzner Cloud, DigitalOcean, Vultr, Linode or AWS Lightsail — or add any Ubuntu server with the install
                                command.
                            </CardDescription>
                        </CardHeader>
                        {can.manage && (
                            <CardContent className="flex justify-center">
                                <Button onClick={() => setAdding(true)}>Add your first credential</Button>
                            </CardContent>
                        )}
                    </Card>
                ) : (
                    <Card>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Last verified</TableHead>
                                    <TableHead className="w-12" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {credentials.map((credential) => (
                                    <TableRow key={credential.id}>
                                        <TableCell className="font-medium">{credential.name}</TableCell>
                                        <TableCell>{credential.provider_label}</TableCell>
                                        <TableCell>
                                            <Badge variant={credential.status === 'active' ? 'secondary' : 'destructive'}>{credential.status}</Badge>
                                            {credential.last_error && (
                                                <p className="text-destructive mt-1 max-w-sm truncate text-xs">{credential.last_error}</p>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {credential.last_verified_at
                                                ? formatDistanceToNow(new Date(credential.last_verified_at), { addSuffix: true })
                                                : 'never'}
                                        </TableCell>
                                        <TableCell>
                                            {can.manage && (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="icon" aria-label={`Actions for ${credential.name}`}>
                                                            {verifying === credential.id ? (
                                                                <RefreshCw className="size-4 animate-spin" />
                                                            ) : (
                                                                <MoreHorizontal className="size-4" />
                                                            )}
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem onSelect={() => verify(credential)}>Verify now</DropdownMenuItem>
                                                        <DropdownMenuItem onSelect={() => setEditing(credential)}>
                                                            Rename / rotate secret
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem className="text-destructive" onSelect={() => remove(credential)}>
                                                            Remove
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>

            {can.manage && <AddCredentialDialog providers={providers} open={adding} onOpenChange={setAdding} />}
            {editing && (
                <EditCredentialDialog credential={editing} provider={providerByValue.get(editing.provider)} onClose={() => setEditing(null)} />
            )}
        </AppLayout>
    );
}
