import { cn } from '@/lib/utils';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { LoaderCircle } from 'lucide-react';
import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Tooltip } from './tooltip';

export const buttonVariants = cva(
    [
        'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap select-none',
        'transition-[background-color,border-color,color,opacity] duration-150 ease-out',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
        'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        '[&_svg]:pointer-events-none [&_svg]:shrink-0',
    ],
    {
        variants: {
            variant: {
                primary: 'bg-primary text-on-accent hover:bg-primary-hover',
                secondary: 'border border-border bg-surface-2 text-fg hover:border-border-strong hover:bg-surface-3',
                ghost: 'text-fg-muted hover:bg-surface-2 hover:text-fg',
                danger: 'bg-danger text-on-danger hover:opacity-90',
            },
            size: {
                sm: 'h-7 px-2.5 text-xs [&_svg]:size-3.5',
                md: 'h-8 px-3 text-sm [&_svg]:size-4',
            },
        },
        defaultVariants: { variant: 'secondary', size: 'md' },
    },
);

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
    /** Shows a spinner, disables the button and sets aria-busy. */
    loading?: boolean;
    icon?: ReactNode;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild = false, loading = false, icon, disabled, children, type, ...props }, ref) => {
        const Comp = asChild ? Slot : 'button';

        if (asChild) {
            return (
                <Comp ref={ref} className={cn(buttonVariants({ variant, size }), className)} {...props}>
                    {children}
                </Comp>
            );
        }

        return (
            <Comp
                ref={ref}
                type={type ?? 'button'}
                className={cn(buttonVariants({ variant, size }), className)}
                disabled={disabled || loading}
                aria-busy={loading || undefined}
                {...props}
            >
                {loading ? <LoaderCircle className="animate-spin" aria-hidden /> : icon}
                {children}
            </Comp>
        );
    },
);
Button.displayName = 'Button';

export interface IconButtonProps extends Omit<ButtonProps, 'children' | 'icon'> {
    /** Accessible name; also shown as a tooltip unless `tooltip={false}`. */
    label: string;
    icon: ReactNode;
    tooltip?: boolean;
    shortcut?: string;
}

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(
    ({ label, icon, tooltip = true, shortcut, className, size = 'md', variant = 'ghost', ...props }, ref) => {
        const button = (
            <Button
                ref={ref}
                aria-label={label}
                variant={variant}
                size={size}
                className={cn('px-0', size === 'sm' ? 'w-7' : 'w-8', className)}
                icon={icon}
                {...props}
            />
        );

        return tooltip ? (
            <Tooltip content={label} shortcut={shortcut}>
                {button}
            </Tooltip>
        ) : (
            button
        );
    },
);
IconButton.displayName = 'IconButton';
