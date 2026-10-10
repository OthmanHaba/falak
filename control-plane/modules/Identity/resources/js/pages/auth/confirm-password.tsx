import { Button } from '@/components/falak/button';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/** Re-authentication: the password, plus the authenticator code when two-factor authentication is enabled. */
export default function ConfirmPassword({ requiresCode = false }: { requiresCode?: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '', code: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password', 'code') });
    };

    return (
        <AuthLayout
            title="Confirm it's you"
            description={
                requiresCode
                    ? 'This is a secure area. Confirm your password and an authentication code to continue.'
                    : 'This is a secure area. Confirm your password to continue.'
            }
        >
            <Head title="Confirm password" />
            <form className="grid gap-4" onSubmit={submit}>
                <Field label="Password" error={errors.password}>
                    <Input
                        type="password"
                        autoComplete="current-password"
                        autoFocus
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                    />
                </Field>
                {requiresCode && (
                    <Field label="Authentication code" hint="From your authenticator app." error={errors.code}>
                        <Input
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            mono
                            value={data.code}
                            onChange={(event) => setData('code', event.target.value.replace(/\s+/g, ''))}
                        />
                    </Field>
                )}
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Confirm
                </Button>
            </form>
        </AuthLayout>
    );
}
