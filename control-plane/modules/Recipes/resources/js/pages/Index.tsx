import { Button } from '@/components/kiln/button';
import { CodeBlock } from '@/components/kiln/code-block';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { DataTable } from '@/components/kiln/data-table';
import { Dialog } from '@/components/kiln/dialog';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { RelativeTime } from '@/components/kiln/relative-time';
import { Section } from '@/components/kiln/section';
import { StatusBadge } from '@/components/kiln/status';
import { Tag } from '@/components/kiln/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { Link, router, useForm } from '@inertiajs/react';
import { Copy, Eye, History, Pencil, Play, Plus, ScrollText, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEventHandler } from 'react';
import { ScriptEditor } from '../components/script-editor';
import { type BuiltinRecipeRow, type RecipeRow, type RunStatus, type RunSummary } from '../types';

interface Props {
    recipes: RecipeRow[];
    builtins: BuiltinRecipeRow[];
    recentRuns: RunSummary[];
    can: { manage: boolean; run: boolean };
}

interface RecipeForm {
    name: string;
    description: string;
    script: string;
    user: string;
}

const EMPTY: RecipeForm = { name: '', description: '', script: '#!/usr/bin/env bash\nset -euo pipefail\n\n', user: 'root' };

const RUN_STATUS: Record<RunStatus, { status: string; label: string }> = {
    pending: { status: 'queued', label: 'Queued' },
    running: { status: 'running', label: 'Running' },
    succeeded: { status: 'succeeded', label: 'Succeeded' },
    failed: { status: 'failed', label: 'Failed' },
    partial: { status: 'degraded', label: 'Partial' },
};

function RunStatusTag({ status }: { status: RunStatus }) {
    const spec = RUN_STATUS[status] ?? { status, label: status };

    return <StatusBadge status={spec.status} label={spec.label} />;
}

