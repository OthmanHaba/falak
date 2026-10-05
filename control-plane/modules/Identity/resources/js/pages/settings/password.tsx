import { Button } from '@/components/falak/button';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { Section } from '@/components/falak/section';
import SettingsLayout from '@/layouts/settings/layout';
import { useForm } from '@inertiajs/react';
import { useRef, type FormEventHandler } from 'react';

export default function Password() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }
                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <SettingsLayout title="Password" description="Use a long, random password from your password manager.">
            <form onSubmit={submit}>
                <Section
                    title="Change password"
                    footer={
                        <>
                            {recentlySuccessful && <span className="text-fg-muted text-xs">Saved</span>}
                            <Button variant="primary" type="submit" loading={processing}>
                                Save password
                            </Button>
                        </>
                    }
                >
                    <Field label="Current password" error={errors.current_password}>
                        <Input
                            ref={currentPasswordInput}
                            type="password"
                            autoComplete="current-password"
                            value={data.current_password}
                            onChange={(event) => setData('current_password', event.target.value)}
                        />
                    </Field>
                    <Field label="New password" error={errors.password}>
                        <Input
                            ref={passwordInput}
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    </Field>
                    <Field label="Confirm new password" error={errors.password_confirmation}>
                        <Input
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                        />
                    </Field>
                </Section>
            </form>
        </SettingsLayout>
    );
}
