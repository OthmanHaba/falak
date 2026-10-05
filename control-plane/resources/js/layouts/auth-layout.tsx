import { useFlashToasts } from '@/components/falak/flash';
import { FalakMark } from '@/components/falak/logo';
import { Toaster } from '@/components/falak/toast';
import { initializeTheme } from '@/hooks/use-appearance';
import { Link } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';

interface AuthLayoutProps {
    title: string;
    description?: ReactNode;
    /** Below the card (e.g. "Don't have an account? Sign up"). */
    footer?: ReactNode;
    children: ReactNode;
}

/** Auth pages (§3): centered minimal card on --bg with a subtle dotted grid. */
export default function AuthLayout({ title, description, footer, children }: AuthLayoutProps) {
    useFlashToasts();
    useEffect(() => initializeTheme(), []);

    return (
        <div className="bg-bg relative flex min-h-svh flex-col items-center justify-center px-4 py-10">
            <div
                aria-hidden
                className="bg-dotted pointer-events-none absolute inset-0 [mask-image:radial-gradient(ellipse_60%_55%_at_50%_45%,black,transparent)]"
            />
            <main className="relative grid w-full max-w-[380px] gap-6">
                <Link
                    href={route('home')}
                    className="text-fg mx-auto flex items-center gap-2 rounded-md text-base font-semibold tracking-tight"
                    aria-label="Falak home"
                >
                    <FalakMark size={24} />
                    Falak
                </Link>
                <div className="border-border bg-surface-1 shadow-panel grid gap-5 rounded-xl border p-6">
                    <div className="grid gap-1 text-center">
                        <h1 className="text-fg text-lg font-semibold">{title}</h1>
                        {description && <p className="text-fg-muted text-sm">{description}</p>}
                    </div>
                    {children}
                </div>
                {footer && <div className="text-fg-muted text-center text-sm">{footer}</div>}
            </main>
            <Toaster />
        </div>
    );
}

/** Inline text link styled for auth pages. */
export function AuthLink({ href, children, method }: { href: string; children: ReactNode; method?: 'post' }) {
    return (
        <Link
            href={href}
            method={method}
            as={method ? 'button' : 'a'}
            className="text-primary rounded-sm font-medium underline-offset-4 hover:underline"
        >
            {children}
        </Link>
    );
}

export function AuthStatus({ children }: { children: ReactNode }) {
    return (
        <p role="status" className="border-success/30 bg-success-soft text-fg rounded-md border px-3 py-2 text-center text-sm">
            {children}
        </p>
    );
}
