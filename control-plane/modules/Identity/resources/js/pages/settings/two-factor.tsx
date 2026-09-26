import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface TwoFactorProps {
    enabled: boolean;
    pending: boolean;
    qrCodeSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[] | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-factor authentication', href: '/settings/two-factor' }];

export default function TwoFactor({ enabled, pending, qrCodeSvg, setupKey, recoveryCodes }: TwoFactorProps) {
    const [busy, setBusy] = useState(false);
    const confirmForm = useForm({ code: '' });

    const post = (url: string) => router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        confirmForm.post(route('two-factor.confirm'), { preserveScroll: true, onSuccess: () => confirmForm.reset() });
    };

    const disable = () =>
        router.delete(route('two-factor.disable'), { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor authentication" />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall
                            title="Two-factor authentication"
                            description="Require a time-based one-time code from an authenticator app when you log in."
                        />
                        {enabled ? (
                            <Badge className="gap-1">
                                <ShieldCheck className="size-3" /> Enabled
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="gap-1">
                                <ShieldOff className="size-3" /> Disabled
                            </Badge>
                        )}
                    </div>

                    {!enabled && !pending && (
                        <Button onClick={() => post(route('two-factor.enable'))} disabled={busy}>
                            Enable two-factor authentication
                        </Button>
                    )}

                    {pending && (
                        <div className="space-y-4 rounded-lg border p-4">
                            <p className="text-sm">
                                Scan this QR code with your authenticator app (1Password, Authy, Google Authenticator…), then enter the 6-digit code
                                it shows to finish enabling two-factor authentication.
                            </p>
                            {qrCodeSvg && <div className="inline-block rounded-md bg-white p-2" dangerouslySetInnerHTML={{ __html: qrCodeSvg }} />}
                            {setupKey && (
                                <p className="text-sm">
                                    Setup key: <code className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">{setupKey}</code>
                                </p>
                            )}
                            <form onSubmit={confirm} className="flex items-end gap-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="code">Code</Label>
                                    <Input
                                        id="code"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        value={confirmForm.data.code}
                                        onChange={(e) => confirmForm.setData('code', e.target.value.replace(/\D/g, ''))}
                                        className="w-32"
                                    />
                                </div>
                                <Button type="submit" disabled={confirmForm.processing || confirmForm.data.code.length !== 6}>
                                    Confirm
                                </Button>
                                <Button type="button" variant="ghost" onClick={disable} disabled={busy}>
                                    Cancel
                                </Button>
                            </form>
                            <InputError message={confirmForm.errors.code} />
                        </div>
                    )}

                    {enabled && (
                        <div className="space-y-4">
                            {recoveryCodes ? (
                                <div className="space-y-2 rounded-lg border p-4">
                                    <p className="text-sm font-medium">Recovery codes</p>
                                    <p className="text-muted-foreground text-sm">
                                        Store these in a password manager. Each code can be used once if you lose access to your authenticator.
                                    </p>
                                    <div className="bg-muted grid grid-cols-2 gap-1 rounded-md p-3 font-mono text-sm">
                                        {recoveryCodes.map((code) => (
                                            <span key={code}>{code}</span>
                                        ))}
                                    </div>
                                </div>
                            ) : null}

                            <div className="flex flex-wrap gap-2">
                                {!recoveryCodes && (
                                    <Button variant="secondary" onClick={() => post(route('two-factor.reveal'))} disabled={busy}>
                                        Show recovery codes
                                    </Button>
                                )}
                                <Button variant="secondary" onClick={() => post(route('two-factor.recovery-codes'))} disabled={busy}>
                                    Regenerate recovery codes
                                </Button>
                                <Button variant="destructive" onClick={disable} disabled={busy}>
                                    Disable two-factor authentication
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
