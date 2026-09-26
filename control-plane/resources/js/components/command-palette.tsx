import { CommandDialog, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandShortcut } from '@/components/ui/command';
import { registeredCommandProviders, shellContext, type PaletteCommand } from '@/lib/registry';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

/**
 * Global ⌘K / Ctrl+K palette. Commands come from every module's `register.ts` via registerCommands().
 */
export function CommandPalette() {
    const { props } = usePage<SharedData>();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [commands, setCommands] = useState<PaletteCommand[]>([]);
    const ctx = useMemo(() => shellContext(props), [props]);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };

        const onOpen = () => setOpen(true);

        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('kiln:command-palette', onOpen);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('kiln:command-palette', onOpen);
        };
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        const timer = window.setTimeout(
            async () => {
                const results = await Promise.all(
                    registeredCommandProviders()
                        .filter((provider) => query.length >= (provider.minQueryLength ?? 0))
                        .map(async (provider) => {
                            try {
                                return await provider.commands({ ...ctx, query });
                            } catch {
                                return [];
                            }
                        }),
                );

                if (!cancelled) {
                    setCommands(results.flat().filter((command) => !command.permission || ctx.can(command.permission)));
                }
            },
            query.length > 0 ? 150 : 0,
        );

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [open, query, ctx]);

    const groups = useMemo(() => {
        const byGroup = new Map<string, PaletteCommand[]>();
        commands.forEach((command) => byGroup.set(command.group, [...(byGroup.get(command.group) ?? []), command]));

        return [...byGroup.entries()];
    }, [commands]);

    const run = (command: PaletteCommand) => {
        setOpen(false);
        setQuery('');

        if (command.href) {
            router.visit(command.href);
        } else {
            command.perform?.();
        }
    };

    return (
        <CommandDialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (!value) setQuery('');
            }}
        >
            <CommandInput placeholder="Search servers, pages and actions…" value={query} onValueChange={setQuery} />
            <CommandList>
                <CommandEmpty>No results found.</CommandEmpty>
                {groups.map(([group, items]) => (
                    <CommandGroup key={group} heading={group}>
                        {items.map((command) => (
                            <CommandItem
                                key={command.id}
                                value={`${command.title} ${command.id}`}
                                keywords={command.keywords}
                                onSelect={() => run(command)}
                            >
                                {command.icon && <command.icon />}
                                <span>{command.title}</span>
                                {command.shortcut && <CommandShortcut>{command.shortcut}</CommandShortcut>}
                            </CommandItem>
                        ))}
                    </CommandGroup>
                ))}
            </CommandList>
        </CommandDialog>
    );
}

export function openCommandPalette(): void {
    window.dispatchEvent(new Event('kiln:command-palette'));
}
