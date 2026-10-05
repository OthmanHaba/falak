import { useEffect, useId, useState, type ReactNode } from 'react';
import { Button } from './button';
import { Dialog } from './dialog';
import { Field } from './field';
import { Input } from './input';

export interface ConfirmDestructiveProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    /** What will be destroyed and what can't be undone. */
    description?: ReactNode;
    /** The exact text the user must type (usually the resource name). */
    confirmText: string;
    confirmLabel?: string;
    /** Receives the confirmed text (some endpoints re-validate it server side). */
    onConfirm: (confirmed: string) => void | Promise<void>;
    processing?: boolean;
    /** Extra fields (e.g. a password) rendered above the confirmation input. */
    children?: ReactNode;
    /** Server-side error for the confirmation text. */
    error?: string;
}

/** §1.9: destructive actions require typing the resource name. */
export function ConfirmDestructive({
    open,
    onOpenChange,
    title,
    description,
    confirmText,
    confirmLabel = 'Delete',
    onConfirm,
    processing = false,
    children,
    error,
}: ConfirmDestructiveProps) {
    const [typed, setTyped] = useState('');
    const [running, setRunning] = useState(false);
    const formId = useId();
    const matches = typed.trim() === confirmText;

    useEffect(() => {
        if (!open) setTyped('');
    }, [open]);

    const submit = async () => {
        if (!matches) return;
        setRunning(true);
        try {
            await onConfirm(typed.trim());
        } finally {
            setRunning(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={description}
            size="sm"
            footer={
                <>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant="danger" type="submit" form={formId} disabled={!matches} loading={processing || running}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <form
                id={formId}
                className="grid gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                {children}
                <Field
                    error={error}
                    label={
                        <>
                            Type <span className="text-fg font-mono">{confirmText}</span> to confirm
                        </>
                    }
                >
                    <Input value={typed} onChange={(event) => setTyped(event.target.value)} autoComplete="off" spellCheck={false} mono autoFocus />
                </Field>
            </form>
        </Dialog>
    );
}
