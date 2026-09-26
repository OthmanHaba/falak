import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface ChallengeForm {
    code: string;
    recovery_code: string;
}

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<ChallengeForm>({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset() });
    };

    const toggle = () => {
        setUseRecovery((value) => !value);
        clearErrors();
        reset();
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={
                useRecovery
                    ? 'Enter one of your emergency recovery codes to confirm access to your account.'
                    : 'Enter the 6-digit code from your authenticator app.'
            }
        >
            <Head title="Two-factor authentication" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                {useRecovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">Recovery code</Label>
                        <Input
                            id="recovery_code"
                            autoFocus
                            autoComplete="one-time-code"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                            placeholder="abcdefghij-klmnopqrst"
                        />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Authentication code</Label>
                        <Input
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                            placeholder="123456"
                        />
                        <InputError message={errors.code} />
                    </div>
                )}

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Continue
                </Button>

                <button type="button" onClick={toggle} className="text-muted-foreground text-center text-sm underline underline-offset-4">
                    {useRecovery ? 'Use an authentication code instead' : 'Use a recovery code instead'}
                </button>
            </form>
        </AuthLayout>
    );
}
