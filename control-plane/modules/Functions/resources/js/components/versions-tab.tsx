import { Button, Callout, Dialog, EmptyState, RelativeTime, Segmented, Skeleton, SkeletonRows, Tag, toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { History, PencilLine, Rocket } from 'lucide-react';
import { Suspense, lazy, useState } from 'react';
import { functionUrl, type FunctionFiles, type FunctionState, type FunctionVersionSummary } from '../types';

const CodeEditor = lazy(() => import('./code-editor'));
const DiffView = lazy(() => import('./code-editor').then((module) => ({ default: module.DiffView })));

type View = 'diff' | 'code';

/**
 * Versions tab: every deployed state of the code (newest first), its diff against the live version, "Deploy this
 * version" (rollback) and "Restore to editor".
 */
export function VersionsTab({ ctx }: ServiceTabProps) {
    const siteId = ctx.service.ref_id;
    const state = useJson<FunctionState>(functionUrl(siteId));
    const versions = useJson<FunctionVersionSummary[]>(functionUrl(siteId, '/versions'));
    const selectedNumber = ctx.item ? Number(ctx.item) : (versions.data?.[0]?.number ?? null);
    const selected = useJson<FunctionVersionSummary & { entrypoint: string; files: FunctionFiles }>(
        selectedNumber ? functionUrl(siteId, `/versions/${selectedNumber}`) : null,
    );
    const live = useJson<FunctionVersionSummary & { files: FunctionFiles }>(
        state.data?.live ? functionUrl(siteId, `/versions/${state.data.live.number}`) : null,
    );
    const [view, setView] = useState<View>('diff');
    const [confirm, setConfirm] = useState<FunctionVersionSummary | null>(null);
    const [busy, setBusy] = useState(false);

    const deploy = async (version: FunctionVersionSummary) => {
        try {
            const response = await requestJson<{ data: { deployment_id: string } }>(
                functionUrl(siteId, `/versions/${version.number}/deploy`),
                'POST',
            );
            toast.success(`Deploying v${version.number}`);
            ctx.refresh();
            ctx.openLayer('deployment', response.data.deployment_id);
        } catch (error) {
            toast.error('Deploy failed', errorMessage(error));
        }
    };

    const restore = async (version: FunctionVersionSummary & { files: FunctionFiles }) => {
        try {
            await requestJson(functionUrl(siteId, '/draft'), 'PUT', { files: version.files, base_version_id: state.data?.head?.id ?? null });
            toast.success(`v${version.number} is in the editor`, 'Deploy it from the Code tab.');
            ctx.open('code');
        } catch (error) {
            toast.error('Could not restore the version', errorMessage(error));
        }
    };

    if (versions.error)
        return (
            <Callout tone="danger" title="Could not load the versions">
                {versions.error}
            </Callout>
        );
    if (!versions.data || !state.data) return <SkeletonRows rows={5} />;
    if (versions.data.length === 0)
        return <EmptyState icon={<History />} title="No versions yet" description="Deploy from the Code tab to create the first one." />;

    const entry = selected.data?.entrypoint ?? state.data.entrypoint;
    const liveNumber = state.data.live?.number ?? null;
    const headNumber = state.data.head?.number ?? null;

    return (
        <div className="grid gap-3 lg:grid-cols-[260px_minmax(0,1fr)]">
            <ol className="border-border divide-border max-h-[560px] divide-y overflow-auto rounded-md border">
                {versions.data.map((version) => (
                    <li key={version.id}>
                        <button
                            type="button"
                            onClick={() => ctx.open('versions', String(version.number))}
                            className={cn(
                                'hover:bg-surface-2 grid w-full gap-0.5 px-3 py-2 text-left',
                                version.number === selectedNumber && 'bg-surface-2',
                            )}
                        >
                            <span className="flex items-center gap-1.5 text-sm">
                                <span className="text-fg font-medium">v{version.number}</span>
                                <span className="text-fg-faint font-mono text-[11px]">{version.short_hash}</span>
                                {version.number === liveNumber && <Tag tone="success">Live</Tag>}
                                {version.number === headNumber && version.number !== liveNumber && <Tag tone="neutral">Newest</Tag>}
                            </span>
                            <span className="text-fg-muted truncate text-xs">{version.message ?? 'No message'}</span>
                            <span className="text-fg-faint text-[11px]">
                                {version.author ?? 'Kiln'} · <RelativeTime value={version.created_at} />
                            </span>
                        </button>
                    </li>
                ))}
            </ol>

            <div className="grid min-w-0 content-start gap-3">
                {selected.data ? (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-fg text-sm font-medium">
                                v{selected.data.number}
                                {selected.data.message ? `: ${selected.data.message}` : ''}
                            </span>
                            <Segmented
                                size="sm"
                                label="View"
                                value={view}
                                onValueChange={setView}
                                options={[
                                    { value: 'diff', label: liveNumber ? `Diff vs live v${liveNumber}` : 'Diff' },
                                    { value: 'code', label: 'Code' },
                                ]}
                            />
                            <span className="ml-auto flex gap-2">
                                {state.data.can.edit && (
                                    <Button size="sm" variant="ghost" icon={<PencilLine />} onClick={() => restore(selected.data!)}>
                                        Restore to editor
                                    </Button>
                                )}
                                {state.data.can.deploy && selected.data.number !== liveNumber && (
                                    <Button size="sm" variant="primary" icon={<Rocket />} onClick={() => setConfirm(selected.data)}>
                                        Deploy this version
                                    </Button>
                                )}
                            </span>
                        </div>
                        <Suspense fallback={<Skeleton className="h-[480px]" />}>
                            {view === 'diff' ? (
                                <DiffView
                                    path={entry}
                                    original={live.data?.files[entry] ?? ''}
                                    modified={selected.data.files[entry] ?? ''}
                                    language={state.data.runtime.language}
                                    className="border-border h-[480px] overflow-hidden rounded-md border"
                                />
                            ) : (
                                <CodeEditor
                                    path={`${state.data.site.slug}/v${selected.data.number}/${entry}`}
                                    value={selected.data.files[entry] ?? ''}
                                    language={state.data.runtime.language}
                                    readOnly
                                    className="border-border h-[480px] overflow-hidden rounded-md border"
                                />
                            )}
                        </Suspense>
                    </>
                ) : (
                    <Skeleton className="h-[520px]" />
                )}
            </div>

            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Deploy v${confirm?.number}?`}
                description={`The function goes back to v${confirm?.number}${confirm?.message ? ` (“${confirm.message}”)` : ''}. Newer versions stay in the history.`}
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirm(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            icon={<Rocket />}
                            loading={busy}
                            onClick={async () => {
                                if (!confirm) return;
                                setBusy(true);
                                await deploy(confirm);
                                setBusy(false);
                                setConfirm(null);
                            }}
                        >
                            Deploy v{confirm?.number}
                        </Button>
                    </>
                }
            />
        </div>
    );
}
