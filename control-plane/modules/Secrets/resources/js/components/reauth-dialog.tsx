import { Button, Dialog, Field, Input } from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { useEffect, useState, type FormEvent } from 'react';

/**
 * Step-up confirmation before a reveal: the password, plus the authenticator code when 2FA is on. On success the
 * caller retries what it was doing.
 */
export function ReauthDialog({
    open,
    requiresCode,
    onClose,
    onConfirmed,
}: {
    open: boolean;
    requiresCode: boolean;
    onClose: () => void;
    onConfirmed: () => void;
}) {
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setPassword('');
            setCode('');
            setErrors({});
        }
    }, [open]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        try {
            await requestJson('/secrets/reauthenticate', 'POST', { password, code: requiresCode ? code : null });
            onConfirmed();
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { password: errorMessage(error) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Confirm it's you"
            description="Revealing a secret needs a recent confirmation. The reveal is recorded in the secret's access log."
            size="sm"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        type="submit"
                        form="secrets-reauth"
                        loading={busy}
                        disabled={password === '' || (requiresCode && code === '')}
                    >
                        Confirm
                    </Button>
                </>
            }
        >
            <form id="secrets-reauth" onSubmit={submit} className="grid gap-4">
                <Field label="Password" error={errors.password}>
                    <Input
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        autoFocus
                    />
                </Field>
                {requiresCode && (
                    <Field label="Authentication code" hint="From your authenticator app." error={errors.code}>
                        <Input
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            mono
                            value={code}
                            onChange={(event) => setCode(event.target.value.replace(/\s+/g, ''))}
                        />
                    </Field>
                )}
            </form>
        </Dialog>
    );
}
