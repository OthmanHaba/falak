import { cn } from '@/lib/utils';
import { Database, GitBranch, Plus } from 'lucide-react';
import { type ReactNode } from 'react';
import { Button } from './button';
import { Kbd } from './kbd';

export interface EmptyCanvasProps {
    /** Opens the Create picker. Omit when the user can't create services. */
    onCreate?: () => void;
    title?: ReactNode;
    description?: ReactNode;
    className?: string;
}

/** §1.8 empty state of a project environment: what a service is and the one action that adds one. */
export function EmptyCanvas({
    onCreate,
    title = 'Nothing deployed here yet',
    description = 'Services are the sites and databases of this environment. Deploy a Git repository or a Docker image, or add a database — they show up here with live status.',
    className,
}: EmptyCanvasProps) {
    return (
        <div className={cn('pointer-events-none absolute inset-0 flex items-center justify-center p-6', className)}>
            <div className="border-border bg-surface-1/90 pointer-events-auto grid max-w-md justify-items-center gap-4 rounded-xl border border-dashed px-8 py-10 text-center backdrop-blur">
                <div className="flex items-center gap-2" aria-hidden>
                    <span className="border-border bg-surface-2 text-fg-muted flex size-9 items-center justify-center rounded-lg border">
                        <GitBranch className="size-4" />
                    </span>
                    <span className="bg-border h-px w-6" />
                    <span className="border-border bg-surface-2 text-fg-muted flex size-9 items-center justify-center rounded-lg border">
                        <Database className="size-4" />
                    </span>
                </div>
                <div className="grid gap-1">
                    <h2 className="text-fg text-base font-medium">{title}</h2>
                    <p className="text-fg-muted text-sm">{description}</p>
                </div>
                {onCreate && (
                    <div className="flex flex-col items-center gap-2">
                        <Button variant="primary" icon={<Plus />} onClick={onCreate}>
                            Create a service
                        </Button>
                        <span className="text-fg-faint text-xs">
                            or right-click the canvas · <Kbd>⌘K</Kbd> → Create
                        </span>
                    </div>
                )}
            </div>
        </div>
    );
}
