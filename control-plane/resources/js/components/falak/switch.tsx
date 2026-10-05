import { cn } from '@/lib/utils';
import * as SwitchPrimitive from '@radix-ui/react-switch';
import { type ComponentPropsWithoutRef } from 'react';
import { useFieldControl } from './field';

export type SwitchProps = ComponentPropsWithoutRef<typeof SwitchPrimitive.Root>;

export function Switch({ className, ...props }: SwitchProps) {
    const field = useFieldControl(props);

    return (
        <SwitchPrimitive.Root
            {...props}
            {...field}
            className={cn(
                'border-border-strong bg-surface-3 relative inline-flex h-[18px] w-8 shrink-0 cursor-pointer items-center rounded-full border',
                'data-[state=checked]:border-primary data-[state=checked]:bg-primary transition-colors duration-150 ease-out',
                'focus-visible:outline-primary focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
        >
            <SwitchPrimitive.Thumb className="bg-fg-muted data-[state=checked]:bg-on-accent block size-3 translate-x-0.5 rounded-full transition-transform duration-150 ease-out data-[state=checked]:translate-x-[15px]" />
        </SwitchPrimitive.Root>
    );
}
