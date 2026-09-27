import { Button } from '@/components/kiln/button';
import { Dialog } from '@/components/kiln/dialog';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { Section } from '@/components/kiln/section';
import SettingsLayout from '@/layouts/settings/layout';
import { type SharedData } from '@/types';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

function DeleteAccount() {
    const [open, setOpen] = useState(false);
    const form = useForm({ password: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.delete(route('profile.destroy'), { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: () => form.reset() });
    };

    return (
        <Section
            id="danger"
            tone="danger"
            title="Delete account"
            description="Permanently delete your account and everything that belongs to it. This cannot be undone."
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-fg-muted text-sm">Organizations you own must be transferred or deleted first.</p>
                <Dialog
                    open={open}
                    onOpenChange={(value) => {
                        setOpen(value);
                        if (!value) form.clearErrors();
                    }}
                    trigger={<Button variant="danger">Delete account</Button>}
                    title="Delete your account?"
                    description="All of your data will be permanently removed. Enter your password to confirm."
                    size="sm"
                    footer={
                        <>
                            <Button variant="ghost" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button variant="danger" type="submit" form="delete-account" loading={form.processing}>
                                Delete account
                            </Button>
                        </>
                    }
                >
                    <form id="delete-account" onSubmit={submit}>
                        <Field label="Password" error={form.errors.password}>
                            <Input
                                type="password"
                                autoComplete="current-password"
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                autoFocus
                            />
                        </Field>
                    </form>
                </Dialog>
            </div>
        </Section>
    );
}

export default function Profile({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    const { auth } = usePage<SharedData>().props;
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({ name: auth.user.name, email: auth.user.email });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Profile" description="Your name and the email address you sign in with.">
            <form onSubmit={submit}>
                <Section
                    title="Profile information"
                    footer={
                        <>
                            {recentlySuccessful && <span className="text-fg-muted text-xs">Saved</span>}
                            <Button variant="primary" type="submit" loading={processing}>
                                Save
                            </Button>
                        </>
                    }
                >
                    <Field label="Name" error={errors.name}>
                        <Input value={data.name} onChange={(event) => setData('name', event.target.value)} required autoComplete="name" />
                    </Field>
                    <Field label="Email address" error={errors.email}>
                        <Input
                            type="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            required
                            autoComplete="username"
                        />
                    </Field>
                    {mustVerifyEmail && auth.user.email_verified_at === null && (
                        <p className="text-fg-muted text-sm">
                            Your email address is unverified.{' '}
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="text-primary underline-offset-4 hover:underline"
                            >
                                Re-send the verification email
                            </Link>
                            {status === 'verification-link-sent' && <span className="text-success ml-1">A new link has been sent.</span>}
                        </p>
                    )}
                </Section>
            </form>
            <DeleteAccount />
        </SettingsLayout>
    );
}
