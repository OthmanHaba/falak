import { cn } from '@/lib/utils';
import { ChevronsUpDown } from 'lucide-react';
import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';

/** Top bar breadcrumb-style dropdown trigger: [icon] Name ⌄ */
export const SwitcherTrigger = forwardRef<
    HTMLButtonElement,
    ButtonHTMLAttributes<HTMLButtonElement> & { icon?: ReactNode; label: ReactNode; hint?: ReactNode }
>(({ icon, label, hint, className, ...props }, ref) => (
    <button
        ref={ref}
        type="button"
        className={cn(
            'text-fg hover:bg-surface-2 data-[state=open]:bg-surface-2 inline-flex h-8 max-w-48 min-w-0 items-center gap-2 rounded-md px-2 text-sm font-medium transition-colors duration-150',
            className,
        )}
        {...props}
    >
        {icon}
        <span className="min-w-0 truncate">{label}</span>
        {hint}
        <ChevronsUpDown className="text-fg-faint size-3.5 shrink-0" aria-hidden />
    </button>
));
SwitcherTrigger.displayName = 'SwitcherTrigger';

export function PathSeparator() {
    return (
        <span className="text-fg-faint px-0.5 select-none" aria-hidden>
            /
        </span>
    );
}
