import { Button } from '@/components/kiln/button';
import { Checkbox } from '@/components/kiln/checkbox';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import AuthLayout, { AuthLink, AuthStatus } from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '', remember: false });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout
            title="Log in to Kiln"
            description="Welcome back."
            footer={
                <>
                    Don't have an account? <AuthLink href={route('register')}>Sign up</AuthLink>
                </>
            }
        >
            <Head title="Log in" />
            {status && <AuthStatus>{status}</AuthStatus>}
            <form className="grid gap-4" onSubmit={submit}>
                <Field label="Email address" error={errors.email}>
                    <Input
                        type="email"
                        required
                        autoFocus
                        autoComplete="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        placeholder="you@company.com"
                    />
                </Field>
                <Field
                    label="Password"
                    error={errors.password}
                    aside={
                        canResetPassword && (
                            <Link href={route('password.request')} className="text-fg-muted hover:text-fg rounded-sm text-xs">
                                Forgot password?
                            </Link>
                        )
                    }
                >
                    <Input
                        type="password"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                    />
                </Field>
                <Field inline label={<span className="text-fg-muted font-normal">Remember me</span>}>
                    <Checkbox name="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                </Field>
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Log in
                </Button>
            </form>
        </AuthLayout>
    );
}
