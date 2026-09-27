import { IconButton, ServiceIcon } from '@/components/kiln';
import * as Popover from '@radix-ui/react-popover';
import { Command } from 'cmdk';
import { Braces, Search } from 'lucide-react';
import { useState } from 'react';
import { referenceFor, type ReferenceTarget } from './api';

interface ReferencePickerProps {
    targets: ReferenceTarget[] | null;
    /** This site's service (listed last: a site may reference its own keys). */
    selfHandle: string;
    onPick: (reference: string) => void;
    disabled?: boolean;
}

/**
 * §5.3 reference picker: every service of the environment with the keys it exposes; picking one inserts
 * `${{ service.KEY }}`, resolved at deploy time.
 */
export function ReferencePicker({ targets, selfHandle, onPick, disabled }: ReferencePickerProps) {
    const [open, setOpen] = useState(false);
    const others = (targets ?? []).filter((target) => target.handle !== selfHandle);

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger asChild disabled={disabled}>
                <IconButton size="sm" tooltip={false} label="Insert a reference to another service" icon={<Braces />} />
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    align="end"
                    sideOffset={4}
                    onKeyDown={(event) => event.stopPropagation()}
                    className="animate-fade-in border-border bg-surface-1 shadow-panel z-50 w-80 overflow-hidden rounded-lg border"
                >
                    <Command className="flex flex-col" loop>
                        <div className="border-border flex items-center gap-2 border-b px-2.5">
                            <Search className="text-fg-faint size-4" aria-hidden />
                            <Command.Input
                                autoFocus
                                placeholder="Search services and keys…"
                                className="text-fg placeholder:text-fg-faint h-9 w-full bg-transparent text-sm outline-none"
                            />
                        </div>
                        <Command.List className="max-h-72 overflow-y-auto p-1">
                            <Command.Empty className="text-fg-faint px-2 py-6 text-center text-sm">
                                {targets === null ? 'Loading services…' : 'No other service in this environment exposes variables.'}
                            </Command.Empty>
                            {others.map((target) => (
                                <Command.Group
                                    key={target.id}
                                    heading={
                                        <span className="flex items-center gap-1.5">
                                            <ServiceIcon name={target.kind === 'database' ? 'database' : 'site'} size={12} />
                                            {target.name}
                                        </span>
                                    }
                                    className="[&_[cmdk-group-heading]]:text-fg-faint [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:font-medium"
                                >
                                    {target.keys.map((key) => (
                                        <Command.Item
                                            key={key}
                                            value={`${target.name} ${key}`}
                                            onSelect={() => {
                                                onPick(referenceFor(target, key));
                                                setOpen(false);
                                            }}
                                            className="text-fg data-[selected=true]:bg-surface-2 flex cursor-default items-center justify-between gap-2 rounded-md px-2 py-1.5 font-mono text-xs"
                                        >
                                            <span className="truncate">{key}</span>
                                        </Command.Item>
                                    ))}
                                </Command.Group>
                            ))}
                        </Command.List>
                        <p className="border-border text-fg-faint border-t px-3 py-2 text-[11px]">
                            Inserts <code className="font-mono">{'${{ service.KEY }}'}</code> — resolved in this environment at deploy time.
                        </p>
                    </Command>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
