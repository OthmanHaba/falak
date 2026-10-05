import { cn } from '@/lib/utils';
import { createContext, useContext, useId, type AriaAttributes, type ReactNode } from 'react';

interface FieldContextValue {
    id: string;
    describedBy: string | undefined;
    invalid: boolean;
}

const FieldContext = createContext<FieldContextValue | null>(null);

/** Controls (Input, Textarea, Select, Combobox, Switch, Checkbox) pick up id/aria from the surrounding <Field>. */
export function useFieldControl(props: { id?: string; 'aria-describedby'?: string; 'aria-invalid'?: AriaAttributes['aria-invalid'] }) {
    const field = useContext(FieldContext);
    const invalid = props['aria-invalid'] ?? (field?.invalid || undefined);

    return {
        id: props.id ?? field?.id,
        'aria-describedby': [props['aria-describedby'], field?.describedBy].filter(Boolean).join(' ') || undefined,
        'aria-invalid': invalid,
    };
}

export interface FieldProps {
    label: ReactNode;
    /** Helper text under the control. */
    hint?: ReactNode;
    /** Validation message (e.g. Inertia `errors.name`); marks the control invalid. */
    error?: string | null;
    /** Control id; generated when omitted. */
    id?: string;
    required?: boolean;
    /** Right side of the label row (e.g. "Forgot password?"). */
    aside?: ReactNode;
    /** Render label and control side by side (switches, checkboxes). */
    inline?: boolean;
    className?: string;
    children: ReactNode;
}

export function Field({ label, hint, error, id, required, aside, inline = false, className, children }: FieldProps) {
    const generated = useId();
    const controlId = id ?? `field-${generated}`;
    const hintId = hint ? `${controlId}-hint` : undefined;
    const errorId = error ? `${controlId}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

    const labelNode = (
        <label htmlFor={controlId} className="text-fg text-xs font-medium">
            {label}
            {required && (
                <span className="text-fg-faint ml-0.5" aria-hidden>
                    *
                </span>
            )}
        </label>
    );

    return (
        <FieldContext.Provider value={{ id: controlId, describedBy, invalid: Boolean(error) }}>
            <div className={cn('grid gap-1.5', className)}>
                {inline ? (
                    <div className="flex items-center gap-2.5">
                        {children}
                        {labelNode}
                        {aside && <div className="ml-auto">{aside}</div>}
                    </div>
                ) : (
                    <>
                        <div className="flex items-center justify-between gap-2">
                            {labelNode}
                            {aside}
                        </div>
                        {children}
                    </>
                )}
                {hint && (
                    <p id={hintId} className="text-fg-faint text-xs">
                        {hint}
                    </p>
                )}
                {error && (
                    <p id={errorId} role="alert" className="text-danger text-xs">
                        {error}
                    </p>
                )}
            </div>
        </FieldContext.Provider>
    );
}
