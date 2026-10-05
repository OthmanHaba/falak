import { Button } from '@/components/falak/button';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input, Textarea } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import InfrastructureLayout from '@/layouts/infrastructure-layout';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

interface SshKeyRow {
    id: string;
    name: string;
    fingerprint: string;
    type: string;
    servers_count: number;
    created_at: string | null;
}

interface Props {
    keys: SshKeyRow[];
    canManage: boolean;
}

const KEY_PATTERN = /^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com)\s+[A-Za-z0-9+/=]+/;

export default function SshKeys({ keys, canManage }: Props) {
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<SshKeyRow | null>(null);
    const form = useForm({ name: '', public_key: '' });
    const keyHint =
        form.data.public_key.trim() !== '' && !KEY_PATTERN.test(form.data.public_key.trim())
            ? 'This does not look like an OpenSSH public key.'
            : null;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('ssh-keys.store'), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Key ${form.data.name} added`, 'Install it on servers from their SSH keys tab.');
                form.reset();
                setOpen(false);
            },
        });
    };

    const destroy = () =>
        new Promise<void>((resolve) => {
            if (!deleting) return resolve();
            router.delete(route('ssh-keys.destroy', deleting.id), {
                preserveScroll: true,
                onSuccess: () => toast.success(`Key ${deleting.name} deleted`),
                onFinish: () => {
                    setDeleting(null);
                    resolve();
                },
            });
        });

    const openDialog = () => {
        form.clearErrors();
        setOpen(true);
    };

    return (
        <InfrastructureLayout
            section="ssh-keys"
            description="Organization keys you can install on servers' authorized_keys."
            actions={
                canManage && (
                    <Button variant="primary" icon={<Plus />} onClick={openDialog}>
                        Add key
                    </Button>
                )
            }
        >
            <DataTable
                label="SSH keys"
                rows={keys}
                rowKey={(key) => key.id}
                defaultSort={{ column: 'name', direction: 'asc' }}
                empty={{
                    icon: <KeyRound />,
                    title: 'No SSH keys yet',
                    description: 'Add your public key once, then install it on any server with a click. Removing it here revokes it everywhere.',
                    action: canManage ? (
                        <Button variant="primary" size="sm" icon={<Plus />} onClick={openDialog}>
                            Add key
                        </Button>
                    ) : undefined,
                }}
                columns={[
                    {
                        id: 'name',
                        header: 'Name',
                        sortValue: (key) => key.name,
                        cell: (key) => (
                            <div className="grid min-w-0 py-1.5">
                                <span className="text-fg truncate font-medium">{key.name}</span>
                                <span className="text-fg-faint truncate font-mono text-xs">{key.fingerprint}</span>
                            </div>
                        ),
                    },
                    { id: 'type', header: 'Type', hideOnMobile: true, sortValue: (key) => key.type, cell: (key) => <Tag mono>{key.type}</Tag> },
                    {
                        id: 'servers',
                        header: 'Servers',
                        align: 'right',
                        sortValue: (key) => key.servers_count,
                        cell: (key) => <span className={key.servers_count === 0 ? 'text-fg-faint' : 'text-fg'}>{key.servers_count}</span>,
                    },
                    {
                        id: 'created',
                        header: 'Added',
                        align: 'right',
                        hideOnMobile: true,
                        sortValue: (key) => key.created_at,
                        cell: (key) => <RelativeTime value={key.created_at} className="text-fg-muted text-xs" />,
                    },
                ]}
                rowActions={canManage ? (key) => [{ label: 'Delete…', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(key) }] : undefined}
            />

            <Dialog
                open={open}
                onOpenChange={setOpen}
                title="Add SSH key"
                description="Paste an OpenSSH public key (e.g. the contents of ~/.ssh/id_ed25519.pub)."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            type="submit"
                            form="add-ssh-key"
                            loading={form.processing}
                            disabled={!form.data.name.trim() || !form.data.public_key.trim()}
                        >
                            Add key
                        </Button>
                    </>
                }
            >
                <form id="add-ssh-key" onSubmit={submit} className="grid gap-4">
                    <Field label="Name" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="ada@laptop"
                            autoFocus
                        />
                    </Field>
                    <Field label="Public key" required error={form.errors.public_key ?? keyHint}>
                        <Textarea
                            value={form.data.public_key}
                            onChange={(event) => form.setData('public_key', event.target.value)}
                            placeholder="ssh-ed25519 AAAA… ada@laptop"
                            rows={5}
                            mono
                            spellCheck={false}
                        />
                    </Field>
                </form>
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name}?`}
                description={
                    deleting && deleting.servers_count > 0
                        ? `The key is removed from ${deleting.servers_count} server${deleting.servers_count === 1 ? '' : 's'} as well.`
                        : 'The key is removed from the organization.'
                }
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete key"
                onConfirm={destroy}
            />
        </InfrastructureLayout>
    );
}
