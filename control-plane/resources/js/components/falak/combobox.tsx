import { cn } from '@/lib/utils';
import * as Popover from '@radix-ui/react-popover';
import { Command } from 'cmdk';
import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useFieldControl } from './field';
import { controlClasses } from './input';

export interface ComboboxOption {
    value: string;
    label: string;
    description?: string;
    icon?: ReactNode;
    keywords?: string[];
}

export interface ComboboxProps {
    value: string | null;
    onValueChange: (value: string | null) => void;
    options: ComboboxOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: ReactNode;
    disabled?: boolean;
    id?: string;
    className?: string;
    'aria-label'?: string;
    /** Allow clearing by re-selecting the current value. */
    clearable?: boolean;
}

/** Searchable single select (repositories, servers, branches…). */
export function Combobox({
    value,
    onValueChange,
    options,
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    emptyText = 'No matches.',
    disabled,
    className,
    clearable = false,
    ...props
}: ComboboxProps) {
    const [open, setOpen] = useState(false);
    const field = useFieldControl(props);
    const selected = options.find((option) => option.value === value) ?? null;

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger asChild disabled={disabled}>
                <button
                    type="button"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={props['aria-label']}
                    {...field}
                    className={cn(controlClasses, 'flex h-8 items-center justify-between gap-2 px-2.5 text-left', className)}
                >
                    <span className={cn('flex min-w-0 items-center gap-2 truncate', !selected && 'text-fg-faint')}>
                        {selected?.icon}
                        {selected?.label ?? placeholder}
                    </span>
                    <ChevronsUpDown className="text-fg-faint size-4 shrink-0" aria-hidden />
                </button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    align="start"
                    sideOffset={4}
                    className="animate-fade-in border-border bg-surface-1 shadow-panel z-50 w-(--radix-popover-trigger-width) min-w-56 overflow-hidden rounded-lg border"
                >
                    <Command className="flex flex-col" loop>
                        <div className="border-border flex items-center gap-2 border-b px-2.5">
                            <Search className="text-fg-faint size-4" aria-hidden />
                            <Command.Input
                                placeholder={searchPlaceholder}
                                className="text-fg placeholder:text-fg-faint h-9 w-full bg-transparent text-sm outline-none"
                            />
                        </div>
                        <Command.List className="max-h-64 overflow-y-auto p-1">
                            <Command.Empty className="text-fg-faint px-2 py-6 text-center text-sm">{emptyText}</Command.Empty>
                            {options.map((option) => (
                                <Command.Item
                                    key={option.value}
                                    value={`${option.label} ${option.value}`}
                                    keywords={option.keywords}
                                    onSelect={() => {
                                        onValueChange(clearable && option.value === value ? null : option.value);
                                        setOpen(false);
                                    }}
                                    className="text-fg data-[selected=true]:bg-surface-2 flex cursor-default items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                                >
                                    {option.icon && <span className="text-fg-muted [&_svg]:size-4">{option.icon}</span>}
                                    <span className="grid min-w-0 flex-1">
                                        <span className="truncate">{option.label}</span>
                                        {option.description && <span className="text-fg-faint truncate text-xs">{option.description}</span>}
                                    </span>
                                    {option.value === value && <Check className="text-primary size-4" aria-hidden />}
                                </Command.Item>
                            ))}
                        </Command.List>
                    </Command>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
