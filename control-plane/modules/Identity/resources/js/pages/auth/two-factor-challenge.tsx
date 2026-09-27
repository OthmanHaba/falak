import { Button } from '@/components/kiln/button';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
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
            description={useRecovery ? 'Enter one of your emergency recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
        >
            <Head title="Two-factor authentication" />
            <form className="grid gap-4" onSubmit={submit}>
                {useRecovery ? (
                    <Field key="recovery" label="Recovery code" error={errors.recovery_code}>
                        <Input
                            autoFocus
                            autoComplete="one-time-code"
                            mono
                            value={data.recovery_code}
                            onChange={(event) => setData('recovery_code', event.target.value)}
                            placeholder="abcdefghij-klmnopqrst"
                        />
                    </Field>
                ) : (
                    <Field key="code" label="Authentication code" error={errors.code}>
                        <Input
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            mono
                            className="text-center tracking-[0.4em]"
                            value={data.code}
                            onChange={(event) => setData('code', event.target.value.replace(/\D/g, ''))}
                            placeholder="123456"
                        />
                    </Field>
                )}
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Continue
                </Button>
                <button type="button" onClick={toggle} className="text-fg-muted hover:text-fg rounded-sm text-center text-xs">
                    {useRecovery ? 'Use an authentication code instead' : 'Use a recovery code instead'}
                </button>
            </form>
        </AuthLayout>
    );
}
