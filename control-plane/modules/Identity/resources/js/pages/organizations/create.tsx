import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'New organization', href: '/organizations/create' }];

export default function CreateOrganization() {
    const { data, setData, post, processing, errors } = useForm({ name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('organizations.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New organization" />
            <div className="max-w-xl space-y-6 px-4 py-6">
                <HeadingSmall
                    title="Create an organization"
                    description="Organizations own servers, sites and credentials. Invite teammates and give them roles once it exists."
                />
                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" autoFocus value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Acme Inc." />
                        <InputError message={errors.name} />
                    </div>
                    <Button disabled={processing}>Create organization</Button>
                </form>
            </div>
        </AppLayout>
    );
}
