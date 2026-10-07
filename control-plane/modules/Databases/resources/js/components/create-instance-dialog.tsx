import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { type CreateOptions } from '../types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: CreateOptions;
}

/**
 * A new database container: engine and major, the server it runs on, its memory limit (the engine's config is tuned to
 * it) and the size of its data volume.
 */
export function CreateInstanceDialog({ open, onOpenChange, options }: Props) {
    const first = options.engines[0];
    const form = useForm({
        engine: first?.value ?? 'postgresql',
        version: first?.default_version ?? '',
        server_id: options.servers[0]?.id ?? '',
        name: '',
        memory_mb: String(first?.default_memory_mb ?? 512),
        disk_gb: String(first?.default_disk_gb ?? 10),
    });
    const engine = options.engines.find((item) => item.value === form.data.engine) ?? first;

    const pickEngine = (value: string) => {
        const picked = options.engines.find((item) => item.value === value);
        if (!picked) return;
        form.setData({
            ...form.data,
            engine: picked.value,
            version: picked.default_version,
            memory_mb: String(picked.default_memory_mb),
            disk_gb: String(picked.default_disk_gb),
        });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            memory_mb: data.memory_mb === '' ? null : Number(data.memory_mb),
            disk_gb: data.disk_gb === '' ? null : Number(data.disk_gb),
        }));
        form.post('/databases/instances', {
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
                        <DialogTitle>New database</DialogTitle>
                        <DialogDescription>
                            A container of the Falak {engine?.label ?? ''} image on the server, its data on its own volume. SQL engines get a database
                            and a user named after it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label>Engine</Label>
                            <Select value={form.data.engine} onValueChange={pickEngine}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.engines.map((item) => (
                                        <SelectItem key={item.value} value={item.value}>
                                            {item.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.engine} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Version</Label>
                            <Select value={form.data.version} onValueChange={(value) => form.setData('version', value)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(engine?.versions ?? []).map((version) => (
                                        <SelectItem key={version} value={version}>
                                            {engine?.label} {version}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.version} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="instance-name">Name</Label>
                        <Input
                            id="instance-name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="shop-db"
                            className="font-mono"
                        />
                        <p className="text-muted-foreground text-xs">Lower-case letters, digits, - and _.</p>
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Server</Label>
                        <Select value={form.data.server_id} onValueChange={(value) => form.setData('server_id', value)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Choose a server" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.servers.map((server) => (
                                    <SelectItem key={server.id} value={server.id}>
                                        {server.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.server_id} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="instance-memory">Memory (MB)</Label>
                            <Input
                                id="instance-memory"
                                inputMode="numeric"
                                value={form.data.memory_mb}
                                onChange={(e) => form.setData('memory_mb', e.target.value.replace(/\D/g, ''))}
                            />
                            <p className="text-muted-foreground text-xs">At least {engine?.min_memory_mb ?? 0} MB.</p>
                            <InputError message={form.errors.memory_mb} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="instance-disk">Disk (GB)</Label>
                            <Input
                                id="instance-disk"
                                inputMode="numeric"
                                value={form.data.disk_gb}
                                onChange={(e) => form.setData('disk_gb', e.target.value.replace(/\D/g, ''))}
                            />
                            <InputError message={form.errors.disk_gb} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing || !form.data.name || !form.data.server_id}>Create database</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
