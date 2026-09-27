import { cn } from '@/lib/utils';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { Check, Minus } from 'lucide-react';
import { type ComponentPropsWithoutRef } from 'react';
import { useFieldControl } from './field';

export type CheckboxProps = ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>;

export function Checkbox({ className, ...props }: CheckboxProps) {
    const field = useFieldControl(props);

    return (
        <CheckboxPrimitive.Root
            {...props}
            {...field}
            className={cn(
                'border-border-strong bg-surface-2 text-on-accent flex size-4 shrink-0 items-center justify-center rounded-sm border',
                'data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary transition-colors duration-150 ease-out',
                'focus-visible:outline-primary focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
        >
            <CheckboxPrimitive.Indicator>
                {props.checked === 'indeterminate' ? <Minus className="size-3" strokeWidth={3} /> : <Check className="size-3" strokeWidth={3} />}
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    );
}
