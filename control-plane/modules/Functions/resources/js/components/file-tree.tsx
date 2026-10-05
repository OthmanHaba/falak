import { Button, Dialog, Field, IconButton, Input } from '@/components/falak';
import { cn } from '@/lib/utils';
import { FilePlus2, FileText, Folder, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { pathError, type FileStatus } from '../files';
import type { FunctionFiles } from '../types';

const STATUS: Record<FileStatus, { label: string; className: string }> = {
    added: { label: 'A', className: 'text-success' },
    modified: { label: 'M', className: 'text-warning' },
    removed: { label: 'D', className: 'text-danger' },
};

interface Row {
    kind: 'folder' | 'file';
    path: string;
    name: string;
    depth: number;
}

/** Folders (once each, before their files) and files, sorted by path, with the entrypoint first. */
function rows(paths: string[], entry: string): Row[] {
    const out: Row[] = [];
    const folders = new Set<string>();
    const sorted = [...paths].sort((a, b) => (a === entry ? -1 : b === entry ? 1 : a.localeCompare(b)));

    for (const path of sorted) {
        const segments = path.split('/');
        for (let i = 1; i < segments.length; i++) {
            const folder = segments.slice(0, i).join('/');
            if (!folders.has(folder)) {
                folders.add(folder);
                out.push({ kind: 'folder', path: folder, name: segments[i - 1], depth: i - 1 });
            }
        }
        out.push({ kind: 'file', path, name: segments[segments.length - 1], depth: segments.length - 1 });
    }

    return out;
}

export interface FileTreeProps {
    files: FunctionFiles;
    entry: string;
    active: string;
    onSelect: (path: string) => void;
    /** Per-file change marks (A/M/D). Removed files are listed so they can be opened in a diff. */
    status?: Record<string, FileStatus>;
    /** Whether a removed file can be selected: false where nothing could show it (an editor, not a diff). */
    removedSelectable?: boolean;
    /** Add, rename and delete (never the entrypoint). */
    editable?: boolean;
    maxFiles?: number;
    onCreate?: (path: string) => void;
    onRename?: (from: string, to: string) => void;
    onDelete?: (path: string) => void;
    className?: string;
}

/**
 * The function's files as a small tree beside the editor. Folders exist only through their files: a new file
 * "lib/db.ts" creates "lib".
 */
export function FileTree({
    files,
    entry,
    active,
    onSelect,
    status = {},
    removedSelectable = true,
    editable = false,
    maxFiles = 50,
    onCreate,
    onRename,
    onDelete,
    className,
}: FileTreeProps) {
    const [dialog, setDialog] = useState<{ mode: 'create' | 'rename'; from?: string } | null>(null);
    const [name, setName] = useState('');
    const removed = Object.entries(status)
        .filter(([path, s]) => s === 'removed' && !(path in files))
        .map(([path]) => path);
    const all = rows([...Object.keys(files), ...removed], entry);
    const error = dialog && name ? pathError(name.trim(), files, maxFiles, dialog.from) : null;

    const open = (mode: 'create' | 'rename', from?: string) => {
        setName(from ?? (active.includes('/') ? `${active.slice(0, active.lastIndexOf('/') + 1)}` : ''));
        setDialog({ mode, from });
    };

    const submit = () => {
        const path = name.trim();
        if (!dialog || !path || error) return;
        if (dialog.mode === 'create') onCreate?.(path);
        else if (dialog.from && dialog.from !== path) onRename?.(dialog.from, path);
        setDialog(null);
    };

    return (
        <div className={cn('border-border flex min-h-0 flex-col rounded-md border', className)}>
            <div className="border-border flex items-center justify-between border-b px-2 py-1.5">
                <span className="text-fg-muted text-xs font-medium">Files</span>
                {editable && (
                    <IconButton
                        size="sm"
                        label="New file"
                        icon={<FilePlus2 />}
                        disabled={Object.keys(files).length >= maxFiles}
                        onClick={() => open('create')}
                    />
                )}
            </div>
            <ul className="min-h-0 flex-1 overflow-auto py-1 text-[13px]">
                {all.map((row) =>
                    row.kind === 'folder' ? (
                        <li
                            key={`d:${row.path}`}
                            className="text-fg-muted flex items-center gap-1.5 py-0.5 pr-2"
                            style={{ paddingLeft: 8 + row.depth * 12 }}
                        >
                            <Folder className="size-3.5 shrink-0" aria-hidden />
                            <span className="truncate">{row.name}</span>
                        </li>
                    ) : (
                        <li key={row.path} className="group relative">
                            <button
                                type="button"
                                onClick={() => onSelect(row.path)}
                                disabled={!removedSelectable && !(row.path in files)}
                                title={row.path in files ? row.path : `${row.path} (deleted)`}
                                className={cn(
                                    'hover:bg-surface-2 flex w-full items-center gap-1.5 py-0.5 pr-2 text-left disabled:cursor-default disabled:hover:bg-transparent',
                                    row.path === active ? 'bg-surface-2 text-fg' : 'text-fg-muted',
                                    status[row.path] === 'removed' && 'line-through opacity-70',
                                )}
                                style={{ paddingLeft: 8 + row.depth * 12 }}
                            >
                                <FileText className="size-3.5 shrink-0" aria-hidden />
                                <span className="min-w-0 flex-1 truncate font-mono text-xs">{row.name}</span>
                                {row.path === entry && <span className="text-fg-faint text-[10px] uppercase">entry</span>}
                                {status[row.path] && (
                                    <span className={cn('font-mono text-[11px] font-semibold', STATUS[status[row.path]].className)}>
                                        {STATUS[status[row.path]].label}
                                    </span>
                                )}
                            </button>
                            {editable && row.path !== entry && row.path in files && (
                                <span className="bg-surface-2 absolute top-0 right-1 hidden items-center group-focus-within:flex group-hover:flex">
                                    <IconButton size="sm" label={`Rename ${row.path}`} icon={<Pencil />} onClick={() => open('rename', row.path)} />
                                    <IconButton size="sm" label={`Delete ${row.path}`} icon={<Trash2 />} onClick={() => onDelete?.(row.path)} />
                                </span>
                            )}
                        </li>
                    ),
                )}
            </ul>

            <Dialog
                open={dialog !== null}
                onOpenChange={(isOpen) => !isOpen && setDialog(null)}
                title={dialog?.mode === 'rename' ? `Rename ${dialog.from}` : 'New file'}
                description="A path like lib/db.ts creates the folder. Import it from the entrypoint with a relative path."
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDialog(null)}>
                            Cancel
                        </Button>
                        <Button variant="primary" disabled={!name.trim() || error !== null} onClick={submit}>
                            {dialog?.mode === 'rename' ? 'Rename' : 'Create'}
                        </Button>
                    </>
                }
            >
                <Field label="Path" error={error ?? undefined}>
                    <Input
                        autoFocus
                        className="font-mono"
                        value={name}
                        maxLength={200}
                        placeholder="lib/db.ts"
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && submit()}
                    />
                </Field>
            </Dialog>
        </div>
    );
}