export default function Index({ recipes, builtins, recentRuns, can }: Props) {
    const [editing, setEditing] = useState<RecipeRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<RecipeRow | null>(null);
    const [viewing, setViewing] = useState<BuiltinRecipeRow | null>(null);
    const [copying, setCopying] = useState<string | null>(null);
    const form = useForm<RecipeForm>(EMPTY);
    const userError = form.data.user && !/^[a-z_][a-z0-9_-]{0,31}$/.test(form.data.user) ? 'A Linux user name, e.g. root or forge.' : undefined;
    const scriptBlank = form.data.script.trim() === '' || form.data.script.trim() === '#!/usr/bin/env bash\nset -euo pipefail';

    useEffect(() => {
        if (can.manage && new URLSearchParams(window.location.search).get('create') === '1') {
            form.setData(EMPTY);
            setEditing('new');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only on first visit
    }, [can.manage]);

    const open = (recipe: RecipeRow | 'new') => {
        form.clearErrors();
        form.setData(
            recipe === 'new' ? EMPTY : { name: recipe.name, description: recipe.description ?? '', script: recipe.script, user: recipe.user },
        );
        setEditing(recipe);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(route('recipes.store'), options);
        } else if (editing) {
            form.put(route('recipes.update', editing.id), options);
        }
    };

    const destroy = () =>
        new Promise<void>((resolve) => {
            if (!deleting) return resolve();
            router.delete(route('recipes.destroy', deleting.id), {
                preserveScroll: true,
                onSuccess: () => setDeleting(null),
                onFinish: () => resolve(),
            });
        });

    const copy = (builtin: BuiltinRecipeRow) => {
        setCopying(builtin.key);
        router.post(route('recipes.builtin.copy', builtin.key), {}, { preserveScroll: true, onFinish: () => setCopying(null) });
    };

    return (
        <SettingsLayout
            title="Recipes"
            description="Saved bash scripts your team runs on many servers at once — patching, log collection, one-off maintenance. Each run is recorded with per-server output."
            actions={
                <>
                    <Button asChild>
                        <Link href={route('recipes.runs.index')}>
                            <History /> Run history
                        </Link>
                    </Button>
                    {can.manage && recipes.length > 0 && (
                        <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                            New recipe
                        </Button>
                    )}
                </>
            }
            wide
        >
            <Section title="Your recipes" bare>
                <DataTable
                    label="Recipes"
                    rows={recipes}
                    rowKey={(recipe) => recipe.id}
                    onRowClick={can.manage ? (recipe) => open(recipe) : undefined}
                    empty={{
                        icon: <ScrollText />,
                        title: 'No recipes yet',
                        description: 'Write a script, or copy one of the built-in recipes below and adapt it.',
                        action: can.manage && (
                            <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                                New recipe
                            </Button>
                        ),
                    }}
                    columns={[
                        {
                            id: 'name',
                            header: 'Recipe',
                            sortValue: (recipe) => recipe.name,
                            cell: (recipe) => (
                                <span className="grid min-w-0 py-1.5">
                                    <span className="truncate font-medium">{recipe.name}</span>
                                    {recipe.description && <span className="text-fg-faint truncate text-xs">{recipe.description}</span>}
                                </span>
                            ),
                        },
                        {
                            id: 'user',
                            header: 'Runs as',
                            hideOnMobile: true,
                            cell: (recipe) => <Tag mono>{recipe.user}</Tag>,
                        },
                        {
                            id: 'updated',
                            header: 'Updated',
                            hideOnMobile: true,
                            sortValue: (recipe) => recipe.updated_at,
                            cell: (recipe) => <RelativeTime value={recipe.updated_at} className="text-fg-muted" />,
                        },
                        ...(can.run
                            ? [
                                  {
                                      id: 'run',
                                      header: <span className="sr-only">Run</span>,
                                      align: 'right' as const,
                                      cell: (recipe: RecipeRow) => (
                                          <Button asChild size="sm" variant="ghost" onClick={(event) => event.stopPropagation()}>
                                              <Link href={route('recipes.run', recipe.id)} aria-label={`Run ${recipe.name}`}>
                                                  <Play />
                                                  <span className="hidden sm:inline">Run</span>
                                              </Link>
                                          </Button>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                    rowActions={
                        can.manage
                            ? (recipe) => [
                                  ...(can.run ? [{ label: 'Run on servers…', icon: <Play />, href: route('recipes.run', recipe.id) }] : []),
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => open(recipe) },
                                  { type: 'separator' },
                                  { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(recipe) },
                              ]
                            : undefined
                    }
                />
            </Section>

            <Section
                title="Built-in recipes"
                description="Maintained by Kiln. Run them as they are, or copy one into your library to change it."
                bare
            >
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {builtins.map((builtin) => (
                        <div key={builtin.key} className="border-border bg-surface-1 flex flex-col gap-2 rounded-lg border p-4">
                            <div className="flex items-start justify-between gap-2">
                                <span className="text-fg text-sm font-medium">{builtin.name}</span>
                                <Tag mono>{builtin.user}</Tag>
                            </div>
                            <p className="text-fg-muted flex-1 text-xs">{builtin.description}</p>
                            {Object.keys(builtin.variables).length > 0 && (
                                <div className="flex flex-wrap gap-1">
                                    {Object.keys(builtin.variables).map((name) => (
                                        <Tag key={name} mono tone="accent">
                                            ${name}
                                        </Tag>
                                    ))}
                                </div>
                            )}
                            <div className="-ml-2 flex flex-wrap gap-1 pt-1">
                                <Button size="sm" variant="ghost" icon={<Eye />} onClick={() => setViewing(builtin)}>
                                    Script
                                </Button>
                                {can.run && (
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={route('recipes.builtin.run', builtin.key)}>
                                            <Play /> Run
                                        </Link>
                                    </Button>
                                )}
                                {can.manage && (
                                    <Button size="sm" variant="ghost" icon={<Copy />} loading={copying === builtin.key} onClick={() => copy(builtin)}>
                                        Copy
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </Section>

            {recentRuns.length > 0 && (
                <Section
                    title="Recent runs"
                    aside={
                        <Button asChild size="sm" variant="ghost">
                            <Link href={route('recipes.runs.index')}>View all</Link>
                        </Button>
                    }
                    bare
                >
                    <DataTable
                        label="Recent recipe runs"
                        rows={recentRuns}
                        rowKey={(run) => run.id}
                        onRowClick={(run) => router.visit(route('recipes.runs.show', run.id))}
                        columns={[
                            {
                                id: 'recipe',
                                header: 'Recipe',
                                cell: (run) => (
                                    <Link href={route('recipes.runs.show', run.id)} className="font-medium hover:underline">
                                        {run.recipe_name}
                                    </Link>
                                ),
                            },
                            { id: 'status', header: 'Status', cell: (run) => <RunStatusTag status={run.status} /> },
                            {
                                id: 'servers',
                                header: 'Servers',
                                hideOnMobile: true,
                                cell: (run) => (
                                    <span className="text-fg-muted">
                                        {run.succeeded}/{run.servers} succeeded
                                    </span>
                                ),
                            },
                            {
                                id: 'created',
                                header: 'Started',
                                align: 'right',
                                cell: (run) => <RelativeTime value={run.created_at} className="text-fg-muted" />,
                            },
                        ]}
                    />
                </Section>
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                size="lg"
                title={editing === 'new' ? 'New recipe' : 'Edit recipe'}
                description="Runs with /bin/bash. Variables supplied when you run it are exported as environment variables."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="recipe-form" loading={form.processing} disabled={Boolean(userError)}>
                            Save recipe
                        </Button>
                    </>
                }
            >
                <form id="recipe-form" onSubmit={submit} className="grid gap-4">
                    <div className="grid items-start gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                        <Field label="Name" error={form.errors.name} required>
                            <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Rotate logs" />
                        </Field>
                        <Field label="Run as" error={form.errors.user ?? userError} required>
                            <Input mono value={form.data.user} onChange={(event) => form.setData('user', event.target.value)} placeholder="root" />
                        </Field>
                    </div>
                    <Field label="Description" error={form.errors.description}>
                        <Input
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            placeholder="What it does and when to use it"
                        />
                    </Field>
                    <Field
                        label="Script"
                        error={form.errors.script}
                        hint={scriptBlank ? 'Add at least one command.' : 'Tab indents · Shift+Tab outdents'}
                        required
                    >
                        <ScriptEditor value={form.data.script} onChange={(value) => form.setData('script', value)} />
                    </Field>
                </form>
            </Dialog>

            <Dialog
                open={viewing !== null}
                onOpenChange={(value) => !value && setViewing(null)}
                size="lg"
                title={viewing?.name ?? ''}
                description={viewing?.description}
                footer={
                    viewing && (
                        <>
                            {can.manage && (
                                <Button
                                    icon={<Copy />}
                                    loading={copying === viewing.key}
                                    onClick={() => {
                                        copy(viewing);
                                        setViewing(null);
                                    }}
                                >
                                    Copy to my recipes
                                </Button>
                            )}
                            {can.run && (
                                <Button asChild variant="primary">
                                    <Link href={route('recipes.builtin.run', viewing.key)}>
                                        <Play /> Run on servers
                                    </Link>
                                </Button>
                            )}
                        </>
                    )
                }
            >
                {viewing && <CodeBlock title={`runs as ${viewing.user}`} code={viewing.script} maxHeight={420} />}
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(value) => !value && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}`}
                description="Run history keeps a copy of the script that ran."
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete recipe"
                onConfirm={destroy}
            />
        </SettingsLayout>
    );
}
