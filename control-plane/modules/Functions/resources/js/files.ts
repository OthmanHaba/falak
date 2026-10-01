import type { FunctionFiles } from './types';

/** Same rules as the server (Code::files): relative, letters, digits, . _ - and /, no dot-files, ≤ 8 levels. */
const PATH = /^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$/;
const RESERVED = ['node_modules', '__pycache__'];
const MAX_DEPTH = 8;

export type FileStatus = 'added' | 'removed' | 'modified';

export interface FileChange {
    path: string;
    status: FileStatus;
}

/** The entrypoint first, then the rest by path. */
export function sortedPaths(files: FunctionFiles, entry: string): string[] {
    return Object.keys(files).sort((a, b) => (a === entry ? -1 : b === entry ? 1 : a.localeCompare(b)));
}

/** Per-file changes from `from` to `to`, by path. */
export function fileChanges(from: FunctionFiles, to: FunctionFiles): FileChange[] {
    const paths = [...new Set([...Object.keys(from), ...Object.keys(to)])].sort();

    return paths.flatMap((path): FileChange[] => {
        if (!(path in from)) return [{ path, status: 'added' }];
        if (!(path in to)) return [{ path, status: 'removed' }];

        return from[path] === to[path] ? [] : [{ path, status: 'modified' }];
    });
}

/** Why `path` can't be a new file of `files` (null when it can). `except` is the file being renamed. */
export function pathError(path: string, files: FunctionFiles, maxFiles: number, except?: string): string | null {
    const others = Object.keys(files).filter((p) => p !== except);
    const segments = path.split('/');

    if (!PATH.test(path) || path.length > 200) return 'Use letters, digits, . _ - and / (no leading dot, no ..).';
    if (segments.length > MAX_DEPTH) return `At most ${MAX_DEPTH} folder levels.`;
    if (segments.some((s) => RESERVED.includes(s))) return `${RESERVED.join(' and ')} are created on the server.`;
    if (others.some((p) => p.toLowerCase() === path.toLowerCase())) return 'A file with this name exists.';
    if (others.some((p) => p.startsWith(`${path}/`))) return 'A folder with this name exists.';
    for (let i = 1; i < segments.length; i++) {
        const folder = segments.slice(0, i).join('/');
        if (others.includes(folder)) return `${folder} is a file, not a folder.`;
    }
    if (except === undefined && others.length >= maxFiles) return `A function can have at most ${maxFiles} files.`;

    return null;
}

export function languageOf(path: string, fallback = 'plaintext'): string {
    if (/\.(ts|mts|cts|tsx)$/.test(path)) return 'typescript';
    if (/\.(js|mjs|cjs|jsx)$/.test(path)) return 'javascript';
    if (path.endsWith('.json')) return 'json';
    if (path.endsWith('.py')) return 'python';
    if (path.endsWith('.go')) return 'go';
    if (/\.(ya?ml)$/.test(path)) return 'yaml';
    if (/\.(md|markdown)$/.test(path)) return 'markdown';
    if (path.endsWith('.sql')) return 'sql';
    if (/\.(html?)$/.test(path)) return 'html';
    if (path.endsWith('.css')) return 'css';
    if (/\.(toml|ini|cfg)$/.test(path)) return 'ini';
    if (/\.(txt|mod|sum|lock)$/.test(path) || path.endsWith('requirements.txt')) return 'plaintext';

    return fallback;
}
