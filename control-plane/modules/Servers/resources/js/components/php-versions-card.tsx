import { CommandLog } from '@/components/command-log';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { MoreHorizontal, Plus, Terminal, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type FpmSettings, type PhpVersionRow } from '../types';

const STATUS_STYLES: Record<PhpVersionRow['status'], string> = {
    installing: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    installed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
    removing: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};

interface Props {
    serverId: string;
    versions: PhpVersionRow[];
    options: string[];
    canUpdate: boolean;
    serverActive: boolean;
}

export function PhpVersionsCard({ serverId, versions, options, canUpdate, serverActive }: Props) {
    const [install, setInstall] = useState<string>('');
    const [editing, setEditing] = useState<PhpVersionRow | null>(null);
    const [logs, setLogs] = useState<Record<string, boolean>>({});
    const [processing, setProcessing] = useState(false);

    const installVersion = () => {
        if (!install) return;
        setProcessing(true);
        router.post(
            route('servers.php.store', serverId),
            { version: install },
            { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => setInstall('') },
        );
    };

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle>PHP</CardTitle>
                {canUpdate && options.length > 0 && serverActive && (
                    <div className="flex items-center gap-2">
                        <Select value={install || undefined} onValueChange={setInstall}>
                            <SelectTrigger className="w-32" aria-label="PHP version to install">
                                <SelectValue placeholder="Version" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.map((version) => (
                                    <SelectItem key={version} value={version}>
                                        PHP {version}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button size="sm" onClick={installVersion} disabled={!install || processing}>
                            <Plus /> Install
                        </Button>
                    </div>
                )}
            </CardHeader>
            <CardContent>
                {versions.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No PHP versions on this server.</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Version</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>FPM pool defaults</TableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {versions.map((php) => {
                                const busy = php.status === 'installing' || php.status === 'removing';

                                return [
                                    <TableRow key={php.id}>
                                        <TableCell className="font-medium">
                                            PHP {php.version}
                                            {php.is_default && (
                                                <Badge variant="secondary" className="ml-2">
                                                    default
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className={cn('border-transparent capitalize', STATUS_STYLES[php.status])}>
                                                {php.status}
                                            </Badge>
                                            {php.status_message && <div className="text-destructive mt-1 text-xs">{php.status_message}</div>}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            pm={php.fpm.pm}, max_children={php.fpm.max_children}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-1">
                                                {php.command_id && busy && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Toggle output"
                                                        onClick={() => setLogs((current) => ({ ...current, [php.id]: !current[php.id] }))}
                                                    >
                                                        <Terminal />
                                                    </Button>
                                                )}
                                                {canUpdate && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="icon" aria-label={`Actions for PHP ${php.version}`}>
                                                                <MoreHorizontal />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem disabled={php.status !== 'installed'} onSelect={() => setEditing(php)}>
                                                                Edit settings
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                disabled={php.status !== 'installed' || php.is_default}
                                                                onSelect={() =>
                                                                    router.put(
                                                                        route('servers.php.default', [serverId, php.version]),
                                                                        {},
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                Make default
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                disabled={php.is_default || busy}
                                                                className="text-destructive"
                                                                onSelect={() =>
                                                                    router.delete(route('servers.php.destroy', [serverId, php.version]), {
                                                                        preserveScroll: true,
                                                                    })
                                                                }
                                                            >
                                                                <Trash2 /> Remove
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>,
                                    logs[php.id] && php.command_id ? (
                                        <TableRow key={`${php.id}-log`}>
                                            <TableCell colSpan={4}>
                                                <CommandLog commandId={php.command_id} />
                                            </TableCell>
                                        </TableRow>
                                    ) : null,
                                ];
                            })}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
            {editing && <PhpSettingsDialog serverId={serverId} php={editing} onClose={() => setEditing(null)} />}
        </Card>
    );
}

type IniRow = { key: string; value: string };
type SettingsForm = { ini: IniRow[]; fpm: FpmSettings };

function PhpSettingsDialog({ serverId, php, onClose }: { serverId: string; php: PhpVersionRow; onClose: () => void }) {
    const { data, setData, put, processing, errors, transform } = useForm<SettingsForm>({
        ini: Object.entries(php.ini).map(([key, value]) => ({ key, value: String(value) })),
        fpm: php.fpm,
    });
    const errorBag = errors as Partial<Record<string, string>>;

    const setIni = (index: number, patch: Partial<IniRow>) =>
        setData(
            'ini',
            data.ini.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
    const setFpm = <K extends keyof FpmSettings>(key: K, value: FpmSettings[K]) => setData('fpm', { ...data.fpm, [key]: value });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        transform((form) => ({
            ini: Object.fromEntries(form.ini.filter((row) => row.key.trim() !== '').map((row) => [row.key.trim(), row.value])),
            fpm: form.fpm,
        }));
        put(route('servers.php.settings', [serverId, php.version]), { preserveScroll: true, onSuccess: onClose });
    };

    const numberField = (key: Exclude<keyof FpmSettings, 'pm'>, label: string) => (
        <div className="grid gap-1">
            <Label htmlFor={`fpm-${key}`}>{label}</Label>
            <Input id={`fpm-${key}`} type="number" min={0} value={data.fpm[key]} onChange={(e) => setFpm(key, Number(e.target.value))} />
            <InputError message={errorBag[`fpm.${key}`]} />
        </div>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>PHP {php.version} settings</DialogTitle>
                        <DialogDescription>php.ini overrides are applied immediately; FPM values are defaults for new site pools.</DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <Label>php.ini overrides</Label>
                        {data.ini.map((row, index) => (
                            <div key={index} className="flex gap-2">
                                <Input
                                    aria-label="Directive"
                                    value={row.key}
                                    onChange={(e) => setIni(index, { key: e.target.value })}
                                    className="font-mono text-xs"
                                    placeholder="memory_limit"
                                />
                                <Input
                                    aria-label="Value"
                                    value={row.value}
                                    onChange={(e) => setIni(index, { value: e.target.value })}
                                    className="font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove directive"
                                    onClick={() =>
                                        setData(
                                            'ini',
                                            data.ini.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('ini', [...data.ini, { key: '', value: '' }])}>
                            <Plus /> Add directive
                        </Button>
                        <InputError message={errorBag.ini} />
                    </div>

                    <div className="space-y-2">
                        <Label>FPM pool defaults</Label>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="grid gap-1">
                                <Label htmlFor="fpm-pm">Process manager</Label>
                                <Select value={data.fpm.pm} onValueChange={(value) => setFpm('pm', value as FpmSettings['pm'])}>
                                    <SelectTrigger id="fpm-pm">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="dynamic">dynamic</SelectItem>
                                        <SelectItem value="static">static</SelectItem>
                                        <SelectItem value="ondemand">ondemand</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {numberField('max_children', 'Max children')}
                            {numberField('start_servers', 'Start servers')}
                            {numberField('min_spare_servers', 'Min spare')}
                            {numberField('max_spare_servers', 'Max spare')}
                            {numberField('max_requests', 'Max requests')}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Save settings</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
