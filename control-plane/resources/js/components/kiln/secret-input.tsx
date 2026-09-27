import { cn } from '@/lib/utils';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from './button';
import { useFieldControl } from './field';
import { Input } from './input';

export interface SecretInputProps {
    value: string;
    onChange: (value: string) => void;
    /**
     * A value is already stored. Secrets are write-only: the stored value is never sent to the browser, so the
     * control shows a masked placeholder with a **Replace** button instead of an input.
     */
    stored?: boolean;
    /** Non-secret hint about the stored value (e.g. "…a1b2", "set 3 days ago"). */
    storedHint?: string;
    placeholder?: string;
    mono?: boolean;
    disabled?: boolean;
    id?: string;
    name?: string;
    /** Called when the user opens/cancels the replace flow (e.g. to clear a "remove" flag). */
    onReplacingChange?: (replacing: boolean) => void;
    className?: string;
}

/**
 * Write-only secret field (tokens, keys, webhook URLs). New values can be typed (with a peek toggle for what is
 * being typed); stored values are never displayed — only replaced. Cancelling a replace sends an empty value,
 * which the endpoints treat as "keep the stored secret".
 */
export function SecretInput({
    value,
    onChange,
    stored = false,
    storedHint,
    placeholder,
    mono = true,
    disabled,
    id,
    name,
    onReplacingChange,
    className,
}: SecretInputProps) {
    const [replacing, setReplacing] = useState(false);
    const [visible, setVisible] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const field = useFieldControl({ id });

    useEffect(() => {
        if (replacing) input.current?.focus();
    }, [replacing]);

    const setMode = (next: boolean) => {
        setReplacing(next);
        onReplacingChange?.(next);
        if (!next) onChange('');
    };

    if (stored && !replacing) {
        return (
            <div
                className={cn(
                    'border-border bg-surface-2 flex h-8 w-full min-w-0 items-center gap-2 rounded-md border pr-1 pl-2.5 text-sm',
                    disabled && 'opacity-60',
                    className,
                )}
                data-testid="secret-stored"
            >
                <KeyRound className="text-fg-faint size-3.5 shrink-0" aria-hidden />
                <span className="text-fg-muted font-mono text-xs tracking-widest" aria-hidden>
                    ••••••••
                </span>
                <span className="text-fg-faint min-w-0 truncate text-xs">{storedHint ?? 'Stored encrypted'}</span>
                <Button
                    id={field.id}
                    aria-describedby={field['aria-describedby']}
                    size="sm"
                    variant="ghost"
                    className="ml-auto"
                    disabled={disabled}
                    onClick={() => setMode(true)}
                >
                    Replace
                </Button>
            </div>
        );
    }

    return (
        <div className={cn('flex min-w-0 items-center gap-1.5', className)}>
            <Input
                ref={input}
                id={id}
                name={name}
                type={visible ? 'text' : 'password'}
                autoComplete="new-password"
                spellCheck={false}
                mono={mono}
                value={value}
                disabled={disabled}
                placeholder={placeholder ?? (stored ? 'New value' : undefined)}
                onChange={(event) => onChange(event.target.value)}
                suffix={
                    <button
                        type="button"
                        tabIndex={-1}
                        className="hover:text-fg rounded-sm"
                        aria-label={visible ? 'Hide value' : 'Show value'}
                        onClick={() => setVisible((current) => !current)}
                    >
                        {visible ? <EyeOff aria-hidden /> : <Eye aria-hidden />}
                    </button>
                }
            />
            {stored && (
                <Button size="sm" variant="ghost" onClick={() => setMode(false)} disabled={disabled}>
                    Cancel
                </Button>
            )}
        </div>
    );
}
