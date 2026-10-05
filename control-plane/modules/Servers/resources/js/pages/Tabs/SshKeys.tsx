import { Button } from '@/components/falak/button';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { Link, router, useForm } from '@inertiajs/react';
import { KeyRound, Plus, Unplug } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { type SshKeyOption } from '../../types';

interface AttachedKey extends SshKeyOption {
    unix_user: string | null;
    attached_at: string | null;
}

interface Props {
    server: ServerHeader;
    sshKeys: AttachedKey[];
    availableSshKeys: SshKeyOption[];
    unixUser: string;
    can: { update: boolean; manageKeys: boolean };
}

export default function SshKeys({ server, sshKeys, availableSshKeys, unixUser, can }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ ssh_key_id: '', unix_user: unixUser });
    const active = server.status === 'active';

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('servers.ssh-keys.attach', server.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Key installed', `It is added to ${form.data.unix_user}'s authorized_keys.`);
                form.reset();
                setOpen(false);
            },
        });
    };

    const detach = (key: AttachedKey) =>
        router.delete(route('servers.ssh-keys.detach', [server.id, key.id]), {
            data: { unix_user: key.unix_user },
            preserveScroll: true,
            onSuccess: () => toast.success(`${key.name} removed from ${key.unix_user ?? 'the server'}`),
        });

    const openDialog = () => {
        form.clearErrors();
        setOpen(true);
    };

    return (
        <ServerLayout
            server={server}
            tab="ssh-keys"
            reloadOnly={['server', 'sshKeys']}
            actions={
                can.update && (
                    <Button variant="primary" icon={<Plus />} onClick={openDialog} disabled={availableSshKeys.length === 0 && !can.manageKeys}>
                        Install key
                    </Button>
                )
            }
        >
            <DataTable
                label="SSH keys on this server"
                rows={sshKeys}
                rowKey={(key) => `${key.id}-${key.unix_user}`}
                defaultSort={{ column: 'name', direction: 'asc' }}
                empty={{
                    icon: <KeyRound />,
                    title: 'No SSH keys installed',
                    description: `Install organization keys to let people SSH in as ${unixUser} or root. Keys are synced to authorized_keys by the agent.`,
                    action: can.update ? (
                        <Button variant="primary" size="sm" icon={<Plus />} onClick={openDialog}>
                            Install key
                        </Button>
                    ) : undefined,
                }}
                columns={[
                    {
                        id: 'name',
                        header: 'Key',
                        sortValue: (key) => key.name,
                        cell: (key) => (
                            <div className="grid min-w-0 py-1.5">
                                <span className="text-fg truncate font-medium">{key.name}</span>
                                <span className="text-fg-faint truncate font-mono text-xs">{key.fingerprint}</span>
                            </div>
                        ),
                    },
                    {
                        id: 'user',
                        header: 'Unix user',
                        sortValue: (key) => key.unix_user,
                        cell: (key) => (
                            <Tag mono tone={key.unix_user === 'root' ? 'warning' : 'neutral'}>
                                {key.unix_user ?? unixUser}
                            </Tag>
                        ),
                    },
                    {
                        id: 'attached',
                        header: 'Installed',
                        hideOnMobile: true,
                        align: 'right',
                        sortValue: (key) => key.attached_at,
                        cell: (key) => <RelativeTime value={key.attached_at} className="text-fg-muted text-xs" />,
                    },
                ]}
                rowActions={
                    can.update
                        ? (key) => [
                              { label: `Remove from ${key.unix_user ?? unixUser}`, icon: <Unplug />, danger: true, onSelect: () => detach(key) },
                          ]
                        : undefined
                }
            />

            {!active && sshKeys.length > 0 && (
                <p className="text-fg-faint text-xs">Keys sync to the server once it is active and its agent is online.</p>
            )}

            <Dialog
                open={open}
                onOpenChange={setOpen}
                title="Install SSH key"
                description={`Adds the key to authorized_keys on ${server.name}.`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="attach-ssh-key" loading={form.processing} disabled={!form.data.ssh_key_id}>
                            Install key
                        </Button>
                    </>
                }
            >
                {availableSshKeys.length === 0 ? (
                    <div className="grid gap-3">
                        <p className="text-fg-muted text-sm">Your organization has no SSH keys yet. Add a public key first.</p>
                        {can.manageKeys && (
                            <Button variant="secondary" asChild>
                                <Link href="/ssh-keys">Add a key</Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <form id="attach-ssh-key" onSubmit={submit} className="grid gap-4">
                        <Field
                            label="Key"
                            error={form.errors.ssh_key_id}
                            aside={
                                can.manageKeys && (
                                    <Link href="/ssh-keys" className="text-primary text-xs hover:underline">
                                        Manage keys
                                    </Link>
                                )
                            }
                        >
                            <Select
                                value={form.data.ssh_key_id || undefined}
                                onValueChange={(value) => form.setData('ssh_key_id', value)}
                                placeholder="Choose a key"
                                options={availableSshKeys.map((key) => ({ value: key.id, label: key.name, description: key.fingerprint }))}
                            />
                        </Field>
                        <Field label="Unix user" error={form.errors.unix_user} hint="root access is audited; prefer the falak user.">
                            <Select
                                value={form.data.unix_user}
                                onValueChange={(value) => form.setData('unix_user', value)}
                                options={[
                                    { value: unixUser, label: unixUser, description: 'Deploy user (recommended)' },
                                    { value: 'root', label: 'root', description: 'Full access' },
                                ]}
                            />
                        </Field>
                    </form>
                )}
            </Dialog>
        </ServerLayout>
    );
}
