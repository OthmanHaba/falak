import { cn } from '@/lib/utils';
import * as SelectPrimitive from '@radix-ui/react-select';
import { Check, ChevronDown } from 'lucide-react';
import { type ReactNode } from 'react';
import { useFieldControl } from './field';
import { controlClasses } from './input';

export interface SelectOption<V extends string = string> {
    value: V;
    label: ReactNode;
    description?: ReactNode;
    icon?: ReactNode;
    disabled?: boolean;
}

export interface SelectProps<V extends string = string> {
    value: V | undefined;
    onValueChange: (value: V) => void;
    options: SelectOption<V>[];
    placeholder?: string;
    disabled?: boolean;
    id?: string;
    name?: string;
    size?: 'sm' | 'md';
    className?: string;
    'aria-label'?: string;
}

export function Select<V extends string = string>({
    value,
    onValueChange,
    options,
    placeholder = 'Select…',
    disabled,
    name,
    size = 'md',
    className,
    ...props
}: SelectProps<V>) {
    const field = useFieldControl(props);

    return (
        <SelectPrimitive.Root value={value} onValueChange={(next) => onValueChange(next as V)} disabled={disabled} name={name}>
            <SelectPrimitive.Trigger
                {...field}
                aria-label={props['aria-label']}
                className={cn(
                    controlClasses,
                    'data-[placeholder]:text-fg-faint flex items-center justify-between gap-2 px-2.5 text-left',
                    size === 'sm' ? 'h-7 text-xs' : 'h-8',
                    className,
                )}
            >
                <span className="truncate">
                    <SelectPrimitive.Value placeholder={placeholder} />
                </span>
                <SelectPrimitive.Icon>
                    <ChevronDown className="text-fg-faint size-4" aria-hidden />
                </SelectPrimitive.Icon>
            </SelectPrimitive.Trigger>
            <SelectPrimitive.Portal>
                <SelectPrimitive.Content
                    position="popper"
                    sideOffset={4}
                    className="animate-fade-in border-border bg-surface-1 shadow-panel z-50 max-h-(--radix-select-content-available-height) min-w-(--radix-select-trigger-width) overflow-hidden rounded-lg border"
                >
                    <SelectPrimitive.Viewport className="p-1">
                        {options.map((option) => (
                            <SelectPrimitive.Item
                                key={option.value}
                                value={option.value}
                                disabled={option.disabled}
                                className="text-fg data-[highlighted]:bg-surface-2 relative flex cursor-default items-center gap-2 rounded-md py-1.5 pr-8 pl-2 text-sm outline-none select-none data-[disabled]:opacity-50"
                            >
                                {option.icon && <span className="text-fg-muted [&_svg]:size-4">{option.icon}</span>}
                                <span className="grid">
                                    <SelectPrimitive.ItemText>{option.label}</SelectPrimitive.ItemText>
                                    {option.description && <span className="text-fg-faint text-xs">{option.description}</span>}
                                </span>
                                <SelectPrimitive.ItemIndicator className="absolute right-2">
                                    <Check className="text-primary size-4" aria-hidden />
                                </SelectPrimitive.ItemIndicator>
                            </SelectPrimitive.Item>
                        ))}
                    </SelectPrimitive.Viewport>
                </SelectPrimitive.Content>
            </SelectPrimitive.Portal>
        </SelectPrimitive.Root>
    );
}
