import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight, Check, X } from 'lucide-react';
import { type ReactNode } from 'react';
import { Button, IconButton } from './button';

export interface SetupStep {
    id: string;
    title: string;
    /** One line on why the step matters / what it unlocks. */
    description?: ReactNode;
    done: boolean;
    /** Where the step's action goes (Inertia link). */
    href?: string;
    /** Or run something (e.g. open the Create picker on the canvas). */
    onAction?: () => void;
    /** Button label; defaults to the title. */
    actionLabel?: string;
}

export interface SetupChecklistProps {
    steps: SetupStep[];
    title?: ReactNode;
    description?: ReactNode;
    /** Shows a dismiss (×) button; persist the choice yourself (e.g. localStorage). */
    onDismiss?: () => void;
    /** Render nothing once every step is done (default true). */
    hideWhenComplete?: boolean;
    className?: string;
}

/** Which of the standard first-run steps are complete (compute from page props / the Kiln shared data). */
export interface SetupProgress {
    gitConnected: boolean;
    hasServer: boolean;
    hasProject: boolean;
    hasDeployment: boolean;
}

/**
 * The standard first-run steps: connect git → add a server → create a project → first deploy.
 * Override any href (e.g. `{ deploy: '/projects/acme/production' }`) or pass `onAction` handlers by id afterwards.
 */
export function defaultSetupSteps(progress: SetupProgress, hrefs: Partial<Record<'git' | 'server' | 'project' | 'deploy', string>> = {}): SetupStep[] {
    return [
        {
            id: 'git',
            title: 'Connect a git provider',
            description: 'GitHub, GitLab, Bitbucket or any git server — Kiln deploys from your repositories.',
            done: progress.gitConnected,
            href: hrefs.git ?? '/settings/source-control',
            actionLabel: 'Connect git',
        },
        {
            id: 'server',
            title: 'Add a server',
            description: 'Create one on a cloud provider or connect any Ubuntu machine with one command.',
            done: progress.hasServer,
            href: hrefs.server ?? '/servers/create',
            actionLabel: 'Add server',
        },
        {
            id: 'project',
            title: 'Create a project',
            description: 'Projects group the services of an app across environments.',
            done: progress.hasProject,
            href: hrefs.project ?? '/projects',
            actionLabel: 'Create project',
        },
        {
            id: 'deploy',
            title: 'Deploy your first service',
            description: 'Pick a repository in the project and watch the first deploy stream live.',
            done: progress.hasDeployment,
            href: hrefs.deploy ?? '/projects',
            actionLabel: 'Deploy',
        },
    ];
}

function StepAction({ step, primary }: { step: SetupStep; primary: boolean }) {
    const label = step.actionLabel ?? step.title;
    const variant = primary ? 'primary' : 'ghost';

    if (step.href) {
        return (
            <Button asChild size="sm" variant={variant}>
                <Link href={step.href}>
                    {label}
                    {primary && <ArrowRight className="size-3.5" aria-hidden />}
                </Link>
            </Button>
        );
    }

    if (step.onAction) {
        return (
            <Button size="sm" variant={variant} onClick={step.onAction}>
                {label}
                {primary && <ArrowRight aria-hidden />}
            </Button>
        );
    }

    return null;
}

/**
 * First-run onboarding checklist (§1.8 "empty states teach"). Steps are ordered; the first incomplete one is the
 * current step and gets the primary action.
 *
 *     <SetupChecklist steps={defaultSetupSteps({ gitConnected, hasServer, hasProject, hasDeployment })} onDismiss={hide} />
 */
export function SetupChecklist({
    steps,
    title = 'Get started with Kiln',
    description,
    onDismiss,
    hideWhenComplete = true,
    className,
}: SetupChecklistProps) {
    const completed = steps.filter((step) => step.done).length;
    const current = steps.find((step) => !step.done);

    if (hideWhenComplete && !current) return null;

    const percent = steps.length === 0 ? 100 : Math.round((completed / steps.length) * 100);

    return (
        <section aria-label="Setup checklist" className={cn('border-border bg-surface-1 rounded-lg border', className)} data-testid="setup-checklist">
            <header className="flex items-start gap-3 px-4 pt-4 pb-3">
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <h2 className="text-fg text-sm font-medium">{title}</h2>
                    <p className="text-fg-muted text-xs">
                        {description ?? (current ? `Next: ${current.title.toLowerCase()}.` : 'All set — you are ready to ship.')}
                    </p>
                </div>
                <span className="text-fg-faint tabular text-xs whitespace-nowrap">
                    {completed} of {steps.length}
                </span>
                {onDismiss && <IconButton label="Dismiss" size="sm" icon={<X />} onClick={onDismiss} className="-mt-1 -mr-1" />}
            </header>
            <div
                className="bg-surface-3 mx-4 h-1 overflow-hidden rounded-full"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-label="Setup progress"
            >
                <div className="bg-primary h-full rounded-full transition-[width] duration-300 ease-out" style={{ width: `${percent}%` }} />
            </div>
            <ol className="grid p-2 pt-3">
                {steps.map((step, index) => {
                    const isCurrent = step.id === current?.id;

                    return (
                        <li
                            key={step.id}
                            data-done={step.done || undefined}
                            aria-current={isCurrent ? 'step' : undefined}
                            className={cn('flex items-center gap-3 rounded-md px-2 py-2', isCurrent && 'bg-surface-2')}
                        >
                            <span
                                className={cn(
                                    'flex size-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-semibold',
                                    step.done
                                        ? 'border-success bg-success text-on-accent'
                                        : isCurrent
                                          ? 'border-primary text-primary'
                                          : 'border-border-strong text-fg-faint',
                                )}
                                aria-hidden
                            >
                                {step.done ? <Check className="size-3" strokeWidth={3} /> : index + 1}
                            </span>
                            <div className="grid min-w-0 flex-1 gap-0.5">
                                <span className={cn('text-sm', step.done ? 'text-fg-faint line-through' : 'text-fg font-medium')}>
                                    {step.title}
                                    <span className="sr-only">{step.done ? ' (done)' : ''}</span>
                                </span>
                                {step.description && !step.done && <span className="text-fg-muted text-xs">{step.description}</span>}
                            </div>
                            {!step.done && <StepAction step={step} primary={isCurrent} />}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
