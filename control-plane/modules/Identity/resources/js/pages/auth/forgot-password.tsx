import { Button } from '@/components/kiln/button';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import AuthLayout, { AuthLink, AuthStatus } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthLayout
            title="Forgot password"
            description="We'll email you a link to choose a new one."
            footer={
                <>
                    Remembered it? <AuthLink href={route('login')}>Log in</AuthLink>
                </>
            }
        >
            <Head title="Forgot password" />
            {status && <AuthStatus>{status}</AuthStatus>}
            <form className="grid gap-4" onSubmit={submit}>
                <Field label="Email address" error={errors.email}>
                    <Input
                        type="email"
                        autoComplete="off"
                        autoFocus
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        placeholder="you@company.com"
                    />
                </Field>
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Email password reset link
                </Button>
            </form>
        </AuthLayout>
    );
}
