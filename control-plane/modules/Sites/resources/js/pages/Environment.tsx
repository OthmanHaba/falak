import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import SiteLayout, { type SiteHeader } from '@/layouts/site-layout';
import { router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Eye, EyeOff, History, Loader2, Lock } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';
import { requestJson } from '../components/site-ui';

interface Props {
    site: SiteHeader;
    current: { version: number; keys: string[]; exposed: string[] } | null;
    versions: { version: number; changed_keys: string[]; created_by: string | null; created_at: string }[];
    can: { reveal: boolean; update: boolean };
}

interface Revealed {
    version: number;
    content: string;
    exposed: string[];
}

const KEY_LINE = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/;

export default function Environment({ site, current, versions, can }: Props) {
    const [revealed, setRevealed] = useState<Revealed | null>(null);
    const [revealing, setRevealing] = useState(false);
    const [revealError, setRevealError] = useState<string | null>(null);
    const form = useForm<{ content: string; exposed: string[]; base_version: number | null }>({ content: '', exposed: [], base_version: null });

    const reveal = async () => {
        setRevealing(true);
        setRevealError(null);

        try {
            const body = await requestJson<{ data: Revealed }>(`/sites/${site.id}/environment/reveal`, 'POST', {});
            setRevealed(body.data);
            form.setData({ content: body.data.content, exposed: body.data.exposed, base_version: body.data.version });
        } catch (e) {
            setRevealError(e instanceof Error ? e.message : 'Could not reveal the environment');
        } finally {
            setRevealing(false);
        }
    };

    const hide = () => {
        setRevealed(null);
        form.reset();
    };

    // Keys currently in the editor, for the "expose to deploy script" toggles.
    const editorKeys = useMemo(
        () =>
            Array.from(
                new Set(
                    form.data.content
                        .split('\n')
                        .map((line) => line.match(KEY_LINE)?.[1])
                        .filter((key): key is string => Boolean(key)),
                ),
            ),
        [form.data.content],
    );

    const toggleExposed = (key: string, checked: boolean) =>
        form.setData('exposed', checked ? [...form.data.exposed, key] : form.data.exposed.filter((item) => item !== key));

    const save: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, exposed: data.exposed.filter((key) => editorKeys.includes(key)) }));
        form.put(`/sites/${site.id}/environment`, {
            preserveScroll: true,
            onSuccess: () => hide(),
        });
    };

    const restore = (version: number) => {
        if (window.confirm(`Restore version ${version}? It is saved as a new version.`)) {
            router.post(`/sites/${site.id}/environment/versions/${version}/restore`, {}, { preserveScroll: true });
        }
    };

    return (
        <SiteLayout site={site} title="Environment">
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
                        <div>
                            <CardTitle>Environment variables</CardTitle>
                            <CardDescription>
                                Written to shared/.env on every deploy. Values are encrypted at rest and every reveal is audited.
                            </CardDescription>
                        </div>
                        {can.reveal &&
                            (revealed ? (
                                <Button variant="outline" size="sm" onClick={hide}>
                                    <EyeOff /> Hide
                                </Button>
                            ) : (
                                <Button variant="outline" size="sm" onClick={() => void reveal()} disabled={revealing}>
                                    {revealing ? <Loader2 className="animate-spin" /> : <Eye />} {can.update ? 'Reveal & edit' : 'Reveal'}
                                </Button>
                            ))}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {revealError && <p className="text-sm text-red-600">{revealError}</p>}

                        {!revealed && (
                            <>
                                {current ? (
                                    <div className="divide-y rounded-md border font-mono text-sm">
                                        {current.keys.length === 0 && <p className="text-muted-foreground p-3 font-sans">No variables.</p>}
                                        {current.keys.map((key) => (
                                            <div key={key} className="flex items-center gap-2 px-3 py-1.5">
                                                <span className="flex-1 truncate">{key}</span>
                                                {current.exposed.includes(key) && <Badge variant="secondary">deploy script</Badge>}
                                                <span className="text-muted-foreground tracking-widest">••••••</span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-sm">No environment yet.</p>
                                )}
                                {!can.reveal && (
                                    <p className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <Lock className="size-3.5" /> You need the “Reveal site environment variables” permission to see values.
                                    </p>
                                )}
                            </>
                        )}

                        {revealed && (
                            <form onSubmit={save} className="space-y-4">
                                <Textarea
                                    value={form.data.content}
                                    onChange={(e) => form.setData('content', e.target.value)}
                                    readOnly={!can.update}
                                    spellCheck={false}
                                    autoComplete="off"
                                    className="min-h-80 font-mono text-xs leading-relaxed"
                                    aria-label="Environment file"
                                />
                                <InputError message={form.errors.content} />

                                {editorKeys.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">Expose to deploy script</p>
                                        <p className="text-muted-foreground text-xs">
                                            Exposed variables are exported while the deploy script runs (they are always in .env).
                                        </p>
                                        <div className="grid gap-1 sm:grid-cols-2">
                                            {editorKeys.map((key) => (
                                                <label key={key} className="flex items-center gap-2 font-mono text-xs">
                                                    <Checkbox
                                                        checked={form.data.exposed.includes(key)}
                                                        disabled={!can.update}
                                                        onCheckedChange={(checked) => toggleExposed(key, checked === true)}
                                                    />
                                                    {key}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {can.update && (
                                    <div className="flex items-center gap-2">
                                        <Button type="submit" disabled={form.processing}>
                                            {form.processing && <Loader2 className="animate-spin" />} Save as version {(current?.version ?? 0) + 1}
                                        </Button>
                                        <span className="text-muted-foreground text-xs">Takes effect on the next deploy.</span>
                                    </div>
                                )}
                            </form>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <History className="size-4" /> Versions
                        </CardTitle>
                        <CardDescription>Deployments pin the version they were built with.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {versions.map((version) => (
                            <div key={version.version} className="space-y-1 text-sm">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">v{version.version}</span>
                                    {version.version === current?.version && <Badge variant="secondary">current</Badge>}
                                    <span className="text-muted-foreground text-xs">
                                        {formatDistanceToNow(new Date(version.created_at), { addSuffix: true })}
                                        {version.created_by && ` · ${version.created_by}`}
                                    </span>
                                    {can.update && version.version !== current?.version && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="ml-auto h-6 px-2 text-xs"
                                            onClick={() => restore(version.version)}
                                        >
                                            Restore
                                        </Button>
                                    )}
                                </div>
                                {version.changed_keys.length > 0 && (
                                    <p className="text-muted-foreground font-mono text-[11px] break-all">{version.changed_keys.join(', ')}</p>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </SiteLayout>
    );
}
