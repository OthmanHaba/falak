import { AppShell } from '@/components/falak/app-shell';
import { Button } from '@/components/falak/button';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { PageHeader, Section } from '@/components/falak/section';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function CreateOrganization() {
    const { data, setData, post, processing, errors } = useForm({ name: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('organizations.store'));
    };

    return (
        <AppShell breadcrumbs={[{ title: 'New organization', href: '/organizations/create' }]}>
            <Head title="New organization" />
            <div className="mx-auto grid max-w-xl gap-6">
                <PageHeader
                    title="Create an organization"
                    description="Organizations own servers, sites and credentials. Invite teammates and give them roles once it exists."
                />
                <form onSubmit={submit}>
                    <Section
                        title="Details"
                        footer={
                            <Button variant="primary" type="submit" loading={processing}>
                                Create organization
                            </Button>
                        }
                    >
                        <Field label="Name" error={errors.name}>
                            <Input autoFocus value={data.name} onChange={(event) => setData('name', event.target.value)} placeholder="Acme Inc." />
                        </Field>
                    </Section>
                </form>
            </div>
        </AppShell>
    );
}
