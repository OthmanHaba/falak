import { Button } from '@/components/kiln/button';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout title="Confirm your password" description="This is a secure area. Confirm your password to continue.">
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
                <Button variant="primary" type="submit" className="w-full" loading={processing}>
                    Confirm password
                </Button>
            </form>
        </AuthLayout>
    );
}
