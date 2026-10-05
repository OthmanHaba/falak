import { AppShell } from '@/components/falak/app-shell';
import { Button, IconButton } from '@/components/falak/button';
import { Checkbox } from '@/components/falak/checkbox';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { PageHeader, Section } from '@/components/falak/section';
import { StatusDot } from '@/components/falak/status';
import { Tag } from '@/components/falak/tag';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Play, Plus, Search, X } from 'lucide-react';
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
    preselected?: string[];
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

const ENV_NAME = /^[A-Za-z_][A-Za-z0-9_]*$/;
const TYPE_LABELS: Record<string, string> = {
    app: 'App',
    web: 'Web',
    db: 'Database',
    cache: 'Cache',
    worker: 'Worker',
    lb: 'Load balancer',
    builder: 'Builder',
};

export default function Run({ recipe, servers, preselected = [], defaultTimeout, maxTimeout }: Props) {
    const [confirming, setConfirming] = useState(false);
    const [filter, setFilter] = useState('');
    const form = useForm<RunForm>({
        server_ids: preselected,
        env: Object.keys(recipe.variables).map((name) => ({ name, value: '' })),
        timeout: defaultTimeout,
    });

    const origin = preselected.length === 1 ? servers.find((server) => server.id === preselected[0]) : undefined;
    const path = typeof window === 'undefined' ? '/settings/recipes' : window.location.pathname + window.location.search;
    const breadcrumbs: BreadcrumbItem[] = origin
        ? [
              { title: 'Infrastructure', href: '/servers' },
              { title: origin.name, href: `/servers/${origin.id}` },
              { title: 'Recipes', href: `/servers/${origin.id}/recipes` },
              { title: `Run ${recipe.name}`, href: path },
          ]
        : [
              { title: 'Recipes', href: '/settings/recipes' },
              { title: `Run ${recipe.name}`, href: path },
          ];

    const visible = useMemo(
        () => servers.filter((server) => `${server.name} ${server.ipv4 ?? ''} ${server.type}`.toLowerCase().includes(filter.toLowerCase())),
        [servers, filter],
    );
    const selected = new Set(form.data.server_ids);
    const allVisibleSelected = visible.length > 0 && visible.every((server) => selected.has(server.id));
    const count = form.data.server_ids.length;

    const toggle = (id: string, checked: boolean) => {
        const next = new Set(form.data.server_ids);
        if (checked) next.add(id);
        else next.delete(id);
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

    const invalidEnv = form.data.env.some((pair) => pair.name.trim() !== '' && !ENV_NAME.test(pair.name.trim()));
    const timeoutError = form.data.timeout < 10 || form.data.timeout > maxTimeout ? `Between 10 and ${maxTimeout} seconds.` : null;

    const submit = () => {
        form.transform((data) => ({ ...data, env: data.env.filter((pair) => pair.name.trim() !== '') }));
        form.post(recipe.run_url, { onFinish: () => setConfirming(false) });
    };

    const envErrors = Object.entries(form.errors)
        .filter(([key]) => key.startsWith('env'))
        .map(([, message]) => message);

    return (
        <AppShell breadcrumbs={breadcrumbs}>
            <Head title={`Run ${recipe.name}`} />
            <div className="grid gap-6">
                <PageHeader
                    title={
                        <span className="flex items-center gap-2">
                            Run {recipe.name}
                            {recipe.builtin && <Tag tone="faint">built-in</Tag>}
                        </span>
                    }
                    description={recipe.description ?? undefined}
                />

                <div className="grid items-start gap-6 lg:grid-cols-2">
                    <Section title="Script" description={`Runs as ${recipe.user} on every selected server in parallel.`} bare>
                        <ScriptBlock script={recipe.script} title={`${recipe.user} · bash`} />
                    </Section>

                    <div className="grid content-start gap-6">
                        <Section
                            title="Servers"
                            description={`${count} selected`}
                            aside={
                                <div className="w-44">
                                    <Input
                                        type="search"
                                        placeholder="Filter"
                                        value={filter}
                                        onChange={(event) => setFilter(event.target.value)}
                                        aria-label="Filter servers"
                                        prefix={<Search />}
                                    />
                                </div>
                            }
                        >
                            {servers.length === 0 ? (
                                <p className="text-fg-muted text-sm">No servers in this organization.</p>
                            ) : (
                                <div className="grid gap-1">
                                    <label className="border-border text-fg flex items-center gap-2.5 border-b px-1 pb-2 text-sm font-medium">
                                        <Checkbox checked={allVisibleSelected} onCheckedChange={(value) => toggleAll(value === true)} />
                                        Select all{filter ? ' matching' : ''}
                                    </label>
                                    <div className="grid max-h-72 gap-0.5 overflow-auto">
                                        {visible.map((server) => (
                                            <label
                                                key={server.id}
                                                className={cn(
                                                    'hover:bg-surface-2 flex cursor-pointer items-center gap-2.5 rounded-md px-1 py-1.5 text-sm transition-colors duration-150',
                                                    selected.has(server.id) && 'bg-surface-2',
                                                )}
                                            >
                                                <Checkbox
                                                    checked={selected.has(server.id)}
                                                    onCheckedChange={(value) => toggle(server.id, value === true)}
                                                />
                                                <StatusDot status={server.status === 'error' ? 'failed' : server.status} size="sm" />
                                                <span className="text-fg font-medium">{server.name}</span>
                                                <span className="text-fg-faint font-mono text-xs">{server.ipv4}</span>
                                                <span className="text-fg-faint ml-auto text-xs">{TYPE_LABELS[server.type] ?? server.type}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {form.errors.server_ids && <p className="text-danger text-xs">{form.errors.server_ids}</p>}
                        </Section>

                        <Section
                            title="Variables & options"
                            description="Exported to the script's environment. Values are not stored in the run history."
                            aside={
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    icon={<Plus />}
                                    onClick={() => form.setData('env', [...form.data.env, { name: '', value: '' }])}
                                >
                                    Add variable
                                </Button>
                            }
                        >
                            {form.data.env.length > 0 && (
                                <div className="grid gap-2">
                                    {form.data.env.map((pair, index) => (
                                        <div key={index} className="flex items-center gap-2">
                                            <Input
                                                value={pair.name}
                                                onChange={(event) => setEnv(index, { name: event.target.value })}
                                                placeholder="NAME"
                                                className="w-40"
                                                mono
                                                aria-label="Variable name"
                                                aria-invalid={pair.name.trim() !== '' && !ENV_NAME.test(pair.name.trim()) ? true : undefined}
                                            />
                                            <Input
                                                value={pair.value}
                                                onChange={(event) => setEnv(index, { value: event.target.value })}
                                                placeholder={recipe.variables[pair.name] ?? 'value'}
                                                mono
                                                aria-label={`Value for ${pair.name || 'variable'}`}
                                            />
                                            <IconButton
                                                label="Remove variable"
                                                icon={<X />}
                                                size="sm"
                                                onClick={() =>
                                                    form.setData(
                                                        'env',
                                                        form.data.env.filter((_, i) => i !== index),
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            )}
                            {invalidEnv && (
                                <p className="text-danger text-xs">Names use letters, digits and underscores and cannot start with a digit.</p>
                            )}
                            {envErrors.map((message) => (
                                <p key={message} className="text-danger text-xs">
                                    {message}
                                </p>
                            ))}
                            <Field label="Timeout (seconds)" error={form.errors.timeout ?? timeoutError} className="max-w-48">
                                <Input
                                    type="number"
                                    min={10}
                                    max={maxTimeout}
                                    value={form.data.timeout}
                                    onChange={(event) => form.setData('timeout', Number(event.target.value))}
                                    className="tabular"
                                />
                            </Field>
                        </Section>
                    </div>
                </div>

                <div className="border-border-strong bg-surface-2 shadow-panel sticky bottom-4 z-20 rounded-xl border">
                    <div className="flex items-center justify-end gap-3 py-2 pr-2 pl-4">
                        <span className="text-fg-muted text-sm">
                            {count === 0 ? 'Select at least one server' : `${count} server${count === 1 ? '' : 's'} · as ${recipe.user}`}
                        </span>
                        <Button
                            variant="primary"
                            icon={<Play />}
                            disabled={count === 0 || invalidEnv || Boolean(timeoutError)}
                            loading={form.processing}
                            onClick={() => setConfirming(true)}
                        >
                            Run on {count} server{count === 1 ? '' : 's'}
                        </Button>
                    </div>
                </div>
            </div>

            <Dialog
                open={confirming}
                onOpenChange={setConfirming}
                size="sm"
                title={`Run ${recipe.name}?`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" icon={<Play />} onClick={submit} loading={form.processing}>
                            Run now
                        </Button>
                    </>
                }
            >
                <p className="text-fg-muted text-sm">
                    The script runs as <span className="text-fg font-mono">{recipe.user}</span> on {count} server{count === 1 ? '' : 's'} in parallel.
                    Changes it makes cannot be undone from Falak.
                </p>
            </Dialog>
        </AppShell>
    );
}
