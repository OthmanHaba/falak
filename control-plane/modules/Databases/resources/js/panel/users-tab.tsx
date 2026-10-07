import {
    Button,
    ConfirmDestructive,
    DataTable,
    Field,
    Input,
    KeyValue,
    RelativeTime,
    Section,
    SkeletonRows,
    StatusBadge,
    Tag,
} from '@/components/falak';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { type DatabaseUserRow } from '../types';
import { mutate, resourceStatus, useDatabasePanel } from './api';

/** §5.4 Databases & users: the database and the users that can reach it (create, rotate, delete). */
export function DatabaseUsersTab({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDatabasePanel(ctx);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ username: '', password: '', host: '%' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState<DatabaseUserRow | null>(null);

    if (!data) return error ? <p className="text-danger text-sm">{error}</p> : <SkeletonRows rows={6} />;

    const { database, instance, can } = data;

    const addUser = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await requestJson(`/databases/instances/${instance.id}/users`, 'POST', {
                username: form.username,
                password: form.password || null,
                host: form.host || null,
                grants: [{ database_id: database.id, privileges: ['ALL PRIVILEGES'] }],
            });
            setAdding(false);
            setForm({ username: '', password: '', host: '%' });
            await reload();
        } catch (e) {
            setErrors(e instanceof HttpError ? { ...e.errors, form: Object.keys(e.errors).length ? '' : e.message } : { form: errorMessage(e) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="grid gap-8">
            <Section title="Database" bare>
                <div className="border-border bg-surface-1 rounded-lg border p-4">
                    <KeyValue
                        columns={3}
                        items={[
                            { label: 'Name', value: database.name, mono: true, copy: database.name },
                            { label: 'Charset', value: database.charset ?? 'default', mono: true },
                            { label: 'Collation', value: database.collation ?? 'default', mono: true },
                            { label: 'Status', value: <StatusBadge status={resourceStatus(database.status)} /> },
                            { label: 'Database server', value: `${instance.name} · ${instance.engine_label} ${instance.version}` },
                            { label: 'Created', value: <RelativeTime value={database.created_at} /> },
                        ]}
                    />
                </div>
            </Section>

            <Section
                title="Users"
                description="Users with privileges on this database. The first one is used for connection strings and references."
                aside={
                    can.manage &&
                    !adding && (
                        <Button size="sm" icon={<Plus />} onClick={() => setAdding(true)}>
                            New user
                        </Button>
                    )
                }
                bare
            >
                {adding && (
                    <form onSubmit={addUser} className="border-border bg-surface-1 grid gap-3 rounded-lg border p-4 sm:grid-cols-3">
                        <Field label="Username" error={errors.username}>
                            <Input value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} mono autoFocus />
                        </Field>
                        <Field label="Password" hint="Leave empty to generate one." error={errors.password}>
                            <Input
                                type="password"
                                value={form.password}
                                onChange={(event) => setForm({ ...form, password: event.target.value })}
                                autoComplete="new-password"
                            />
                        </Field>
                        <Field label="Host" hint="% allows any host." error={errors.host}>
                            <Input value={form.host} onChange={(event) => setForm({ ...form, host: event.target.value })} mono />
                        </Field>
                        {errors.form && <p className="text-danger text-xs sm:col-span-3">{errors.form}</p>}
                        <div className="flex justify-end gap-2 sm:col-span-3">
                            <Button variant="ghost" onClick={() => setAdding(false)}>
                                Cancel
                            </Button>
                            <Button variant="primary" type="submit" loading={saving} disabled={!form.username}>
                                Create user
                            </Button>
                        </div>
                    </form>
                )}
                <DataTable
                    label="Database users"
                    rows={data.users}
                    rowKey={(user) => user.id}
                    empty={{
                        icon: <KeyRound />,
                        title: 'No users yet',
                        description: 'Create a user to connect to this database from your services.',
                    }}
                    columns={[
                        {
                            id: 'username',
                            header: 'User',
                            cell: (user) => <span className="text-fg font-mono text-xs">{user.username}</span>,
                            sortValue: (user) => user.username,
                        },
                        { id: 'host', header: 'Host', hideOnMobile: true, cell: (user) => <span className="font-mono text-xs">{user.host}</span> },
                        {
                            id: 'privileges',
                            header: 'Privileges',
                            hideOnMobile: true,
                            cell: (user) => (
                                <span className="flex flex-wrap gap-1">
                                    {(user.grants.find((grant) => grant.database_id === database.id)?.privileges ?? []).map((privilege) => (
                                        <Tag key={privilege}>{privilege}</Tag>
                                    ))}
                                </span>
                            ),
                        },
                        { id: 'status', header: 'Status', cell: (user) => <StatusBadge status={resourceStatus(user.status)} /> },
                    ]}
                    rowActions={
                        can.manage
                            ? (user) => [
                                  {
                                      label: 'Rotate password',
                                      icon: <KeyRound />,
                                      onSelect: () =>
                                          void mutate(
                                              'POST',
                                              `/databases/users/${user.id}/password`,
                                              {},
                                              { success: `Rotating ${user.username}'s password`, reload },
                                          ),
                                  },
                                  { type: 'separator' },
                                  { label: 'Delete user', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(user) },
                              ]
                            : undefined
                    }
                />
            </Section>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete user ${deleting?.username ?? ''}?`}
                description="The user is dropped on the server; services connecting with it will fail."
                confirmText={deleting?.username ?? ''}
                onConfirm={async () => {
                    if (
                        deleting &&
                        (await mutate('DELETE', `/databases/users/${deleting.id}`, undefined, { success: `Deleting ${deleting.username}`, reload }))
                    )
                        setDeleting(null);
                }}
            />
        </div>
    );
}
