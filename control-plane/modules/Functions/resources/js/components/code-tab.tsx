import { Button, Callout, Input, RelativeTime, Skeleton, Tag, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { GitCompareArrows, Rocket, RotateCcw, SquareFunction } from 'lucide-react';
import { Suspense, lazy, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { functionUrl, type FunctionFiles, type FunctionState, type FunctionVersionSummary } from '../types';

const CodeEditor = lazy(() => import('./code-editor'));
const DiffView = lazy(() => import('./code-editor').then((module) => ({ default: module.DiffView })));

const AUTOSAVE_MS = 1200;

function sameFiles(a: FunctionFiles, b: FunctionFiles): boolean {
    const keys = Object.keys(a);

    return keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key]);
}

function bytes(files: FunctionFiles): number {
    return Object.entries(files).reduce((sum, [path, content]) => sum + path.length + new TextEncoder().encode(content).length, 0);
}

function kb(value: number): string {
    return value < 1024 ? `${value} B` : `${(value / 1024).toFixed(1)} KB`;
}

interface Conflict {
    head: FunctionVersionSummary & { files: FunctionFiles };
    message: string;
}

/**
 * Code tab of a function: the editor (draft autosaved per user), Deploy (⌘S) with an optional message, and the
 * conflict view when a teammate deployed after this editor loaded its version.
 */
