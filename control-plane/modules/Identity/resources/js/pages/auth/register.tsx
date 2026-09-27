import { Button } from '@/components/kiln/button';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import AuthLayout, { AuthLink } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout
            title="Create your account"
            description="Deploy and observe your apps on your own servers."
            footer={
                <>
                    Already have an account? <AuthLink href={route('login')}>Log in</AuthLink>
                </>
            }
        >
            <Head title="Register" />
            <form className="grid gap-4" onSubmit={submit}>
                <Field label="Name" error={errors.name}>
                    <Input
                        required
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        disabled={processing}
                    />
                </Field>
                <Field label="Email address" error={errors.email}>
                    <Input
                        type="email"
                        required
                        autoComplete="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        disabled={processing}
                        placeholder="you@company.com"
                    />
                </Field>
                <Field label="Password" error={errors.password}>
                    <Input
                        type="password"
                        required
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        disabled={processing}
                    />
                </Field>
                <Field label="Confirm password" error={errors.password_confirmation}>
                    <Input
                        type="password"
                        required
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                        disabled={processing}
                    />
                </Field>
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Create account
                </Button>
            </form>
        </AuthLayout>
    );
}
