import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Link, router, useForm } from '@inertiajs/react';
import { KeyRound, Plus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type SshKeyOption } from '../types';

interface AttachedKey extends SshKeyOption {
    unix_user: string | null;
}

interface Props {
    serverId: string;
    attached: AttachedKey[];
    available: SshKeyOption[];
    canUpdate: boolean;
}

export function SshKeysCard({ serverId, attached, available, canUpdate }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ ssh_key_id: '', unix_user: 'kiln' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('servers.ssh-keys.attach', serverId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const detach = (key: AttachedKey) =>
        router.delete(route('servers.ssh-keys.detach', [serverId, key.id]), { data: { unix_user: key.unix_user }, preserveScroll: true });

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle>SSH keys</CardTitle>
                {canUpdate && available.length > 0 && (
                    <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                        <Plus /> Add
                    </Button>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                {attached.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No keys installed.{' '}
                        {available.length === 0 && (
                            <Link href={route('ssh-keys.index')} className="text-primary underline">
                                Manage SSH keys
                            </Link>
                        )}
                    </p>
                ) : (
                    attached.map((key) => (
                        <div key={`${key.id}-${key.unix_user}`} className="flex items-center gap-3 rounded-md border p-2">
                            <KeyRound className="text-muted-foreground size-4 shrink-0" />
                            <div className="min-w-0 flex-1">
                                <div className="text-sm font-medium">
                                    {key.name} <span className="text-muted-foreground font-normal">→ {key.unix_user}</span>
                                </div>
                                <div className="text-muted-foreground truncate font-mono text-xs">{key.fingerprint}</div>
                            </div>
                            {canUpdate && (
                                <Button variant="ghost" size="icon" onClick={() => detach(key)} aria-label={`Remove ${key.name}`}>
                                    <X />
                                </Button>
                            )}
                        </div>
                    ))
                )}
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Install SSH key</DialogTitle>
                            <DialogDescription>The key is added to the user's authorized_keys by the agent.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="attach-key">Key</Label>
                            <Select value={form.data.ssh_key_id || undefined} onValueChange={(value) => form.setData('ssh_key_id', value)}>
                                <SelectTrigger id="attach-key">
                                    <SelectValue placeholder="Choose a key" />
                                </SelectTrigger>
                                <SelectContent>
                                    {available.map((key) => (
                                        <SelectItem key={key.id} value={key.id}>
                                            {key.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.ssh_key_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="attach-user">Unix user</Label>
                            <Select value={form.data.unix_user} onValueChange={(value) => form.setData('unix_user', value)}>
                                <SelectTrigger id="attach-user">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="kiln">kiln</SelectItem>
                                    <SelectItem value="root">root</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.unix_user} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing || !form.data.ssh_key_id}>Install key</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
