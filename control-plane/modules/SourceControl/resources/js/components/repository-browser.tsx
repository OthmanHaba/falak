import { Callout } from '@/components/kiln/callout';
import { Dialog } from '@/components/kiln/dialog';
import { EmptyState } from '@/components/kiln/empty-state';
import { Input } from '@/components/kiln/input';
import { SkeletonRows } from '@/components/kiln/skeleton';
import { Tag } from '@/components/kiln/tag';
import { ExternalLink, FolderGit2, GitBranch, Lock, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { type ConnectionRow, type RepositoryOption } from '../types';

async function fetchRepositories(connectionId: string, search: string, signal: AbortSignal): Promise<RepositoryOption[]> {
    const response = await fetch(route('source-control.connections.repositories', { connection: connectionId, search }), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal,
    });
    const body = (await response.json().catch(() => null)) as { data?: RepositoryOption[]; message?: string } | null;

    if (!response.ok) throw new Error(body?.message ?? `The provider answered HTTP ${response.status}.`);

    return body?.data ?? [];
}

/** Live repository list of a connection (what Kiln can see with its credentials), with search. */
export function RepositoryBrowser({ connection, onClose }: { connection: ConnectionRow | null; onClose: () => void }) {
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [repositories, setRepositories] = useState<RepositoryOption[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const timer = window.setTimeout(() => setQuery(search.trim()), 250);

        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (!connection) {
            setSearch('');
            setQuery('');
            setRepositories(null);
            setError(null);

            return;
        }

        const controller = new AbortController();
        setRepositories(null);
        setError(null);
        fetchRepositories(connection.id, query, controller.signal)
            .then(setRepositories)
            .catch((e: unknown) => {
                if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'Could not load repositories.');
            });

        return () => controller.abort();
    }, [connection, query]);

    return (
        <Dialog
            open={connection !== null}
            onOpenChange={(open) => !open && onClose()}
            title={`Repositories · ${connection?.name ?? ''}`}
            description="What Kiln can deploy from with this connection's credentials."
            size="lg"
        >
            <div className="grid gap-3">
                <Input
                    prefix={<Search />}
                    placeholder="Search repositories"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    aria-label="Search repositories"
                    autoFocus
                />
                {error ? (
                    <Callout tone="danger" title="Could not list repositories">
                        {error}
                    </Callout>
                ) : repositories === null ? (
                    <SkeletonRows rows={6} />
                ) : repositories.length === 0 ? (
                    <EmptyState
                        size="sm"
                        icon={<FolderGit2 />}
                        title={query ? `No repositories match “${query}”` : 'No repositories visible'}
                        description={
                            query
                                ? 'Try another search.'
                                : 'Grant this connection access to repositories on the provider (app installation or token scopes), then reopen this list.'
                        }
                    />
                ) : (
                    <ul className="border-border divide-border max-h-[50vh] divide-y overflow-y-auto rounded-lg border" aria-label="Repositories">
                        {repositories.map((repository) => (
                            <li key={repository.full_name} className="flex items-center gap-3 px-3 py-2.5">
                                <FolderGit2 className="text-fg-faint size-4 shrink-0" aria-hidden />
                                <span className="grid min-w-0 flex-1">
                                    <span className="text-fg truncate font-mono text-xs">{repository.full_name}</span>
                                </span>
                                {repository.private && (
                                    <Tag icon={<Lock />} className="hidden sm:inline-flex">
                                        Private
                                    </Tag>
                                )}
                                <Tag mono icon={<GitBranch />}>
                                    {repository.default_branch}
                                </Tag>
                                {repository.web_url && (
                                    <a
                                        href={repository.web_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-fg-faint hover:text-fg shrink-0"
                                        aria-label={`Open ${repository.full_name}`}
                                    >
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Dialog>
    );
}
