import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm, usePage } from '@inertiajs/react';
import { Globe, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type DnsCredentialOption, type Option } from '../types';

interface Props {
    credentials: DnsCredentialOption[];
    providers: Option[];
    canManage: boolean;
}

/**
 * Organization-wide DNS provider credentials used for ACME DNS-01 (wildcard) certificates.
 */
export function DnsCredentialsCard({ credentials, providers, canManage }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ provider: providers[0]?.value ?? 'cloudflare', name: '', api_token: '' });
    const pageErrors = usePage().props.errors;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('edge.dns-credentials.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const remove = (credential: DnsCredentialOption) => {
        if (window.confirm(`Delete the DNS credential "${credential.name}"?`)) {
            router.delete(route('edge.dns-credentials.destroy', credential.id), { preserveScroll: true });
        }
    };

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-2">
                <div className="space-y-1.5">
                    <CardTitle>DNS providers</CardTitle>
                    <CardDescription>API tokens for DNS-01 challenges (wildcard certificates). Shared by the organization.</CardDescription>
                </div>
                {canManage && (
                    <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                        <Plus /> Add
                    </Button>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                {credentials.length === 0 && <p className="text-muted-foreground text-sm">No DNS credentials.</p>}
                {credentials.map((credential) => (
                    <div key={credential.id} className="flex items-center gap-3 rounded-md border p-2">
                        <Globe className="text-muted-foreground size-4 shrink-0" />
                        <div className="flex-1 text-sm">
                            {credential.name} <span className="text-muted-foreground capitalize">· {credential.provider}</span>
                        </div>
                        {canManage && (
                            <Button variant="ghost" size="icon" onClick={() => remove(credential)} aria-label={`Delete ${credential.name}`}>
                                <Trash2 />
                            </Button>
                        )}
                    </div>
                ))}
                <InputError message={pageErrors.credential} />
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Add DNS credential</DialogTitle>
                            <DialogDescription>
                                For Cloudflare, create an API token with Zone → DNS → Edit permission. The token is stored encrypted and never shown
                                again.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label>Provider</Label>
                            <Select value={form.data.provider} onValueChange={(value) => form.setData('provider', value)}>
                                <SelectTrigger aria-label="Provider">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {providers.map((provider) => (
                                        <SelectItem key={provider.value} value={provider.value}>
                                            {provider.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.provider} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="dns-name">Name</Label>
                            <Input id="dns-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="dns-token">API token</Label>
                            <Input
                                id="dns-token"
                                type="password"
                                autoComplete="off"
                                value={form.data.api_token}
                                onChange={(e) => form.setData('api_token', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.api_token} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
