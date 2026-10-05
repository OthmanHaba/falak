import { Section } from '@/components/falak/section';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { Monitor, Moon, Sun, type LucideIcon } from 'lucide-react';

const OPTIONS: { value: Appearance; label: string; description: string; icon: LucideIcon }[] = [
    { value: 'dark', label: 'Dark', description: 'The default Falak theme.', icon: Moon },
    { value: 'light', label: 'Light', description: 'High-key surfaces for bright rooms.', icon: Sun },
    { value: 'system', label: 'System', description: 'Follow your operating system.', icon: Monitor },
];

/** Miniature of the app chrome in a given theme (class-scoped tokens, no hard-coded colors). */
function Preview({ theme }: { theme: 'dark' | 'light' }) {
    return (
        <div className={cn(theme, 'border-border bg-bg pointer-events-none overflow-hidden rounded-md border')} aria-hidden>
            <div className="border-border flex h-3 items-center gap-1 border-b px-1.5">
                <span className="bg-primary size-1 rounded-full" />
                <span className="bg-surface-3 h-1 w-6 rounded-full" />
            </div>
            <div className="bg-dotted grid h-12 place-items-center">
                <div className="border-border bg-surface-1 flex h-5 w-14 items-center gap-1 rounded-sm border px-1">
                    <span className="bg-success size-1 rounded-full" />
                    <span className="bg-fg-faint h-1 w-6 rounded-full" />
                </div>
            </div>
        </div>
    );
}

export default function AppearancePage() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <SettingsLayout title="Appearance" description="Choose how Falak looks on this device. Saved in a cookie so the first paint is correct.">
            <Section title="Theme" bare>
                <div role="radiogroup" aria-label="Theme" className="grid gap-3 sm:grid-cols-3">
                    {OPTIONS.map((option) => {
                        const selected = appearance === option.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                onClick={() => updateAppearance(option.value)}
                                className={cn(
                                    'bg-surface-1 grid gap-3 rounded-lg border p-3 text-left transition-colors duration-150',
                                    selected ? 'border-primary ring-primary ring-1' : 'border-border hover:border-border-strong',
                                )}
                            >
                                {option.value === 'system' ? (
                                    <div className="grid grid-cols-2 gap-1">
                                        <Preview theme="dark" />
                                        <Preview theme="light" />
                                    </div>
                                ) : (
                                    <Preview theme={option.value} />
                                )}
                                <span className="text-fg flex items-center gap-2 text-sm font-medium">
                                    <option.icon className="text-fg-muted size-4" aria-hidden />
                                    {option.label}
                                </span>
                                <span className="text-fg-faint -mt-2 text-xs">{option.description}</span>
                            </button>
                        );
                    })}
                </div>
            </Section>
        </SettingsLayout>
    );
}
