import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Copy, Eye, History, Pencil, Play, Plus, ScrollText, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';
import { RunStatusBadge, ScriptBlock } from '../components/run-ui';
import { type BuiltinRecipeRow, type RecipeRow, type RunSummary } from '../types';

interface Props {
    recipes: RecipeRow[];
    builtins: BuiltinRecipeRow[];
    recentRuns: RunSummary[];
    can: { manage: boolean; run: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Recipes', href: '/recipes' }];

interface RecipeForm {
    name: string;
    description: string;
    script: string;
    user: string;
}

const EMPTY: RecipeForm = { name: '', description: '', script: '#!/usr/bin/env bash\nset -euo pipefail\n\n', user: 'root' };

export default function Index({ recipes, builtins, recentRuns, can }: Props) {
    const [editing, setEditing] = useState<RecipeRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<RecipeRow | null>(null);
    const [viewing, setViewing] = useState<BuiltinRecipeRow | null>(null);
    const form = useForm<RecipeForm>(EMPTY);

    useEffect(() => {
        if (can.manage && new URLSearchParams(window.location.search).get('create') === '1') {
            setEditing('new');
        }
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

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('recipes.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    const copy = (builtin: BuiltinRecipeRow) => router.post(route('recipes.builtin.copy', builtin.key), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recipes" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Recipes" description="Saved bash scripts you can run on many servers at once" />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('recipes.runs.index')}>
                                <History /> Run history
                            </Link>
                        </Button>
                        {can.manage && (
                            <Button onClick={() => open('new')}>
                                <Plus /> New recipe
                            </Button>
                        )}
                    </div>
                </div>

                {recipes.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <ScrollText className="text-muted-foreground size-10" />
                            <p className="font-medium">No recipes yet</p>
                            <p className="text-muted-foreground text-sm">Write a script, or start from one of the built-in recipes below.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="py-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Runs as</TableHead>
                                    <TableHead>Updated</TableHead>
                                    <TableHead className="w-40" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recipes.map((recipe) => (
                                    <TableRow key={recipe.id}>
                                        <TableCell>
                                            <div className="font-medium">{recipe.name}</div>
                                            {recipe.description && <div className="text-muted-foreground text-xs">{recipe.description}</div>}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{recipe.user}</TableCell>
                                        <TableCell className="text-muted-foreground text-sm">
                                            {formatDistanceToNow(new Date(recipe.updated_at), { addSuffix: true })}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                {can.run && (
                                                    <Button variant="ghost" size="icon" asChild aria-label={`Run ${recipe.name}`}>
                                                        <Link href={route('recipes.run', recipe.id)}>
                                                            <Play />
                                                        </Link>
                                                    </Button>
                                                )}
                                                {can.manage && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => open(recipe)}
                                                            aria-label={`Edit ${recipe.name}`}
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => setDeleting(recipe)}
                                                            aria-label={`Delete ${recipe.name}`}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}

                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">Built-in recipes</h3>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {builtins.map((builtin) => (
                            <Card key={builtin.key} className="gap-3">
                                <CardHeader>
                                    <CardTitle className="text-base">{builtin.name}</CardTitle>
                                    <CardDescription>{builtin.description}</CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" onClick={() => setViewing(builtin)}>
                                        <Eye /> Script
                                    </Button>
                                    {can.run && (
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={route('recipes.builtin.run', builtin.key)}>
                                                <Play /> Run
                                            </Link>
                                        </Button>
                                    )}
                                    {can.manage && (
                                        <Button size="sm" variant="ghost" onClick={() => copy(builtin)}>
                                            <Copy /> Copy
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>

                {recentRuns.length > 0 && (
                    <section className="space-y-3">
                        <h3 className="text-sm font-semibold">Recent runs</h3>
                        <Card className="py-0">
                            <Table>
                                <TableBody>
                                    {recentRuns.map((run) => (
                                        <TableRow key={run.id}>
                                            <TableCell>
                                                <Link href={route('recipes.runs.show', run.id)} className="font-medium hover:underline">
                                                    {run.recipe_name}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {run.servers} server{run.servers === 1 ? '' : 's'}
                                            </TableCell>
                                            <TableCell>
                                                <RunStatusBadge status={run.status} />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-right text-sm">
                                                {formatDistanceToNow(new Date(run.created_at), { addSuffix: true })}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </section>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(value) => !value && setEditing(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editing === 'new' ? 'New recipe' : 'Edit recipe'}</DialogTitle>
                            <DialogDescription>
                                The script runs with /bin/bash. Variables supplied at run time are exported as environment variables.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                            <div className="grid gap-2">
                                <Label htmlFor="recipe-name">Name</Label>
                                <Input id="recipe-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="recipe-user">Run as user</Label>
                                <Input
                                    id="recipe-user"
                                    value={form.data.user}
                                    onChange={(e) => form.setData('user', e.target.value)}
                                    className="font-mono"
                                    placeholder="root"
                                />
                                <InputError message={form.errors.user} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="recipe-description">Description</Label>
                            <Input
                                id="recipe-description"
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                            />
                            <InputError message={form.errors.description} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="recipe-script">Script</Label>
                            <Textarea
                                id="recipe-script"
                                value={form.data.script}
                                onChange={(e) => form.setData('script', e.target.value)}
                                className="min-h-64 font-mono text-xs"
                                spellCheck={false}
                            />
                            <InputError message={form.errors.script} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>Save recipe</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={viewing !== null} onOpenChange={(value) => !value && setViewing(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{viewing?.name}</DialogTitle>
                        <DialogDescription>
                            Runs as <span className="font-mono">{viewing?.user}</span>
                            {viewing && Object.keys(viewing.variables).length > 0 && <> · variables: {Object.keys(viewing.variables).join(', ')}</>}
                        </DialogDescription>
                    </DialogHeader>
                    {viewing && <ScriptBlock script={viewing.script} />}
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>Run history keeps a copy of the script.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            Delete recipe
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
