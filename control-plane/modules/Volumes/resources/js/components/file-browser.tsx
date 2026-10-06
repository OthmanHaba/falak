import { Button, Callout, DataTable, IconButton, Input, RelativeTime, Section, Select, toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { formatBytes } from '@/lib/utils';
import { ChevronRight, Download, File, Folder, FolderOpen, Link2, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { type BrowseEntry, type BrowseResult, type StorageProvider, type VolumeOperation } from '../types';

const PAGE = 200;

/**
 * The read-only file browser (volume.browse): breadcrumbs, one page of a directory, a name search below it, and
 * downloads (a file as is, a folder as .tar.zst) through backup storage. Every listing and download is audited.
 */
export function FileBrowser({ volumeId, providers, maxBytes }: { volumeId: string; providers: StorageProvider[]; maxBytes: number }) {
    const [path, setPath] = useState('');
    const [offset, setOffset] = useState(0);
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState('');
    const [result, setResult] = useState<BrowseResult | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [provider, setProvider] = useState(providers[0]?.id ?? '');
    const [downloading, setDownloading] = useState<string | null>(null);
    const alive = useRef(true);

    useEffect(() => () => void (alive.current = false), []);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ path, offset: String(offset), ...(search ? { search } : {}) });
            const response = await requestJson<{ data: BrowseResult }>(`/volumes/${volumeId}/browse?${params}`);
            if (alive.current) setResult(response.data);
        } catch (e) {
            if (alive.current) setError(e instanceof HttpError ? (Object.values(e.errors)[0] ?? e.message) : errorMessage(e));
        } finally {
            if (alive.current) setLoading(false);
        }
    }, [volumeId, path, offset, search]);

    useEffect(() => void load(), [load]);

    const open = (next: string) => {
        setPath(next);
        setOffset(0);
        setSearch('');
        setQuery('');
    };

    const download = async (target: string) => {
        if (!provider) return;
        setDownloading(target);
        try {
            const started = await requestJson<{ data: VolumeOperation }>(`/volumes/${volumeId}/downloads`, 'POST', {
                path: target,
                storage_provider_id: provider,
            });
            toast.info('Preparing the download', 'The server uploads it to your backup storage first.');
            let operation = started.data;
            // Poll until the agent uploaded it (or failed); give up after ~10 minutes.
            for (let attempt = 0; attempt < 300 && alive.current && (operation.status === 'running' || operation.status === 'pending'); attempt++) {
                await new Promise((resolve) => window.setTimeout(resolve, 2_000));
                operation = (await requestJson<{ data: VolumeOperation }>(`/volumes/operations/${operation.id}`)).data;
            }
            if (operation.status !== 'succeeded') {
                throw new Error(operation.error ?? 'The download is not ready yet; try again later.');
            }
            const link = await requestJson<{ url: string }>(`/volumes/operations/${operation.id}/file`);
            window.location.assign(link.url);
        } catch (e) {
            toast.error('Could not download', errorMessage(e));
        } finally {
            if (alive.current) setDownloading(null);
        }
    };

    const segments = path === '' ? [] : path.split('/');
    const entries = result?.entries ?? [];
    const total = result?.total ?? 0;

    return (
        <Section
            title="Files"
            description={`Read-only. Downloads go through backup storage (up to ${formatBytes(maxBytes)} before compression) and are audited.`}
            aside={
                providers.length > 1 && (
                    <Select
                        size="sm"
                        className="w-44"
                        aria-label="Storage for downloads"
                        value={provider}
                        onValueChange={setProvider}
                        options={providers.map((item) => ({ value: item.id, label: item.name }))}
                    />
                )
            }
            bare
        >
            {providers.length === 0 && <Callout tone="info">Add backup storage (Settings → Backup storage) to download files.</Callout>}
            <div className="flex flex-wrap items-center gap-2">
                <nav aria-label="Path" className="flex min-w-0 flex-1 flex-wrap items-center gap-0.5 font-mono text-xs">
                    <button type="button" className="text-fg-muted hover:text-fg rounded px-1" onClick={() => open('')}>
                        /
                    </button>
                    {segments.map((segment, index) => (
                        <span key={index} className="flex items-center gap-0.5">
                            {index > 0 && <ChevronRight className="text-fg-faint size-3" aria-hidden />}
                            <button
                                type="button"
                                className="text-fg-muted hover:text-fg rounded px-1"
                                onClick={() => open(segments.slice(0, index + 1).join('/'))}
                            >
                                {segment}
                            </button>
                        </span>
                    ))}
                </nav>
                <form
                    className="flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setOffset(0);
                        setSearch(query.trim());
                    }}
                >
                    <Input
                        className="w-56"
                        aria-label="Search names"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={path ? `Search in ${segments[segments.length - 1]}` : 'Search names'}
                        prefix={<Search className="size-3.5" />}
                    />
                </form>
                {providers.length > 0 && (
                    <Button
                        size="sm"
                        icon={<Download />}
                        loading={downloading === path}
                        disabled={downloading !== null}
                        onClick={() => void download(path)}
                    >
                        {path ? 'Download folder' : 'Download all'}
                    </Button>
                )}
            </div>
            {error && <Callout tone="danger">{error}</Callout>}
            <DataTable<BrowseEntry>
                label="Files"
                rows={entries}
                loading={loading && !result}
                rowKey={(row) => row.path}
                onRowClick={(row) => row.type === 'dir' && open(row.path)}
                empty={{ icon: <FolderOpen />, title: search ? 'Nothing matches' : 'Empty folder', size: 'sm' }}
                columns={[
                    {
                        id: 'name',
                        header: 'Name',
                        cell: (row) => (
                            <span className="flex min-w-0 items-center gap-2">
                                {row.type === 'dir' ? (
                                    <Folder className="text-fg-faint size-3.5 shrink-0" aria-hidden />
                                ) : row.type === 'symlink' ? (
                                    <Link2 className="text-fg-faint size-3.5 shrink-0" aria-label="Symbolic link" />
                                ) : (
                                    <File className="text-fg-faint size-3.5 shrink-0" aria-hidden />
                                )}
                                <span className="truncate font-mono text-xs">{search ? row.path : row.name}</span>
                            </span>
                        ),
                    },
                    {
                        id: 'size',
                        header: 'Size',
                        align: 'right',
                        cell: (row) => <span className="text-fg-muted tabular text-xs">{row.type === 'file' ? formatBytes(row.size) : ''}</span>,
                    },
                    {
                        id: 'mtime',
                        header: 'Modified',
                        hideOnMobile: true,
                        cell: (row) => <RelativeTime value={row.mtime} />,
                    },
                    {
                        id: 'download',
                        header: '',
                        align: 'right',
                        width: '48px',
                        cell: (row) =>
                            providers.length > 0 && (row.type === 'file' || row.type === 'dir') ? (
                                <IconButton
                                    size="sm"
                                    label={row.type === 'dir' ? `Download ${row.name} as .tar.zst` : `Download ${row.name}`}
                                    icon={<Download />}
                                    loading={downloading === row.path}
                                    disabled={downloading !== null}
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        void download(row.path);
                                    }}
                                />
                            ) : null,
                    },
                ]}
            />
            {(total > PAGE || offset > 0) && (
                <div className="text-fg-muted flex items-center justify-between text-xs">
                    <span className="tabular">
                        {offset + 1}–{offset + entries.length} of {total}
                        {result?.truncated ? '+' : ''}
                    </span>
                    <span className="flex gap-2">
                        <Button size="sm" variant="ghost" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>
                            Previous
                        </Button>
                        <Button size="sm" variant="ghost" disabled={offset + entries.length >= total} onClick={() => setOffset(offset + PAGE)}>
                            Next
                        </Button>
                    </span>
                </div>
            )}
        </Section>
    );
}
