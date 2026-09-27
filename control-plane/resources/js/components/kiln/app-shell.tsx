import { initializeTheme } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { useEffect, type ReactNode } from 'react';
import { CommandPalette } from './command-palette';
import { useFlashToasts } from './flash';
import { Toaster } from './toast';
import { TooltipProvider } from './tooltip';
import { TopBar } from './top-bar';

export interface AppShellProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /**
     * `page`: centered content column with the 24px gutter (16px mobile).
     * `full`: full-bleed below the top bar (project canvas).
     */
    variant?: 'page' | 'full';
    className?: string;
}

/** The authenticated app frame (§3): top bar, no permanent sidebar, ⌘K palette and toasts. */
export function AppShell({ children, breadcrumbs, variant = 'page', className }: AppShellProps) {
    useFlashToasts();
    useEffect(() => initializeTheme(), []);

    return (
        <TooltipProvider delayDuration={300}>
            <div className="bg-bg text-fg flex min-h-svh flex-col">
                <a
                    href="#main"
                    className="bg-surface-2 sr-only z-50 rounded-md px-3 py-2 text-sm focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
                >
                    Skip to content
                </a>
                <TopBar breadcrumbs={breadcrumbs} />
                <main
                    id="main"
                    className={cn(
                        variant === 'full' ? 'relative flex min-h-0 flex-1 flex-col' : 'mx-auto w-full max-w-[1200px] flex-1 px-4 py-6 md:px-6',
                        className,
                    )}
                >
                    {children}
                </main>
                <CommandPalette />
                <Toaster />
            </div>
        </TooltipProvider>
    );
}
