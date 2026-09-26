import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import OrganizationLayout from '@/layouts/organization/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Member {
    id: string;
    name: string;
    email: string;
}

interface OrganizationSettingsProps {
    details: { id: string; name: string; slug: string; personal: boolean; owner_id: string; created_at: string | null };
    can: { update: boolean; delete: boolean; transfer: boolean };
    members: Member[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Organization settings', href: '/organization/settings' }];

export default function OrganizationSettings({ details: organization, can, members }: OrganizationSettingsProps) {
    const rename = useForm({ name: organization.name });
    const transfer = useForm({ user_id: '', password: '' });
    const destroy = useForm({ name: '', password: '' });

    const submitRename: FormEventHandler = (e) => {
        e.preventDefault();
        rename.patch(route('organization.update'), { preserveScroll: true });
    };

    const submitTransfer: FormEventHandler = (e) => {
        e.preventDefault();
        transfer.post(route('organization.transfer'), { preserveScroll: true, onSuccess: () => transfer.reset() });
    };

    const submitDelete: FormEventHandler = (e) => {
        e.preventDefault();
        destroy.delete(route('organization.destroy'), { preserveScroll: true, onFinish: () => destroy.reset('password') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organization settings" />
            <OrganizationLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="General"
                        description={`Slug: ${organization.slug}${organization.personal ? ' · personal organization' : ''}`}
                    />
                    <form onSubmit={submitRename} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={rename.data.name}
                                disabled={!can.update}
                                onChange={(e) => rename.setData('name', e.target.value)}
                            />
                            <InputError message={rename.errors.name} />
                        </div>
                        {can.update && (
                            <div className="flex items-center gap-4">
                                <Button disabled={rename.processing}>Save</Button>
                                <Transition
                                    show={rename.recentlySuccessful}
                                    enter="transition"
                                    enterFrom="opacity-0"
                                    leave="transition"
                                    leaveTo="opacity-0"
                                >
                                    <p className="text-sm text-neutral-600">Saved</p>
                                </Transition>
                            </div>
                        )}
                    </form>
                </div>

                {can.transfer && (
                    <div className="space-y-6">
                        <HeadingSmall title="Transfer ownership" description="The new owner gets full control; you become an admin." />
                        {members.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Invite another member first.</p>
                        ) : (
                            <form onSubmit={submitTransfer} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label>New owner</Label>
                                    <Select value={transfer.data.user_id} onValueChange={(value) => transfer.setData('user_id', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select a member" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {members.map((member) => (
                                                <SelectItem key={member.id} value={member.id}>
                                                    {member.name} ({member.email})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={transfer.errors.user_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="transfer_password">Your password</Label>
                                    <Input
                                        id="transfer_password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={transfer.data.password}
                                        onChange={(e) => transfer.setData('password', e.target.value)}
                                    />
                                    <InputError message={transfer.errors.password} />
                                </div>
                                <Button variant="secondary" disabled={transfer.processing || !transfer.data.user_id}>
                                    Transfer ownership
                                </Button>
                            </form>
                        )}
                    </div>
                )}

                {can.delete && (
                    <div className="space-y-6">
                        <HeadingSmall
                            title="Delete organization"
                            description="Deletes the organization and everything it owns. This cannot be undone."
                        />
                        <form
                            onSubmit={submitDelete}
                            className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="confirm_name">
                                    Type <span className="font-mono">{organization.name}</span> to confirm
                                </Label>
                                <Input id="confirm_name" value={destroy.data.name} onChange={(e) => destroy.setData('name', e.target.value)} />
                                <InputError message={destroy.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="delete_password">Your password</Label>
                                <Input
                                    id="delete_password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={destroy.data.password}
                                    onChange={(e) => destroy.setData('password', e.target.value)}
                                />
                                <InputError message={destroy.errors.password} />
                            </div>
                            <Button variant="destructive" disabled={destroy.processing || destroy.data.name !== organization.name}>
                                Delete organization
                            </Button>
                        </form>
                    </div>
                )}
            </OrganizationLayout>
        </AppLayout>
    );
}
