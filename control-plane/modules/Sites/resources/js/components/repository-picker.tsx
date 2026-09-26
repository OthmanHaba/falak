import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Link } from '@inertiajs/react';
import { Loader2, Lock, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { type BranchItem, type ConnectionOption, type RepositoryItem } from '../types';
import { requestJson } from './site-ui';

export interface RepositoryValue {
    source_connection_id: string;
    repository: string;
    branch: string;
    push_to_deploy: boolean;
}

interface Props {
    connections: ConnectionOption[];
    value: RepositoryValue;
    onChange: (value: Partial<RepositoryValue>) => void;
    errors: Partial<Record<keyof RepositoryValue, string>>;
    canManageSourceControl?: boolean;
}

const NONE = 'none';

/**
 * Connection → repository → branch picker backed by the SourceControl JSON endpoints.
 * Custom git connections take a clone URL and branch by hand.
 */
export function RepositoryPicker({ connections, value, onChange, errors, canManageSourceControl = false }: Props) {
    const connection = connections.find((item) => item.id === value.source_connection_id) ?? null;
    const [search, setSearch] = useState('');
    const [repositories, setRepositories] = useState<RepositoryItem[]>([]);
    const [branches, setBranches] = useState<BranchItem[]>([]);
    const [loading, setLoading] = useState<'repositories' | 'branches' | null>(null);
    const [error, setError] = useState<string | null>(null);

    const loadRepositories = useCallback(
        async (query: string) => {
            if (!connection?.has_api) {
                return;
            }

            setLoading('repositories');
            setError(null);

            try {
                const body = await requestJson<{ data: RepositoryItem[] }>(
                    `/source-control/connections/${connection.id}/repositories?search=${encodeURIComponent(query)}`,
                );
                setRepositories(body.data);
            } catch (e) {
                setError(e instanceof Error ? e.message : 'Could not load repositories');
            } finally {
                setLoading(null);
            }
        },
        [connection],
    );

    useEffect(() => {
        setRepositories([]);
        setBranches([]);
        void loadRepositories('');
    }, [loadRepositories]);

    useEffect(() => {
        if (!connection?.has_api || !value.repository) {
            setBranches([]);

            return;
        }

        let cancelled = false;
        setLoading('branches');

        requestJson<{ data: BranchItem[] }>(
            `/source-control/connections/${connection.id}/branches?repository=${encodeURIComponent(value.repository)}`,
        )
            .then((body) => !cancelled && setBranches(body.data))
            .catch((e: unknown) => !cancelled && setError(e instanceof Error ? e.message : 'Could not load branches'))
            .finally(() => !cancelled && setLoading(null));

        return () => {
            cancelled = true;
        };
    }, [connection, value.repository]);

    if (connections.length === 0) {
        return (
            <div className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                No source control connections yet.{' '}
                {canManageSourceControl ? (
                    <Link href="/source-control" className="text-foreground underline">
                        Connect GitHub, GitLab, Bitbucket or a custom git server
                    </Link>
                ) : (
                    'Ask an admin to connect one.'
                )}{' '}
                — or skip and add a repository later.
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="grid gap-2">
                <Label>Connection</Label>
                <Select
                    value={value.source_connection_id || NONE}
                    onValueChange={(id) => onChange({ source_connection_id: id === NONE ? '' : id, repository: '', branch: '' })}
                >
                    <SelectTrigger aria-label="Source control connection">
                        <SelectValue placeholder="No repository" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>No repository (configure later)</SelectItem>
                        {connections.map((item) => (
                            <SelectItem key={item.id} value={item.id}>
                                {item.name} · {item.provider_label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.source_connection_id} />
            </div>

            {connection && !connection.has_api && (
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="repository">Clone URL</Label>
                        <Input
                            id="repository"
                            placeholder="git@git.example.com:acme/shop.git"
                            value={value.repository}
                            onChange={(e) => onChange({ repository: e.target.value })}
                        />
                        <InputError message={errors.repository} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="branch">Branch</Label>
                        <Input id="branch" placeholder="main" value={value.branch} onChange={(e) => onChange({ branch: e.target.value })} />
                        <InputError message={errors.branch} />
                    </div>
                </div>
            )}

            {connection?.has_api && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="repository-search">Repository</Label>
                        <div className="flex gap-2">
                            <Input
                                id="repository-search"
                                placeholder="Search repositories…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        void loadRepositories(search);
                                    }
                                }}
                            />
                            <Button type="button" variant="outline" onClick={() => void loadRepositories(search)} disabled={loading !== null}>
                                {loading === 'repositories' ? <Loader2 className="animate-spin" /> : <RefreshCw />}
                                Search
                            </Button>
                        </div>
                        <div className="max-h-56 overflow-y-auto rounded-md border">
                            {repositories.length === 0 && (
                                <p className="text-muted-foreground p-3 text-sm">{loading ? 'Loading…' : 'No repositories found.'}</p>
                            )}
                            {repositories.map((repository) => (
                                <button
                                    type="button"
                                    key={repository.full_name}
                                    onClick={() => onChange({ repository: repository.full_name, branch: repository.default_branch })}
                                    className={`hover:bg-muted flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm ${value.repository === repository.full_name ? 'bg-muted font-medium' : ''}`}
                                >
                                    <span className="truncate font-mono">{repository.full_name}</span>
                                    {repository.private && <Lock className="text-muted-foreground size-3.5 shrink-0" aria-label="Private" />}
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.repository} />
                    </div>

                    {value.repository && (
                        <div className="grid gap-2">
                            <Label>Branch</Label>
                            <Select value={value.branch || undefined} onValueChange={(branch) => onChange({ branch })}>
                                <SelectTrigger aria-label="Branch">
                                    <SelectValue placeholder={loading === 'branches' ? 'Loading branches…' : 'Pick a branch'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {(branches.some((branch) => branch.name === value.branch) || !value.branch
                                        ? branches
                                        : [{ name: value.branch, sha: null, protected: false }, ...branches]
                                    ).map((branch) => (
                                        <SelectItem key={branch.name} value={branch.name}>
                                            {branch.name}
                                            {branch.protected ? ' (protected)' : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.branch} />
                        </div>
                    )}
                </>
            )}

            {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

            {connection && (
                <label className="flex items-start gap-2 text-sm">
                    <Checkbox checked={value.push_to_deploy} onCheckedChange={(checked) => onChange({ push_to_deploy: checked === true })} />
                    <span>
                        Push to deploy
                        <span className="text-muted-foreground block text-xs">
                            Deploy automatically when the branch receives a push (a webhook is registered at the provider).
                        </span>
                    </span>
                </label>
            )}
        </div>
    );
}
