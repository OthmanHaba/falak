import { Button } from '@/components/falak/button';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ResetPasswordProps {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ token, email, password: '', password_confirmation: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.store'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="Reset password" description="Choose a new password for your account.">
            <Head title="Reset password" />
            <form className="grid gap-4" onSubmit={submit}>
                <Field label="Email address" error={errors.email}>
                    <Input type="email" autoComplete="email" value={data.email} readOnly />
                </Field>
                <Field label="New password" error={errors.password}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        autoFocus
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                    />
                </Field>
                <Field label="Confirm password" error={errors.password_confirmation}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                    />
                </Field>
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Reset password
                </Button>
            </form>
        </AuthLayout>
    );
}
