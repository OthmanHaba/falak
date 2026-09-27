import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Play, Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ScriptBlock } from '../components/run-ui';

interface RunnableRecipe {
    id: string | null;
    key?: string;
    name: string;
    description: string | null;
    script: string;
    user: string;
    builtin: boolean;
    variables: Record<string, string>;
    run_url: string;
}

interface ServerOption {
    id: string;
    name: string;
    type: string;
    status: string;
    ipv4: string | null;
}

interface Props {
    recipe: RunnableRecipe;
    servers: ServerOption[];
    defaultTimeout: number;
    maxTimeout: number;
}

interface EnvPair {
    name: string;
    value: string;
}

interface RunForm {
    server_ids: string[];
    env: EnvPair[];
    timeout: number;
}

export default function Run({ recipe, servers, defaultTimeout, maxTimeout }: Props) {
    const [confirming, setConfirming] = useState(false);
    const [filter, setFilter] = useState('');
    const form = useForm<RunForm>({
        server_ids: [],
        env: Object.keys(recipe.variables).map((name) => ({ name, value: '' })),
        timeout: defaultTimeout,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Recipes', href: '/settings/recipes' },
        { title: `Run ${recipe.name}`, href: typeof window === 'undefined' ? '/settings/recipes' : window.location.pathname },
    ];

    const visible = useMemo(
        () => servers.filter((server) => `${server.name} ${server.ipv4 ?? ''} ${server.type}`.toLowerCase().includes(filter.toLowerCase())),
        [servers, filter],
    );
    const selected = new Set(form.data.server_ids);
    const allVisibleSelected = visible.length > 0 && visible.every((server) => selected.has(server.id));

    const toggle = (id: string, checked: boolean) => {
        const next = new Set(form.data.server_ids);

        if (checked) {
            next.add(id);
        } else {
            next.delete(id);
        }

        form.setData('server_ids', [...next]);
    };

    const toggleAll = (checked: boolean) => {
        const next = new Set(form.data.server_ids);
        visible.forEach((server) => (checked ? next.add(server.id) : next.delete(server.id)));
        form.setData('server_ids', [...next]);
    };

    const setEnv = (index: number, pair: Partial<EnvPair>) =>
        form.setData(
            'env',
            form.data.env.map((item, i) => (i === index ? { ...item, ...pair } : item)),
        );

    const submit = () => {
        form.transform((data) => ({ ...data, env: data.env.filter((pair) => pair.name.trim() !== '') }));
        form.post(recipe.run_url, { onFinish: () => setConfirming(false) });
    };

    const envErrors = Object.entries(form.errors)
        .filter(([key]) => key.startsWith('env'))
        .map(([, message]) => message);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Run ${recipe.name}`} />
            <div className="space-y-6 p-4">
                <Heading title={`Run ${recipe.name}`} description={recipe.description ?? undefined} />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Script <span className="text-muted-foreground text-sm font-normal">· runs as {recipe.user}</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ScriptBlock script={recipe.script} />
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-2">
                                <CardTitle className="text-base">Servers ({form.data.server_ids.length} selected)</CardTitle>
                                <Input placeholder="Filter…" value={filter} onChange={(e) => setFilter(e.target.value)} className="h-8 w-40" />
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {servers.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">No servers in this organization.</p>
                                ) : (
                                    <>
                                        <label className="flex items-center gap-2 border-b pb-2 text-sm font-medium">
                                            <Checkbox checked={allVisibleSelected} onCheckedChange={(value) => toggleAll(value === true)} />
                                            Select all
                                        </label>
                                        <div className="max-h-72 space-y-1 overflow-auto">
                                            {visible.map((server) => (
                                                <label
                                                    key={server.id}
                                                    className="hover:bg-muted/50 flex items-center gap-2 rounded px-1 py-1 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={selected.has(server.id)}
                                                        onCheckedChange={(value) => toggle(server.id, value === true)}
                                                    />
                                                    <span className="font-medium">{server.name}</span>
                                                    <span className="text-muted-foreground font-mono text-xs">{server.ipv4}</span>
                                                    <span className="text-muted-foreground ml-auto text-xs">
                                                        {server.type} · {server.status}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </>
                                )}
                                <InputError message={form.errors.server_ids} />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Variables & options</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {form.data.env.map((pair, index) => (
                                    <div key={index} className="flex items-center gap-2">
                                        <Input
                                            value={pair.name}
                                            onChange={(e) => setEnv(index, { name: e.target.value })}
                                            placeholder="NAME"
                                            className="w-40 font-mono"
                                            aria-label="Variable name"
                                        />
                                        <Input
                                            value={pair.value}
                                            onChange={(e) => setEnv(index, { value: e.target.value })}
                                            placeholder={recipe.variables[pair.name] ?? 'value'}
                                            className="font-mono"
                                            aria-label={`Value for ${pair.name || 'variable'}`}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                form.setData(
                                                    'env',
                                                    form.data.env.filter((_, i) => i !== index),
                                                )
                                            }
                                            aria-label="Remove variable"
                                        >
                                            <X />
                                        </Button>
                                    </div>
                                ))}
                                {envErrors.map((message) => (
                                    <InputError key={message} message={message} />
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => form.setData('env', [...form.data.env, { name: '', value: '' }])}
                                >
                                    <Plus /> Add variable
                                </Button>
                                <div className="grid max-w-48 gap-2 pt-2">
                                    <Label htmlFor="run-timeout">Timeout (seconds)</Label>
                                    <Input
                                        id="run-timeout"
                                        type="number"
                                        min={10}
                                        max={maxTimeout}
                                        value={form.data.timeout}
                                        onChange={(e) => form.setData('timeout', Number(e.target.value))}
                                    />
                                    <InputError message={form.errors.timeout} />
                                </div>
                            </CardContent>
                        </Card>

                        <div className="flex justify-end">
                            <Button disabled={form.data.server_ids.length === 0 || form.processing} onClick={() => setConfirming(true)}>
                                <Play /> Run on {form.data.server_ids.length} server{form.data.server_ids.length === 1 ? '' : 's'}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Run {recipe.name}?</DialogTitle>
                        <DialogDescription>
                            The script runs as <span className="font-mono">{recipe.user}</span> on {form.data.server_ids.length} server
                            {form.data.server_ids.length === 1 ? '' : 's'} in parallel. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Run now
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
