import { Button } from '@/components/falak/button';
import { CodeBlock } from '@/components/falak/code-block';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Section } from '@/components/falak/section';
import { StatusBadge } from '@/components/falak/status';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

interface TwoFactorProps {
    enabled: boolean;
    pending: boolean;
    qrCodeSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[] | null;
}

export default function TwoFactor({ enabled, pending, qrCodeSvg, setupKey, recoveryCodes }: TwoFactorProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const confirmForm = useForm({ code: '' });

    const post = (name: string) =>
        router.post(route(name), {}, { preserveScroll: true, onStart: () => setBusy(name), onFinish: () => setBusy(null) });

    const disable = () =>
        router.delete(route('two-factor.disable'), { preserveScroll: true, onStart: () => setBusy('disable'), onFinish: () => setBusy(null) });

    const confirm: FormEventHandler = (event) => {
        event.preventDefault();
        confirmForm.post(route('two-factor.confirm'), { preserveScroll: true, onSuccess: () => confirmForm.reset() });
    };

    return (
        <SettingsLayout
            title="Two-factor authentication"
            description="Require a one-time code from an authenticator app when you sign in."
            actions={enabled ? <StatusBadge status="active" label="Enabled" /> : <StatusBadge status="inactive" label="Disabled" />}
        >
            {!enabled && !pending && (
                <Section title="Protect your account" description="Adds a second step to every sign-in, even if your password leaks.">
                    <div>
                        <Button variant="primary" onClick={() => post('two-factor.enable')} loading={busy === 'two-factor.enable'}>
                            Enable two-factor authentication
                        </Button>
                    </div>
                </Section>
            )}

            {pending && (
                <Section
                    title="Finish setup"
                    description="Scan the QR code with your authenticator app (1Password, Authy, Google Authenticator…), then enter the 6-digit code."
                >
                    <div className="flex flex-wrap items-start gap-6">
                        {qrCodeSvg && (
                            // QR codes need a light quiet zone to scan in both themes.
                            <div className="rounded-lg bg-[white] p-2" dangerouslySetInnerHTML={{ __html: qrCodeSvg }} />
                        )}
                        <div className="grid min-w-0 flex-1 gap-4">
                            {setupKey && <CodeBlock title="Setup key" code={setupKey} />}
                            <form onSubmit={confirm} className="flex flex-wrap items-end gap-2">
                                <Field label="Code" error={confirmForm.errors.code} className="w-36">
                                    <Input
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        mono
                                        value={confirmForm.data.code}
                                        onChange={(event) => confirmForm.setData('code', event.target.value.replace(/\D/g, ''))}
                                    />
                                </Field>
                                <Button
                                    variant="primary"
                                    type="submit"
                                    loading={confirmForm.processing}
                                    disabled={confirmForm.data.code.length !== 6}
                                >
                                    Confirm
                                </Button>
                                <Button variant="ghost" onClick={disable} disabled={busy !== null}>
                                    Cancel
                                </Button>
                            </form>
                        </div>
                    </div>
                </Section>
            )}

            {enabled && (
                <>
                    <Section
                        title="Recovery codes"
                        description="Each code works once if you lose access to your authenticator. Store them in a password manager."
                        footer={
                            <>
                                {!recoveryCodes && (
                                    <Button onClick={() => post('two-factor.reveal')} loading={busy === 'two-factor.reveal'}>
                                        Show recovery codes
                                    </Button>
                                )}
                                <Button onClick={() => post('two-factor.recovery-codes')} loading={busy === 'two-factor.recovery-codes'}>
                                    Regenerate
                                </Button>
                            </>
                        }
                    >
                        {recoveryCodes ? (
                            <CodeBlock code={recoveryCodes.join('\n')} />
                        ) : (
                            <p className="text-fg-muted text-sm">Hidden. Showing them requires confirming your password.</p>
                        )}
                    </Section>
                    <Section
                        tone="danger"
                        title="Disable two-factor authentication"
                        description="Your account will be protected by your password only."
                    >
                        <div>
                            <Button variant="danger" onClick={disable} loading={busy === 'disable'}>
                                Disable
                            </Button>
                        </div>
                    </Section>
                </>
            )}
        </SettingsLayout>
    );
}
