import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface SshKeyRow {
    id: string;
    name: string;
    fingerprint: string;
    type: string;
    servers_count: number;
    created_at: string | null;
}

interface Props {
    keys: SshKeyRow[];
    canManage: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'SSH keys', href: '/ssh-keys' }];

export default function SshKeys({ keys, canManage }: Props) {
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<SshKeyRow | null>(null);
    const form = useForm({ name: '', public_key: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('ssh-keys.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('ssh-keys.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SSH keys" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="SSH keys" description="Organization keys that can be synced to servers' authorized_keys" />
                    {canManage && (
                        <Button onClick={() => setOpen(true)}>
                            <Plus /> Add key
                        </Button>
                    )}
                </div>

                {keys.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <KeyRound className="text-muted-foreground size-10" />
                            <p className="font-medium">No SSH keys yet</p>
                            <p className="text-muted-foreground text-sm">Add a public key to grant SSH access to servers as the kiln or root user.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Fingerprint</TableHead>
                                    <TableHead>Servers</TableHead>
                                    <TableHead>Added</TableHead>
                                    {canManage && <TableHead className="w-12" />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {keys.map((key) => (
                                    <TableRow key={key.id}>
                                        <TableCell className="font-medium">{key.name}</TableCell>
                                        <TableCell className="font-mono text-xs">{key.type}</TableCell>
                                        <TableCell className="font-mono text-xs break-all">{key.fingerprint}</TableCell>
                                        <TableCell className="tabular-nums">{key.servers_count}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {key.created_at ? formatDistanceToNow(new Date(key.created_at), { addSuffix: true }) : '—'}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => setDeleting(key)}
                                                    aria-label={`Delete ${key.name}`}
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

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Add SSH key</DialogTitle>
                            <DialogDescription>Paste an OpenSSH public key (ed25519, ecdsa, rsa ≥ 2048 bits or a security key).</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="key-name">Name</Label>
                            <Input id="key-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Laptop" />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="key-value">Public key</Label>
                            <Textarea
                                id="key-value"
                                value={form.data.public_key}
                                onChange={(e) => form.setData('public_key', e.target.value)}
                                placeholder="ssh-ed25519 AAAAC3Nza… you@host"
                                className="font-mono text-xs"
                                rows={5}
                            />
                            <InputError message={form.errors.public_key} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>Add key</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>
                            The key is removed from the {deleting?.servers_count ?? 0} server(s) it is installed on.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            Delete key
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
