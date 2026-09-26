import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import SiteLayout, { type SiteHeader } from '@/layouts/site-layout';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { FormEventHandler, useRef } from 'react';

interface Item {
    name: string;
    description: string;
}

interface Props {
    site: SiteHeader;
    script: string;
    defaultScript: string;
    macros: Item[];
    variables: Item[];
    exposedEnvironment: string[];
    can: { update: boolean };
}

export default function DeployScript({ site, script, defaultScript, macros, variables, exposedEnvironment, can }: Props) {
    const form = useForm({ script });
    const editor = useRef<HTMLTextAreaElement>(null);
    const lines = form.data.script.split('\n').length;
    const hasActivate = /\$\{?KILN_ACTIVATE\b/.test(form.data.script);
    const hasFetch = /\$\{?KILN_FETCH\b/.test(form.data.script);

    const insert = (text: string) => {
        const el = editor.current;

        if (!el || !can.update) {
            return;
        }

        const { selectionStart, selectionEnd, value } = el;
        const next = value.slice(0, selectionStart) + text + value.slice(selectionEnd);
        form.setData('script', next);
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(selectionStart + text.length, selectionStart + text.length);
        });
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Tab') {
            event.preventDefault();
            insert('    ');
        }

        if ((event.metaKey || event.ctrlKey) && event.key === 's') {
            event.preventDefault();
            submit();
        }
    };

    const submit = () => form.put(`/sites/${site.id}/deploy-script`, { preserveScroll: true });

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();
        submit();
    };

    return (
        <SiteLayout site={site} title="Deploy script">
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Deploy script</CardTitle>
                        <CardDescription>Bash, run on every server as the site user. Macros expand into the deployment steps.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={onSubmit} className="space-y-3">
                            <div className="flex overflow-hidden rounded-md border bg-neutral-950 font-mono text-xs leading-5 text-neutral-100">
                                <div aria-hidden className="border-r border-neutral-800 px-2 py-2 text-right text-neutral-500 select-none">
                                    {Array.from({ length: lines }, (_, i) => (
                                        <div key={i}>{i + 1}</div>
                                    ))}
                                </div>
                                <textarea
                                    ref={editor}
                                    value={form.data.script}
                                    onChange={(e) => form.setData('script', e.target.value)}
                                    onKeyDown={onKeyDown}
                                    readOnly={!can.update}
                                    spellCheck={false}
                                    wrap="off"
                                    rows={Math.max(16, lines + 2)}
                                    className="flex-1 resize-none bg-transparent px-3 py-2 whitespace-pre outline-none"
                                    aria-label="Deploy script"
                                />
                            </div>
                            <InputError message={form.errors.script} />

                            {(!hasActivate || !hasFetch) && (
                                <p className="flex items-center gap-2 text-sm text-amber-600">
                                    <AlertTriangle className="size-4" />
                                    {!hasFetch && 'Without $KILN_FETCH the release is fetched before the script runs. '}
                                    {!hasActivate && 'Without $KILN_ACTIVATE the release is activated after the script finishes.'}
                                </p>
                            )}

                            {can.update && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button type="submit" disabled={form.processing || !form.isDirty}>
                                        {form.processing && <Loader2 className="animate-spin" />} Save
                                    </Button>
                                    <Button type="button" variant="ghost" onClick={() => form.setData('script', defaultScript)}>
                                        Reset to preset
                                    </Button>
                                    <span className="text-muted-foreground text-xs">⌘S to save · Tab indents</span>
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Macros</CardTitle>
                            <CardDescription>{can.update ? 'Click to insert at the cursor.' : 'Available in the script.'}</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {macros.map((macro) => (
                                <button
                                    type="button"
                                    key={macro.name}
                                    onClick={() => insert(`$${macro.name}\n`)}
                                    className="hover:bg-muted block w-full rounded-md p-2 text-left"
                                >
                                    <code className="text-xs font-semibold">${macro.name}</code>
                                    <span className="text-muted-foreground block text-xs">{macro.description}</span>
                                </button>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Variables</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            {variables.map((variable) => (
                                <button
                                    type="button"
                                    key={variable.name}
                                    onClick={() => insert(`$${variable.name}`)}
                                    className="hover:bg-muted block w-full rounded px-2 py-1 text-left"
                                    title={variable.description}
                                >
                                    <code className="text-xs">${variable.name}</code>
                                    <span className="text-muted-foreground ml-2 text-xs">{variable.description}</span>
                                </button>
                            ))}
                            {exposedEnvironment.length > 0 && (
                                <p className="text-muted-foreground pt-2 text-xs">
                                    Also exported from the environment: <span className="font-mono">{exposedEnvironment.join(', ')}</span>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </SiteLayout>
    );
}