export function CodeTab({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const state = useJson<FunctionState>(functionUrl(siteId));
    const data = state.data;
    const [files, setFiles] = useState<FunctionFiles | null>(null);
    const [base, setBase] = useState<string | null>(null);
    const [message, setMessage] = useState('');
    const [deploying, setDeploying] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const [conflict, setConflict] = useState<Conflict | null>(null);
    const loadedFor = useRef<string | null>(null);

    // Start from the user's draft, else the newest version (once per function).
    useEffect(() => {
        if (!data || loadedFor.current === data.site.id) return;
        loadedFor.current = data.site.id;
        setFiles(data.draft?.files ?? data.head?.files ?? { [data.entrypoint]: '' });
        setBase(data.draft ? data.draft.base_version_id : (data.head?.id ?? null));
        setSavedAt(data.draft?.updated_at ?? null);
    }, [data]);

    const headFiles = data?.head?.files ?? {};
    const dirty = files !== null && !sameFiles(files, headFiles);
    const stale = data?.head && base !== null && base !== data.head.id;
    const size = files ? bytes(files) : 0;
    const tooBig = data ? size > data.limits.max_bytes : false;
    const canEdit = data?.can.edit ?? false;

    // Autosave the draft (or drop it once the code matches the newest version again).
    useEffect(() => {
        if (!data || !files || !canEdit || conflict) return;
        const timer = window.setTimeout(async () => {
            try {
                if (dirty) {
                    const response = await requestJson<{ data: { updated_at: string } }>(functionUrl(siteId, '/draft'), 'PUT', {
                        files,
                        base_version_id: base,
                    });
                    setSavedAt(response.data.updated_at);
                } else if (savedAt) {
                    await requestJson(functionUrl(siteId, '/draft'), 'DELETE');
                    setSavedAt(null);
                }
            } catch {
                // Autosave is best effort; Deploy reports errors.
            }
        }, AUTOSAVE_MS);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [files, base, dirty, canEdit, conflict, siteId]);

    const deploy = useCallback(
        async (force = false) => {
            if (!files || !data?.can.deploy || deploying || tooBig) return;
            setDeploying(true);
            try {
                const response = await requestJson<{
                    data: { version: FunctionVersionSummary; created: boolean; deployment_id: string | null };
                    warnings: string[];
                }>(functionUrl(siteId, '/deploy'), 'POST', { files, message: message.trim() || null, base_version_id: base, force });
                const { version, created, deployment_id } = response.data;
                response.warnings.forEach((warning) => toast.warning(warning));
                toast.success(
                    created ? `v${version.number} deploying` : `Redeploying v${version.number}`,
                    created ? undefined : 'No changes since the newest version.',
                );
                setMessage('');
                setBase(version.id);
                setSavedAt(null);
                setConflict(null);
                await state.reload();
                ctx.refresh();
                if (deployment_id) ctx.openLayer('deployment', deployment_id);
            } catch (error) {
                if (error instanceof HttpError && error.status === 409) {
                    const payload = await requestJson<FunctionState>(functionUrl(siteId)).catch(() => null);
                    if (payload?.head) setConflict({ head: payload.head, message: error.message });
                } else {
                    toast.error('Deploy failed', errorMessage(error));
                }
            } finally {
                setDeploying(false);
            }
        },
        [files, data, deploying, tooBig, siteId, message, base, state, ctx],
    );

    const entry = data?.entrypoint ?? 'index.ts';
    const code = files?.[entry] ?? '';
    const live = data?.live;
    const status = useMemo(() => {
        if (!data) return null;
        if (!live) return <Tag tone="neutral">Not deployed yet</Tag>;

        return (
            <Tag tone={live.id === data.head?.id ? 'success' : 'warning'}>
                Live v{live.number} · {live.short_hash}
            </Tag>
        );
    }, [data, live]);

    if (state.error)
        return (
            <Callout tone="danger" title="Could not load the function">
                {state.error}
            </Callout>
        );
    if (!data || files === null) return <Skeleton className="h-[480px]" />;

    if (conflict) {
        return (
            <div className="grid gap-3">
                <Callout tone="warning" title="Someone deployed a newer version">
                    {conflict.message} Left: v{conflict.head.number}
                    {conflict.head.author ? ` by ${conflict.head.author}` : ''}; right: your code.
                </Callout>
                <Suspense fallback={<Skeleton className="h-[420px]" />}>
                    <DiffView
                        path={entry}
                        original={conflict.head.files[entry] ?? ''}
                        modified={code}
                        language={data.runtime.language}
                        className="border-border h-[420px] overflow-hidden rounded-md border"
                    />
                </Suspense>
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button
                        variant="ghost"
                        onClick={() => {
                            setFiles(conflict.head.files);
                            setBase(conflict.head.id);
                            setConflict(null);
                        }}
                    >
                        Use v{conflict.head.number}, drop my changes
                    </Button>
                    <Button
                        onClick={() => {
                            setBase(conflict.head.id);
                            setConflict(null);
                        }}
                    >
                        Keep editing mine
                    </Button>
                    <Button variant="primary" icon={<Rocket />} loading={deploying} disabled={!data.can.deploy} onClick={() => deploy(true)}>
                        Deploy mine over v{conflict.head.number}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="text-fg inline-flex items-center gap-1.5 font-medium">
                    <SquareFunction className="size-4" aria-hidden /> {data.runtime.label}
                </span>
                <span className="text-fg-faint font-mono">{entry}</span>
                {status}
                {data.head && (
                    <span className="text-fg-muted">
                        newest v{data.head.number}
                        {data.head.author ? ` by ${data.head.author}` : ''} <RelativeTime value={data.head.created_at} />
                    </span>
                )}
                <span className="ml-auto flex items-center gap-2">
                    {dirty && <Tag tone="warning">Unsaved changes{savedAt ? ' · draft saved' : ''}</Tag>}
                    <span className={tooBig ? 'text-danger' : 'text-fg-faint'}>
                        {kb(size)} / {kb(data.limits.max_bytes)}
                    </span>
                </span>
            </div>

            {stale && (
                <Callout tone="warning" title={`v${data.head!.number} was deployed after your draft started`}>
                    Deploying will show the differences first.
                </Callout>
            )}
            {!canEdit && (
                <Callout tone="info" title="Read only">
                    You can view this function’s code but not change it.
                </Callout>
            )}

            <Suspense fallback={<Skeleton className="h-[480px]" />}>
                <CodeEditor
                    path={`${data.site.slug}/${entry}`}
                    value={code}
                    language={data.runtime.language}
                    readOnly={!canEdit}
                    onChange={(value) => setFiles((current) => ({ ...(current ?? {}), [entry]: value }))}
                    onSave={() => deploy()}
                    className="border-border h-[480px] overflow-hidden rounded-md border"
                />
            </Suspense>

            <div className="flex flex-wrap items-center gap-2">
                <Input
                    className="min-w-0 flex-1"
                    placeholder={dirty ? 'What changed? (optional)' : 'No changes'}
                    value={message}
                    maxLength={500}
                    disabled={!data.can.deploy}
                    onChange={(event) => setMessage(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') deploy();
                    }}
                    aria-label="Deploy message"
                />
                {dirty && (
                    <Button
                        variant="ghost"
                        icon={<RotateCcw />}
                        onClick={() => {
                            setFiles(headFiles);
                            setBase(data.head?.id ?? null);
                        }}
                    >
                        Discard
                    </Button>
                )}
                {dirty && data.head && (
                    <Button variant="ghost" icon={<GitCompareArrows />} onClick={() => ctx.open('versions', String(data.head!.number))}>
                        Compare
                    </Button>
                )}
                <Button variant="primary" icon={<Rocket />} loading={deploying} disabled={!data.can.deploy || tooBig} onClick={() => deploy()}>
                    {dirty ? 'Deploy' : 'Redeploy'} <span className="text-on-accent/70 ml-1 text-[11px]">⌘S</span>
                </Button>
            </div>
        </div>
    );
}
