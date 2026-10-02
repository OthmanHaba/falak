import { requestJson } from '@/lib/http';
import { type ComposeServiceChoice } from '@/lib/registry';
import { useEffect, useRef, useState } from 'react';

/** One service of POST /sites/compose/inspect (docs/plans/COMPOSE_APPS.md). */
export interface InspectedService {
    name: string;
    image: string | null;
    build: boolean;
    build_context: string | null;
    ports: number[];
    published_ports: string[];
    volumes: string[];
    binds: { source: string; in_repo: boolean; key: string }[];
    env_files: { path: string; in_repo: boolean }[];
    healthcheck: boolean;
    depends_on: string[];
    variables: string[];
    database_engine: 'postgresql' | 'mysql' | 'mariadb' | null;
    mode: ComposeServiceChoice['mode'];
}

export interface InspectedVariable {
    name: string;
    default: string | null;
    required: boolean;
    services: string[];
    source: string;
}

export interface Adjustment {
    kind: string;
    service: string | null;
    detail: string;
    key?: string;
}

export interface Inspection {
    no_api: boolean;
    message?: string;
    files?: string[];
    services: InspectedService[];
    variables: InspectedVariable[];
    adjustments: Adjustment[];
    missing?: string[];
    violations: string[];
    errors: string[];
    warnings: string[];
    original?: string;
    adjusted?: string;
}

export const ENGINE_LABELS: Record<string, string> = { postgresql: 'PostgreSQL', mysql: 'MySQL', mariadb: 'MariaDB' };

/**
 * Debounced POST to an inspect endpoint; the latest answer wins. `body` null skips (nothing to inspect yet).
 */
export function useInspection(
    url: string,
    body: Record<string, unknown> | null,
): { inspection: Inspection | null; loading: boolean; error: string | null } {
    const [inspection, setInspection] = useState<Inspection | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const key = body === null ? null : JSON.stringify(body);
    const sequence = useRef(0);

    useEffect(() => {
        if (key === null) {
            setInspection(null);
            setLoading(false);

            return;
        }
        const current = ++sequence.current;
        setLoading(true);
        const timer = window.setTimeout(() => {
            requestJson<{ data: Inspection }>(url, 'POST', JSON.parse(key) as Record<string, unknown>)
                .then((response) => {
                    if (current !== sequence.current) return;
                    setInspection(response.data);
                    setError(null);
                })
                .catch((e: unknown) => current === sequence.current && setError(e instanceof Error ? e.message : 'Could not read the repository'))
                .finally(() => current === sequence.current && setLoading(false));
        }, 400);

        return () => window.clearTimeout(timer);
    }, [url, key]);

    return { inspection, loading, error };
}

/** POST /sites/{site}/compose/candidates: compose files of a site's repository (Settings), as suggestions. */
export function useSiteCandidates(url: string, enabled: boolean): string[] {
    const [files, setFiles] = useState<string[]>([]);

    useEffect(() => {
        if (!enabled) {
            setFiles([]);

            return;
        }
        let cancelled = false;
        requestJson<{ data: { files: string[] } }>(url, 'POST', {})
            .then((response) => !cancelled && setFiles(response.data.files))
            .catch(() => !cancelled && setFiles([]));

        return () => {
            cancelled = true;
        };
    }, [url, enabled]);

    return files;
}

/** POST /sites/compose/candidates: compose files of the repository, as suggestions. */
export function useCandidates(connectionId: string, repository: string, branch: string): string[] {
    const [files, setFiles] = useState<string[]>([]);

    useEffect(() => {
        if (!connectionId || !repository || !branch) {
            setFiles([]);

            return;
        }
        let cancelled = false;
        requestJson<{ data: { files: string[] } }>('/sites/compose/candidates', 'POST', { source_connection_id: connectionId, repository, branch })
            .then((response) => !cancelled && setFiles(response.data.files))
            .catch(() => !cancelled && setFiles([]));

        return () => {
            cancelled = true;
        };
    }, [connectionId, repository, branch]);

    return files;
}
