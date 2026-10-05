import { Button } from '@/components/falak/button';
import AuthLayout, { AuthLink, AuthStatus } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthLayout
            title="Verify your email"
            description="Click the link we just emailed you to finish setting up your account."
            footer={
                <AuthLink href={route('logout')} method="post">
                    Log out
                </AuthLink>
            }
        >
            <Head title="Email verification" />
            {status === 'verification-link-sent' && <AuthStatus>A new verification link has been sent to your email address.</AuthStatus>}
            <form onSubmit={submit}>
                <Button type="submit" className="w-full" loading={processing}>
                    Resend verification email
                </Button>
            </form>
        </AuthLayout>
    );
}
