import { Button } from '@/components/falak/button';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { KeyValue } from '@/components/falak/key-value';
import { Section } from '@/components/falak/section';
import { Select } from '@/components/falak/select';
import SettingsLayout from '@/layouts/settings/layout';
import { useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

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

export default function OrganizationSettings({ details: organization, can, members }: OrganizationSettingsProps) {
    const rename = useForm({ name: organization.name });
    const transfer = useForm({ user_id: '', password: '' });
    const destroy = useForm({ name: '', password: '' });
    const [deleteOpen, setDeleteOpen] = useState(false);

    const submitRename: FormEventHandler = (event) => {
        event.preventDefault();
        rename.patch(route('organization.update'), { preserveScroll: true });
    };

    const submitTransfer: FormEventHandler = (event) => {
        event.preventDefault();
        transfer.post(route('organization.transfer'), { preserveScroll: true, onSuccess: () => transfer.reset() });
    };

    const submitDelete = (confirmed: string) => {
        destroy.transform((data) => ({ ...data, name: confirmed }));
        destroy.delete(route('organization.destroy'), { preserveScroll: true, onFinish: () => destroy.reset('password') });
    };

    return (
        <SettingsLayout title="General" description="Your organization's name and ownership.">
            <form onSubmit={submitRename}>
                <Section
                    title="Organization"
                    footer={
                        can.update ? (
                            <>
                                {rename.recentlySuccessful && <span className="text-fg-muted text-xs">Saved</span>}
                                <Button variant="primary" type="submit" loading={rename.processing}>
                                    Save
                                </Button>
                            </>
                        ) : undefined
                    }
                >
                    <Field label="Name" error={rename.errors.name}>
                        <Input value={rename.data.name} disabled={!can.update} onChange={(event) => rename.setData('name', event.target.value)} />
                    </Field>
                    <KeyValue
                        columns={3}
                        items={[
                            { label: 'Slug', value: organization.slug, mono: true, copy: organization.slug },
                            { label: 'ID', value: organization.id, mono: true, copy: organization.id },
                            { label: 'Type', value: organization.personal ? 'Personal' : 'Team' },
                        ]}
                    />
                </Section>
            </form>

            {can.transfer && (
                <form onSubmit={submitTransfer}>
                    <Section
                        title="Transfer ownership"
                        description="The new owner gets full control; you become an admin."
                        footer={
                            members.length > 0 ? (
                                <Button type="submit" loading={transfer.processing} disabled={!transfer.data.user_id}>
                                    Transfer ownership
                                </Button>
                            ) : undefined
                        }
                    >
                        {members.length === 0 ? (
                            <p className="text-fg-muted text-sm">Invite another member first.</p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="New owner" error={transfer.errors.user_id}>
                                    <Select
                                        value={transfer.data.user_id || undefined}
                                        onValueChange={(value) => transfer.setData('user_id', value)}
                                        placeholder="Select a member"
                                        options={members.map((member) => ({ value: member.id, label: member.name, description: member.email }))}
                                    />
                                </Field>
                                <Field label="Your password" error={transfer.errors.password}>
                                    <Input
                                        type="password"
                                        autoComplete="current-password"
                                        value={transfer.data.password}
                                        onChange={(event) => transfer.setData('password', event.target.value)}
                                    />
                                </Field>
                            </div>
                        )}
                    </Section>
                </form>
            )}

            {can.delete && (
                <Section
                    tone="danger"
                    title="Delete organization"
                    description="Deletes the organization and everything it owns. This cannot be undone."
                >
                    <div>
                        <Button variant="danger" onClick={() => setDeleteOpen(true)}>
                            Delete organization
                        </Button>
                    </div>
                    <ConfirmDestructive
                        open={deleteOpen}
                        onOpenChange={setDeleteOpen}
                        title="Delete organization"
                        description="All servers, sites, databases and history owned by this organization will be removed."
                        confirmText={organization.name}
                        confirmLabel="Delete organization"
                        onConfirm={submitDelete}
                        processing={destroy.processing}
                        error={destroy.errors.name}
                    >
                        <Field label="Your password" error={destroy.errors.password}>
                            <Input
                                type="password"
                                autoComplete="current-password"
                                value={destroy.data.password}
                                onChange={(event) => destroy.setData('password', event.target.value)}
                            />
                        </Field>
                    </ConfirmDestructive>
                </Section>
            )}
        </SettingsLayout>
    );
}
